<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\Support\SimulatedCalendar;
use Tests\TestCase;

/** The open booking page and its endpoints: only what a visitor needs, validated on the server, limited against abuse and isolated by key. */
class PublicBookingTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Service $service;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        $this->workspace = $this->bookingWorkspace();
        $this->service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$this->service]);
        $this->key = $this->enablePublic($this->workspace);
    }

    private function enablePublic(Workspace $workspace, array $rules = []): string
    {
        $setting = $workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true] + $rules);

        return $setting->ensurePublicKey();
    }

    private function api(string $path = '', ?string $key = null): string
    {
        return '/api/booking/'.($key ?? $this->key).$path;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+57 300 111 2233', 'email' => 'laura@example.com'], 'notes' => 'Primera vez'];
    }

    // --- who can reach it -----------------------------------------------------------------------------------------------------

    public function test_the_page_exists_only_for_an_enabled_key_of_an_available_workspace(): void
    {
        $this->get("/book/{$this->key}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Booking/Public')->where('business', $this->workspace->name)->where('publicKey', $this->key));

        $this->get('/book/'.str_repeat('a', 32))->assertNotFound();
        $this->get('/book/short')->assertNotFound();

        $this->workspace->bookingSetting->update(['public_enabled' => false]);
        $this->get("/book/{$this->key}")->assertNotFound();
        $this->getJson($this->api('/config'))->assertNotFound();
    }

    public function test_without_the_booking_modules_in_the_plan_or_with_the_workspace_off_nothing_exists(): void
    {
        foreach (['service_catalog', 'professionals', 'bookings'] as $missing) {
            $this->contractModules($this->workspace, array_diff(['dashboard', 'service_catalog', 'professionals', 'bookings'], [$missing]));

            foreach (['/config', '/services', '/professionals?service_id=1', '/availability?service_id=1'] as $path) {
                $this->getJson($this->api($path))->assertNotFound();
            }
            $this->postJson($this->api('/appointments'), $this->payload())->assertNotFound();
            $this->get("/book/{$this->key}")->assertNotFound();
        }

        $this->contractModules($this->workspace, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->getJson($this->api('/config'))->assertOk();
        $this->workspace->update(['is_active' => false]);
        $this->getJson($this->api('/config'))->assertNotFound();
    }

    public function test_a_key_only_reaches_its_own_workspace(): void
    {
        $other = $this->bookingWorkspace('OTRO');
        $otherService = $this->makeService($other, ['name' => 'Ajeno']);
        $this->makeProfessional($other, [$otherService], 'Ajena');
        $otherKey = $this->enablePublic($other);

        $names = array_column($this->getJson($this->api('/services'))->assertOk()->json('services'), 'name');
        $this->assertSame(['Manicure'], $names);
        $this->assertSame(['Ajeno'], array_column($this->getJson($this->api('/services', $otherKey))->json('services'), 'name'));

        // The other Workspace's service id means nothing on this key.
        $this->postJson($this->api('/appointments'), $this->payload(['service_id' => $otherService->id]))->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    // --- what it shows --------------------------------------------------------------------------------------------------------------

    public function test_the_config_services_professionals_and_availability_show_only_what_a_visitor_needs(): void
    {
        $this->getJson($this->api('/config'))->assertOk()->assertExactJson([
            'business' => $this->workspace->name, 'timezone' => 'UTC', 'currency' => 'DOP', 'provider' => 'internal', 'canBook' => true,
            'requiresConfirmation' => false, 'requirePhone' => true, 'requireEmail' => false, 'cancelNoticeHours' => 2, 'maxDays' => 14,
        ]);

        $this->getJson($this->api('/services'))->assertOk()->assertJsonPath('services.0.durationMinutes', 60)->assertJsonMissingPath('services.0.workspace_id')->assertJsonMissingPath('services.0.isActive');
        $this->getJson($this->api("/professionals?service_id={$this->service->id}"))->assertOk()->assertJsonPath('professionals.0.name', 'Ana')->assertJsonMissingPath('professionals.0.phone');
        $this->getJson($this->api("/availability?service_id={$this->service->id}&from=2026-10-13&days=1"))->assertOk()->assertJsonPath('slots.0.label', '09:00 AM')->assertJsonPath('slots.0.startsAt', '2026-10-13T09:00:00Z');

        $this->service->update(['is_active' => false]);
        $this->assertSame([], $this->getJson($this->api('/services'))->json('services'));
    }

    public function test_availability_is_validated_and_never_looks_beyond_the_window(): void
    {
        $this->getJson($this->api('/availability'))->assertStatus(422);
        $this->getJson($this->api("/availability?service_id={$this->service->id}&days=99"))->assertStatus(422);
        $this->getJson($this->api("/availability?service_id={$this->service->id}&from=not-a-date"))->assertStatus(422);
        $this->assertSame([], $this->getJson($this->api("/availability?service_id={$this->service->id}&from=2027-06-01&days=3"))->json('slots')); // beyond max_advance_days
    }

    // --- booking ---------------------------------------------------------------------------------------------------------------------

    public function test_a_visitor_books_and_gets_a_link_whose_response_carries_no_contact_data(): void
    {
        $response = $this->postJson($this->api('/appointments'), $this->payload())->assertCreated();

        $this->assertSame(40, strlen($response->json('token')));
        $response->assertJsonPath('appointment.status', 'confirmed')->assertJsonPath('appointment.service', 'Manicure');
        foreach (['laura@example.com', '300 111', '573001112233', 'Laura', 'Primera vez'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($response->json()));
        }

        $appointment = Appointment::sole();
        $this->assertSame(['web', 'internal', hash('sha256', $response->json('token'))], [$appointment->channel, $appointment->provider, $appointment->token_hash]);
        $this->assertNotSame($response->json('token'), $appointment->token_hash); // the plain link is not stored
        $this->assertSame('573001112233', $appointment->customer->phone);
    }

    public function test_when_the_business_confirms_each_booking_it_is_requested_and_says_so(): void
    {
        $this->enablePublic($this->workspace, ['requires_confirmation' => true]);

        $this->postJson($this->api('/appointments'), $this->payload())->assertCreated()->assertJsonPath('appointment.status', 'requested')->assertJsonPath('appointment.statusLabel', 'Por confirmar');
    }

    public function test_a_taken_start_is_a_conflict_and_a_start_nobody_offered_is_refused(): void
    {
        $this->postJson($this->api('/appointments'), $this->payload())->assertCreated();

        $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => 'Otra', 'phone' => '3009998877']]))->assertStatus(409)->assertJsonPath('reason', 'slot_unavailable');
        $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => '2026-10-13T03:00:00Z', 'customer' => ['name' => 'Otra', 'phone' => '3009998877']]))->assertStatus(409);
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_data_of_the_visitor_is_validated_on_the_server(): void
    {
        $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => '']]))->assertStatus(422)->assertJsonValidationErrors('customer.name');
        $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => 'Laura', 'phone' => 'abc']]))->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => 'Laura', 'phone' => '3001112233', 'email' => 'no-es-correo']]))->assertStatus(422)->assertJsonValidationErrors('customer.email');
        $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => 'mañana']))->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->postJson($this->api('/appointments'), $this->payload(['notes' => str_repeat('x', 501)]))->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->postJson($this->api('/appointments'), ['service_id' => $this->service->id])->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_contact_rules_of_the_workspace_are_enforced(): void
    {
        $this->enablePublic($this->workspace, ['require_email' => true, 'require_phone' => true]);

        $response = $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => 'Laura', 'phone' => '3001112233']]))->assertStatus(422);
        $this->assertSame('invalid', $response->json('reason'));

        $this->enablePublic($this->workspace, ['require_email' => false, 'require_phone' => false]);
        $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => 'Laura']]))->assertStatus(422); // at least one way to contact
        $this->postJson($this->api('/appointments'), $this->payload(['customer' => ['name' => 'Laura', 'email' => 'laura@example.com']]))->assertCreated();
    }

    public function test_a_bot_filling_the_honeypot_is_refused_and_one_visitor_cannot_hold_unlimited_appointments(): void
    {
        $this->postJson($this->api('/appointments'), $this->payload(['website' => 'http://spam.example']))->assertStatus(422);
        $this->assertSame(0, Appointment::count());

        config(['bookings.public.write_per_minute' => 100]);
        $limit = (int) config('bookings.public.max_active_per_contact');

        foreach (range(0, $limit - 1) as $i) {
            $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC')->addHours($i)->toIso8601ZuluString()]))->assertCreated();
        }

        $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => '2026-10-14T09:00:00Z']))->assertStatus(422)->assertJsonPath('reason', 'outside_policy');
        $this->assertSame($limit, Appointment::count());
    }

    public function test_writing_is_rate_limited_per_visitor_and_key(): void
    {
        config(['bookings.public.write_per_minute' => 2]);

        foreach (range(1, 2) as $i) {
            $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => "2026-10-13T1{$i}:00:00Z"]))->assertCreated();
        }

        $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => '2026-10-13T14:00:00Z']))->assertStatus(429);
    }

    // --- managing a booking with its link --------------------------------------------------------------------------------------------

    private function book(string $start = '2026-10-15T10:00:00Z'): string
    {
        return $this->postJson($this->api('/appointments'), $this->payload(['starts_at' => $start]))->assertCreated()->json('token');
    }

    public function test_the_link_shows_cancels_and_reschedules_only_that_booking(): void
    {
        $token = $this->book();
        $other = $this->book('2026-10-16T10:00:00Z');

        $this->getJson($this->api("/appointments/{$token}"))->assertOk()->assertJsonPath('appointment.canChange', true)->assertJsonPath('appointment.canCancel', true);

        $this->postJson($this->api("/appointments/{$token}/reschedule"), ['starts_at' => '2026-10-15T12:00:00Z'])->assertOk()->assertJsonPath('appointment.startsAt', '2026-10-15T12:00:00Z');
        $this->postJson($this->api("/appointments/{$token}/reschedule"), ['starts_at' => '2026-10-16T10:00:00Z'])->assertStatus(409); // taken by the other
        $this->postJson($this->api("/appointments/{$token}/cancel"))->assertOk()->assertJsonPath('appointment.status', 'cancelled');
        $this->postJson($this->api("/appointments/{$token}/cancel"))->assertStatus(409);

        $this->assertSame('confirmed', Appointment::where('token_hash', hash('sha256', $other))->value('status'));
    }

    public function test_a_wrong_or_foreign_link_finds_nothing(): void
    {
        $token = $this->book();
        $other = $this->bookingWorkspace('OTRO');
        $otherKey = $this->enablePublic($other);

        $this->getJson($this->api('/appointments/'.str_repeat('a', 40)))->assertNotFound();
        $this->getJson($this->api('/appointments/short'))->assertNotFound();
        $this->getJson($this->api("/appointments/{$token}", $otherKey))->assertNotFound(); // the right link on another Workspace's key
        $this->postJson($this->api("/appointments/{$token}/cancel", $otherKey))->assertNotFound();
        $this->assertSame('confirmed', Appointment::sole()->status);
    }

    public function test_inside_the_notice_window_the_visitor_is_told_to_call_the_business(): void
    {
        $token = $this->book('2026-10-12T14:00:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 13:00:00', 'UTC')); // one hour before: the rule is two

        $this->getJson($this->api("/appointments/{$token}"))->assertJsonPath('appointment.canCancel', false)->assertJsonPath('appointment.canChange', false);
        $this->postJson($this->api("/appointments/{$token}/cancel"))->assertStatus(422)->assertJsonPath('reason', 'outside_policy');
        $this->postJson($this->api("/appointments/{$token}/reschedule"), ['starts_at' => '2026-10-13T10:00:00Z'])->assertStatus(422);
        $this->assertSame('confirmed', Appointment::sole()->status);
    }

    // --- audit and external agendas ------------------------------------------------------------------------------------------------------

    public function test_what_the_web_does_is_audited_as_an_automatic_event_without_personal_data(): void
    {
        $token = $this->book();
        $this->postJson($this->api("/appointments/{$token}/cancel"))->assertOk();

        $events = AuditLog::where('resource_type', 'appointment')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $events->pluck('action')->all());
        $this->assertSame(['system', 'Reserva en línea (automático)', null], [$events[0]->actor, $events[0]->user_name, $events[0]->user_id]);
        $this->assertStringNotContainsString('300', json_encode($events->toArray()));
        $this->assertStringNotContainsString('Laura', json_encode($events->toArray()));
    }

    public function test_with_an_external_agenda_that_cannot_book_the_page_says_so_and_books_nothing(): void
    {
        $this->useSimulatedAgenda(new SimulatedCalendar(['services' => true, 'professionals' => true, 'availability' => false, 'book' => false, 'reschedule' => false, 'cancel' => true, 'find' => true]));
        $this->useBookerSetting($this->workspace);

        $this->getJson($this->api('/config'))->assertJsonPath('canBook', false)->assertJsonPath('provider', 'booker');
        $this->postJson($this->api('/appointments'), $this->payload(['service_id' => 'S1']))->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->getJson($this->api('/availability?service_id=S1'))->assertStatus(422)->assertJsonPath('reason', 'unsupported');
        $this->assertSame(0, Appointment::count());
    }

    public function test_with_an_external_agenda_that_can_book_the_result_is_the_agendas_and_a_failure_is_a_gateway_error(): void
    {
        $simulated = new SimulatedCalendar;
        $this->useSimulatedAgenda($simulated);
        $this->useBookerSetting($this->workspace);

        $this->postJson($this->api('/appointments'), $this->payload(['service_id' => 'S1', 'professional_id' => 'P1']))->assertCreated()->assertJsonPath('appointment.status', 'confirmed')->assertJsonPath('token', null);

        $simulated->failWith['book'] = 'timeout';
        $response = $this->postJson($this->api('/appointments'), $this->payload(['service_id' => 'S1', 'starts_at' => '2026-10-14T10:00:00Z', 'customer' => ['name' => 'Otra', 'phone' => '3009998877']]))->assertStatus(502);
        $this->assertSame('provider', $response->json('reason'));
        $this->assertSame(['confirmed', 'failed'], Appointment::orderBy('id')->pluck('status')->all());
    }
}
