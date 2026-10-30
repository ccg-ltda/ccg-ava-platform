<?php

namespace Tests\Feature;

use App\Booking\ProviderException;
use App\Integrations\SafeHttpTarget;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\IntegrationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The whole life of a booking across every entry point on Ava's own agenda (admin, public page, Ava Chat), the hardening of what the
 * open channels accept, and the Booker connector when it cannot do what is asked: it is refused and Ava's own agenda is never used instead.
 */
class BookingLifecycleTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'integrations', 'settings', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings', 'calendar_providers'];

    private Workspace $workspace;

    private $service;

    private $professional;

    private string $key;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        $this->service = $this->makeService($this->workspace);
        $this->professional = $this->makeProfessional($this->workspace, [$this->service]);
        $this->key = $this->workspace->bookingSetting()->create(['public_enabled' => true])->ensurePublicKey();
        $this->token = app(AgentAccess::class)->generate($this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']));
    }

    private function web(string $path = ''): string
    {
        return "/api/booking/{$this->key}{$path}";
    }

    private function chat(string $method, string $path, array $data = [])
    {
        return $this->withToken($this->token)->json($method, "/api/agent/booking{$path}", $data);
    }

    private function conversation(string $contact = '573001112233')
    {
        return $this->workspace->chatbots()->first()->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => 'Laura']);
    }

    private function customer(string $phone = '+573001112233'): array
    {
        return ['name' => 'Laura Pérez', 'phone' => $phone];
    }

    // --- what the open channels accept ---------------------------------------------------------------------------------------------------

    public function test_ids_and_starts_of_the_wrong_kind_are_a_validation_error_never_a_crash_in_any_channel(): void
    {
        config(['bookings.public.write_per_minute' => 100]);
        $this->conversation();
        $bad = [
            ['service_id' => [1, 2]],
            ['service_id' => 'a b'],
            ['service_id' => $this->service->id, 'professional_id' => ['x']],
            ['service_id' => $this->service->id, 'professional_id' => 'abc def'],
            ['service_id' => $this->service->id, 'starts_at' => 'tomorrow'],
            ['service_id' => $this->service->id, 'starts_at' => '2026-10-13 10:00:00'],
            ['service_id' => $this->service->id, 'starts_at' => ['2026-10-13T10:00:00Z']],
        ];

        foreach ($bad as $override) {
            $payload = $override + ['starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer(), 'channel' => 'whatsapp', 'contact_id' => '573001112233'];

            $this->postJson($this->web('/appointments'), $payload)->assertStatus(422);
            $this->chat('POST', '/appointments', $payload)->assertStatus(422);
        }

        $this->getJson($this->web('/availability?service_id[]=1'))->assertStatus(422);
        $this->getJson($this->web('/availability?service_id=1&professional_id=%27%3B'))->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_non_numeric_id_is_not_a_service_of_the_internal_agenda(): void
    {
        $this->postJson($this->web('/appointments'), ['service_id' => 'S1', 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer()])->assertStatus(422)->assertJsonPath('reason', 'invalid');
        $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'professional_id' => 'P1', 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer()])->assertStatus(422)->assertJsonPath('reason', 'invalid');
        $this->assertSame([], $this->getJson($this->web('/availability?service_id=S1'))->assertOk()->json('slots'));
        $this->assertSame(0, Appointment::count());
    }

    public function test_only_services_somebody_can_perform_are_offered_to_customers_and_to_ava_chat(): void
    {
        $orphan = $this->makeService($this->workspace, ['name' => 'Sin profesional']);
        $off = $this->makeService($this->workspace, ['name' => 'Profesional sin horario']);
        $this->makeProfessional($this->workspace, [$off], 'Sin horario', weekdays: []);

        $this->assertSame(['Manicure'], array_column($this->getJson($this->web('/services'))->json('services'), 'name'));
        $this->assertSame(['Manicure'], array_column($this->chat('GET', '/services')->json('services'), 'name'));

        // Staff still see and manage every active service.
        $this->actAsMember($this->workspace);
        $this->get('/services')->assertInertia(fn (AssertableInertia $page) => $page->has('services', 3));
        $this->get('/bookings')->assertInertia(fn (AssertableInertia $page) => $page->has('options.services', 3));

        $this->professional->services()->attach($orphan->id, ['workspace_id' => $this->workspace->id]);
        $this->assertSame(['Manicure', 'Sin profesional'], array_column($this->getJson($this->web('/services'))->json('services'), 'name'));
    }

    // --- moving an appointment ---------------------------------------------------------------------------------------------------------------

    public function test_the_time_an_appointment_holds_counts_as_free_for_moving_it_and_only_for_it(): void
    {
        $token = $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer()])->assertCreated()->json('token');
        $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T14:00:00Z', 'customer' => $this->customer('+573009998877')])->assertCreated();
        $query = "service_id={$this->service->id}&from=2026-10-13&days=1";
        $starts = fn ($response) => array_column($response->json('slots'), 'startsAt');

        $this->assertNotContains('2026-10-13T10:30:00Z', $starts($this->getJson($this->web("/availability?{$query}"))));
        $this->assertContains('2026-10-13T10:30:00Z', $starts($this->getJson($this->web("/availability?{$query}&token={$token}"))));
        $this->assertNotContains('2026-10-13T14:30:00Z', $starts($this->getJson($this->web("/availability?{$query}&token={$token}")))); // the other booking still blocks

        $this->postJson($this->web("/appointments/{$token}/reschedule"), ['starts_at' => '2026-10-13T10:30:00Z'])->assertOk()->assertJsonPath('appointment.startsAt', '2026-10-13T10:30:00Z');
        $this->getJson($this->web("/availability?{$query}&token=".str_repeat('a', 40)))->assertNotFound();
    }

    public function test_staff_and_the_automation_can_also_move_an_appointment_into_its_own_time_but_only_their_own(): void
    {
        $this->conversation();
        $this->conversation('573009998877');
        $mine = $this->chat('POST', '/appointments', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer('573001112233')])->assertCreated()->json('appointment.id');
        $theirs = $this->chat('POST', '/appointments', ['channel' => 'whatsapp', 'contact_id' => '573009998877', 'service_id' => $this->service->id, 'starts_at' => '2026-10-14T10:00:00Z', 'customer' => $this->customer('573009998877')])->assertCreated()->json('appointment.id');
        $query = "service_id={$this->service->id}&from=2026-10-13&days=1&channel=whatsapp&contact_id=573001112233";

        $slots = fn ($response) => array_column($response->json('slots'), 'startsAt');
        $this->assertContains('2026-10-13T10:30:00Z', $slots($this->chat('GET', "/availability?{$query}&ignore_appointment={$mine}")));
        $this->chat('GET', "/availability?{$query}&ignore_appointment={$theirs}")->assertNotFound(); // not an appointment of this conversation

        $this->actAsMember($this->workspace);
        $this->getJson("/bookings/availability?service_id={$this->service->id}&from=2026-10-13&days=1&ignore_appointment={$mine}")->assertOk()->assertJsonFragment(['startsAt' => '2026-10-13T10:30:00Z']);
        $this->getJson("/bookings/availability?service_id={$this->service->id}&from=2026-10-13&days=1")->assertOk()->assertJsonMissing(['startsAt' => '2026-10-13T10:30:00Z']);
        $other = $this->bookingWorkspace('OTRO');
        $foreign = $this->appointments()->book($other, $this->makeService($other), $this->makeProfessional($other, [$other->services()->first()])->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), $this->customer('+573005550000') + ['email' => null], 'admin')['appointment'];
        $this->getJson("/bookings/availability?service_id={$this->service->id}&ignore_appointment={$foreign->id}")->assertNotFound();
    }

    // --- one booking, every channel ------------------------------------------------------------------------------------------------------------

    public function test_a_booking_lives_through_web_chat_and_staff_without_ever_overlapping(): void
    {
        $this->workspace->bookingSetting->update(['requires_confirmation' => true]);
        $this->conversation('573001112233');

        // The web books 10:00 and it waits for the business.
        $link = $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer()])->assertCreated()->assertJsonPath('appointment.status', 'requested')->json('token');
        // Ava Chat asks for the same time: it is taken, and it is told so.
        $this->chat('POST', '/appointments', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer('573001112233')])->assertStatus(409)->assertJsonPath('reason', 'slot_unavailable');
        // Ava Chat takes the next one and also waits.
        $chat = $this->chat('POST', '/appointments', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T11:00:00Z', 'customer' => $this->customer('573001112233')])->assertCreated()->assertJsonPath('confirmed', false)->json('appointment.id');

        $this->actAsMember($this->workspace);
        $this->get('/bookings?from=2026-10-13&days=1')->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 2)->where('appointments.0.channel', 'web')->where('appointments.1.channel', 'chat')->where('appointments.0.status', 'requested'));

        // The staff confirm one, reject the other, and the customer of the first moves it.
        $web = Appointment::where('token_hash', hash('sha256', $link))->firstOrFail();
        $this->post("/bookings/{$web->id}/status", ['status' => 'confirmed'])->assertSessionHas('success');
        $this->post("/bookings/{$chat}/cancel", ['reason' => 'No hay cupo'])->assertSessionHas('success');
        $this->postJson($this->web("/appointments/{$link}/reschedule"), ['starts_at' => '2026-10-14T09:00:00Z'])->assertOk()->assertJsonPath('appointment.status', 'requested'); // the business confirms moves too

        $this->assertSame(['confirmed', 'No hay cupo'], [Appointment::find($chat)->status === 'cancelled' ? 'confirmed' : 'x', Appointment::find($chat)->cancel_reason]);
        $this->assertSame('requested', $web->fresh()->status);
        $this->get('/bookings?from=2026-10-13&days=1&status=cancelled')->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 1)->where('appointments.0.cancelReason', 'No hay cupo'));

        // The freed 11:00 is bookable again, and a closed day later the staff can close what happened.
        $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T11:00:00Z', 'customer' => $this->customer('+573007776655')])->assertCreated();
        $this->post("/bookings/{$web->id}/status", ['status' => 'confirmed'])->assertSessionHas('success');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->renewAbsoluteSession();
        $this->post("/bookings/{$web->id}/status", ['status' => 'no_show'])->assertSessionHas('success');
        $this->assertSame('no_show', $web->fresh()->status);

        // Who did what is in the audit trail: people as people, the web and the chat as automatic processes.
        $sources = AuditLog::where('resource_type', 'appointment')->get()->groupBy('actor')->map->pluck('user_name')->map->unique()->map->values();
        $this->assertContains('Reserva en línea (automático)', $sources['system']->all());
        $this->assertContains('Asistente (automático)', $sources['system']->all());
        $this->assertNotEmpty($sources['user']);
    }

    // --- warnings and audit of configuration -----------------------------------------------------------------------------------------------------

    public function test_switching_off_a_service_or_a_professional_with_upcoming_appointments_warns_and_keeps_them(): void
    {
        $this->actAsMember($this->workspace);
        $appointment = $this->appointments()->book($this->workspace, $this->service, $this->professional->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), $this->customer() + ['email' => null], 'admin')['appointment'];

        $this->post("/services/{$this->service->id}/deactivate")->assertSessionHas('warning', fn ($message) => str_contains($message, '1 cita'));
        $this->post("/professionals/{$this->professional->id}/deactivate")->assertSessionHas('warning', fn ($message) => str_contains($message, '1 cita'));
        $this->get('/services')->assertInertia(fn (AssertableInertia $page) => $page->where('services.0.upcomingCount', 1));

        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->post("/services/{$this->service->id}/activate")->assertSessionMissing('warning');
        $this->assertSame([], $this->getJson($this->web('/availability?service_id='.$this->service->id))->json('slots')); // the professional is still off
    }

    public function test_an_exception_over_existing_appointments_warns_how_many_and_only_the_ones_it_touches(): void
    {
        $this->actAsMember($this->workspace);
        $this->appointments()->book($this->workspace, $this->service, $this->professional->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), $this->customer() + ['email' => null], 'admin');
        $this->appointments()->book($this->workspace, $this->service, $this->professional->id, CarbonImmutable::parse('2026-10-13 15:00:00', 'UTC'), $this->customer('+573009998877') + ['email' => null], 'admin');

        $this->post('/professionals/exceptions', ['date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'starts_at' => '14:00', 'ends_at' => '16:00'])->assertSessionHas('warning', fn ($message) => str_contains($message, '1 cita'));
        $this->post('/professionals/exceptions', ['date_from' => '2026-10-13', 'date_to' => '2026-10-14', 'reason' => 'Festivo'])->assertSessionHas('warning', fn ($message) => str_contains($message, '2 cita'));
        $this->post('/professionals/exceptions', ['date_from' => '2026-10-20', 'date_to' => '2026-10-20'])->assertSessionMissing('warning');
        $this->assertSame(2, Appointment::where('status', 'confirmed')->count()); // nobody is cancelled by a setting
    }

    public function test_the_services_of_a_professional_and_the_resources_of_a_service_are_audited(): void
    {
        $this->actAsMember($this->workspace);
        $other = $this->makeService($this->workspace, ['name' => 'Pedicure']);
        $chair = $this->workspace->bookableResources()->create(['name' => 'Silla A']);

        $this->put("/professionals/{$this->professional->id}", ['name' => 'Ana', 'service_ids' => [$this->service->id, $other->id]])->assertSessionHasNoErrors();
        $this->put("/services/{$this->service->id}", ['name' => 'Manicure', 'duration_minutes' => 60, 'resource_ids' => [$chair->id]])->assertSessionHasNoErrors();

        $fields = AuditLog::all()->flatMap(fn ($log) => $log->changes)->keyBy('field');
        $this->assertSame('Manicure, Pedicure', $fields['Servicios que presta']['after']);
        $this->assertSame('Silla A', $fields['Recursos que necesita']['after']);
    }

    public function test_a_cancellation_with_a_reason_keeps_it_and_the_customers_note_is_shown_to_staff(): void
    {
        $this->actAsMember($this->workspace);
        $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer(), 'notes' => 'Alérgica al látex'])->assertCreated();
        $id = Appointment::sole()->id;

        $this->get('/bookings?from=2026-10-13&days=1')->assertInertia(fn (AssertableInertia $page) => $page->where('appointments.0.notes', 'Alérgica al látex'));
        $this->post("/bookings/{$id}/cancel", ['reason' => str_repeat('x', 256)])->assertSessionHasErrors('reason');
        $this->post("/bookings/{$id}/cancel", ['reason' => 'Cierre por obras'])->assertSessionHas('success');
        $this->assertSame('Cierre por obras', Appointment::sole()->cancel_reason);
    }

    // --- the public link --------------------------------------------------------------------------------------------------------------------------------

    public function test_regenerating_the_public_link_kills_the_old_one_only_with_the_permission_and_is_audited_without_the_key(): void
    {
        $old = $this->key;
        $token = $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer()])->assertCreated()->json('token');

        $this->actAsMember($this->workspace, 'supervisor');
        $this->post('/bookings/settings/public-key')->assertForbidden();

        $this->actAsMember($this->workspace);
        $this->post('/bookings/settings/public-key')->assertSessionHas('success');
        $new = $this->workspace->bookingSetting->fresh()->public_key;

        $this->assertNotSame($old, $new);
        $this->getJson("/api/booking/{$old}/config")->assertNotFound();
        $this->get("/book/{$old}")->assertNotFound();
        $this->getJson("/api/booking/{$new}/config")->assertOk();
        $this->getJson("/api/booking/{$old}/appointments/{$token}")->assertNotFound();
        $this->getJson("/api/booking/{$new}/appointments/{$token}")->assertOk(); // the customer's own link is not the page's key
        $this->assertStringNotContainsString($new, json_encode(AuditLog::all()->toArray()));
        $this->assertTrue(AuditLog::where('resource_type', 'booking_settings')->get()->flatMap(fn ($log) => $log->changes)->contains(fn ($change) => $change['field'] === 'Enlace de reservas'));
    }

    // --- Booker: what it cannot do is refused and Ava's own agenda is never used instead --------------------------------------------------------------------

    private function chooseBooker(): void
    {
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        app(IntegrationService::class)->saveProvider($this->workspace, 'booker', ['environment' => 'staging', 'location_id' => '777', 'client_id' => 'CID', 'client_secret' => 'CSEC', 'subscription_key' => 'SUB']);
        $this->workspace->bookingSetting->update(['provider' => 'booker']);
        Http::fake(['*/v5/auth/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]), '*/v4.1/customer/treatments' => Http::response(['Results' => [['ID' => 1, 'Name' => 'Servicio Booker', 'TotalDuration' => 30, 'IsActive' => true, 'IsClass' => false, 'AllowCustomersToBookOnline' => true]]]), '*' => Http::response([], 500)]);
    }

    public function test_with_booker_chosen_every_booking_operation_is_refused_explicitly_and_nothing_touches_the_internal_agenda(): void
    {
        $this->chooseBooker();
        $this->conversation();
        $payload = ['service_id' => '1', 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => $this->customer(), 'channel' => 'whatsapp', 'contact_id' => '573001112233'];

        $this->getJson($this->web('/config'))->assertOk()->assertJsonPath('provider', 'booker')->assertJsonPath('canBook', false);
        $this->getJson($this->web('/availability?service_id=1'))->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->postJson($this->web('/appointments'), $payload)->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->chat('GET', '/availability?service_id=1')->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->chat('POST', '/appointments', $payload)->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->assertStringContainsString('Booker', $this->postJson($this->web('/appointments'), $payload)->json('message'));

        // The internal service ("Manicure", numeric id) is neither offered nor bookable while Booker is the agenda.
        $this->assertSame(['Servicio Booker'], array_column($this->getJson($this->web('/services'))->json('services'), 'name'));
        $this->postJson($this->web('/appointments'), ['service_id' => $this->service->id] + $payload)->assertStatus(422);
        $this->assertSame(0, Appointment::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/appointment/create'));
    }

    public function test_staff_cannot_create_or_move_with_booker_and_the_dialogs_say_why(): void
    {
        $this->chooseBooker();
        $this->actAsMember($this->workspace);
        $mine = $this->appointments()->book($this->workspace, $this->service, $this->professional->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), $this->customer() + ['email' => null], 'admin')['appointment'];

        $this->getJson('/bookings/availability?service_id=1')->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->post('/bookings', ['service_id' => '1', 'starts_at' => '2026-10-13T12:00:00Z', 'customer' => $this->customer()])->assertSessionHas('error');
        $this->get('/bookings')->assertInertia(fn (AssertableInertia $page) => $page->where('capabilities.book', false)->where('capabilities.availability', false)->where('capabilities.cancel', true));

        // An old internal appointment is still Ava's: moving the agenda to Booker did not orphan it, and cancelling it needs no provider.
        $this->post("/bookings/{$mine->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $mine->fresh()->status);
    }

    public function test_when_the_plan_loses_the_provider_module_the_chosen_agenda_is_unavailable_not_replaced(): void
    {
        $this->chooseBooker();
        $this->contractModules($this->workspace, array_diff(self::MODULES, ['calendar_providers']));
        $this->actAsMember($this->workspace);

        $this->getJson($this->web('/config'))->assertOk()->assertJsonPath('canBook', false);
        $this->getJson($this->web('/services'))->assertStatus(502)->assertJsonPath('reason', 'provider');
        $this->get('/services')->assertInertia(fn (AssertableInertia $page) => $page->where('capabilities.connected', false)->where('external', [])->has('services', 0));
        $this->assertSame(0, Appointment::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'booker.com')); // it did not even try: the module is not in the plan
    }

    public function test_an_unsupported_provider_error_code_is_a_fixed_message(): void
    {
        $this->assertSame('La agenda externa conectada no ofrece esta operación en Ava.', ProviderException::unsupported()->getMessage());
    }
}
