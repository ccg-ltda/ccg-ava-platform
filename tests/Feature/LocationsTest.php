<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Sedes. They are optional and additive: a Workspace without locations books exactly as before; with locations, a booking can never put a
 * service, a professional or a time where that location does not offer it, and nothing crosses a Workspace. The dates follow BuildsBookings:
 * "now" is Monday 2026-10-12 08:00 UTC and the professionals work Monday to Friday 09:00-17:00 unless a test says otherwise.
 */
class LocationsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations'];

    private Workspace $workspace;

    private Workspace $other;

    private Service $service;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('CLINICA_A', self::MODULES);
        $this->other = $this->bookingWorkspace('CLINICA_B', self::MODULES);
        $this->service = $this->makeService($this->workspace, ['name' => 'Consulta', 'duration_minutes' => 30]);
        $this->professional = $this->makeProfessional($this->workspace, [$this->service]);
    }

    private function location(Workspace $workspace, string $name = 'Sede Norte', bool $active = true): Location
    {
        return $workspace->locations()->create(['name' => $name, 'address' => 'Calle 1', 'city' => 'Bogotá', 'is_active' => $active]);
    }

    private function asAdmin(?Workspace $workspace = null, string $role = 'admin'): void
    {
        $this->actAsMember($workspace ?? $this->workspace, $role);
    }

    private function bookPayload(array $overrides = []): array
    {
        return $overrides + ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']];
    }

    // --- the sede itself: create, read, edit, deactivate --------------------------------------------------------------------------

    public function test_staff_creates_edits_and_deactivates_a_location_without_ever_deleting_it(): void
    {
        $this->asAdmin();

        $this->post('/locations', ['name' => 'Sede Norte', 'address' => 'Calle 1 # 2-3', 'city' => 'Bogotá', 'phone' => '+57 601 234 5678', 'notes' => 'Frente al parque', 'service_ids' => [$this->service->id]])->assertSessionHasNoErrors();
        $location = $this->workspace->locations()->firstOrFail();
        $this->assertSame([$this->service->id], $location->services()->pluck('services.id')->all());
        $this->get('/locations')->assertOk()->assertInertia(fn ($page) => $page->component('Locations/Index')->has('locations', 1)->where('locations.0.name', 'Sede Norte')->where('locations.0.serviceIds', [$this->service->id]));

        $this->put("/locations/{$location->id}", ['name' => 'Sede Norte 2', 'service_ids' => [$this->service->id]])->assertSessionHasNoErrors();
        $this->assertSame('Sede Norte 2', $location->fresh()->name);
        $this->assertSame(1, $location->services()->count(), 'the services of a sede are the ones chosen (at least one)');

        $this->post("/locations/{$location->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($location->fresh()->is_active);
        $this->post("/locations/{$location->id}/activate");
        $this->assertTrue($location->fresh()->is_active);

        $this->delete("/locations/{$location->id}")->assertStatus(405);
        $this->assertSame(1, Location::count());

        $actions = AuditLog::where('resource_type', 'location')->pluck('action')->all();
        $this->assertContains('created', $actions);
        $this->assertContains('updated', $actions);
    }

    public function test_the_form_validates_names_phones_and_services(): void
    {
        $this->asAdmin();
        $this->location($this->workspace, 'Sede Norte');
        $foreign = $this->makeService($this->other, ['name' => 'Ajeno']);

        $services = ['service_ids' => [$this->service->id]];

        $this->post('/locations', ['name' => ''] + $services)->assertSessionHasErrors('name');
        $this->post('/locations', ['name' => 'Sede Norte'] + $services)->assertSessionHasErrors('name');
        $this->post('/locations', ['name' => 'Sede Sur', 'phone' => '3001112233'] + $services)->assertSessionHasErrors('phone');
        $this->post('/locations', ['name' => 'Sede Sur', 'service_ids' => [$foreign->id]])->assertSessionHasErrors('service_ids.0');
        $this->post('/locations', ['name' => str_repeat('a', 121)] + $services)->assertSessionHasErrors('name');
        $this->assertSame(1, $this->workspace->locations()->count());

        // «Servicios que ofrece» is required: none, an empty list or only inactive services are refused on the server, and nothing is created.
        $this->post('/locations', ['name' => 'Sede Sur'])->assertSessionHasErrors('service_ids');
        $this->post('/locations', ['name' => 'Sede Sur', 'service_ids' => []])->assertSessionHasErrors('service_ids');
        $inactive = $this->makeService($this->workspace, ['name' => 'Retirado', 'is_active' => false]);
        $this->post('/locations', ['name' => 'Sede Sur', 'service_ids' => [$inactive->id]])->assertSessionHasErrors('service_ids');
        $this->assertSame(1, $this->workspace->locations()->count());
        $existing = $this->workspace->locations()->firstOrFail();
        $this->put("/locations/{$existing->id}", ['name' => 'Sede Norte', 'service_ids' => []])->assertSessionHasErrors('service_ids');

        // The same name in ANOTHER Workspace is fine: names are unique per Workspace.
        $this->location($this->other, 'Sede Sur');
        $this->post('/locations', ['name' => 'Sede Sur'] + $services)->assertSessionHasNoErrors();
    }

    public function test_only_staff_who_manage_services_can_change_locations_and_the_plan_must_include_the_module(): void
    {
        $location = $this->location($this->workspace);
        $viewer = $this->member($this->workspace, 'cliente');
        $this->workspace->users()->updateExistingPivot($viewer->id, ['role' => 'supervisor']);

        // supervisor has no view-bookings: neither page nor writes
        $this->actingAs($viewer)->withSession(['workspace_id' => $this->workspace->id]);
        $this->get('/locations')->assertForbidden();
        $this->post('/locations', ['name' => 'X'])->assertForbidden();
        $this->put("/locations/{$location->id}", ['name' => 'Y'])->assertForbidden();
        $this->post("/locations/{$location->id}/deactivate")->assertForbidden();
        $this->assertTrue($location->fresh()->is_active);

        // A module that is not in the plan does not exist, even for an admin.
        $this->contractModules($this->workspace, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->asAdmin();
        $this->get('/locations')->assertForbidden();
        $this->post('/locations', ['name' => 'Z'])->assertForbidden();
    }

    public function test_a_location_of_another_workspace_is_not_found_and_never_changes(): void
    {
        $foreign = $this->location($this->other, 'Ajena');
        $this->asAdmin();

        $this->put("/locations/{$foreign->id}", ['name' => 'Robada'])->assertNotFound();
        $this->post("/locations/{$foreign->id}/deactivate")->assertNotFound();
        $this->assertSame('Ajena', $foreign->fresh()->name);
        $this->assertTrue($foreign->fresh()->is_active);
        $this->get('/locations')->assertInertia(fn ($page) => $page->has('locations', 0));
    }

    public function test_the_database_itself_refuses_links_between_workspaces(): void
    {
        $mine = $this->location($this->workspace);
        $foreignService = $this->makeService($this->other, ['name' => 'Ajeno']);
        $foreign = $this->location($this->other, 'Ajena');

        $refuses = function (callable $write) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted a link between Workspaces.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };

        $refuses(fn () => DB::table('location_service')->insert(['workspace_id' => $this->workspace->id, 'location_id' => $mine->id, 'service_id' => $foreignService->id]));
        $refuses(fn () => DB::table('schedule_rules')->insert(['workspace_id' => $this->workspace->id, 'professional_id' => $this->professional->id, 'location_id' => $foreign->id, 'weekday' => 1, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']));
        $refuses(fn () => $this->workspace->appointments()->create($this->rawAppointment(['location_id' => $foreign->id])));
    }

    /** @return array<string, mixed> */
    private function rawAppointment(array $overrides = []): array
    {
        $customer = $this->workspace->bookingCustomers()->firstOrCreate(['phone' => '573001112233'], ['name' => 'Laura']);

        return $overrides + [
            'service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'customer_id' => $customer->id, 'service_name' => 'Consulta',
            'starts_at' => '2026-10-13 10:00:00', 'ends_at' => '2026-10-13 10:30:00', 'blocks_until' => '2026-10-13 10:30:00', 'status' => 'confirmed', 'channel' => 'admin',
        ];
    }

    // --- booking with and without sedes --------------------------------------------------------------------------------------------

    public function test_a_workspace_without_locations_books_exactly_as_before(): void
    {
        $this->asAdmin();

        $this->post('/bookings', $this->bookPayload())->assertSessionHasNoErrors()->assertSessionHas('success');

        $appointment = Appointment::firstOrFail();
        $this->assertNull($appointment->location_id);
        $this->assertNull($appointment->location_name);
        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('options.locations', [])->where('options.locationRequired', false));
    }

    public function test_a_single_active_location_is_implied_and_recorded_with_its_name(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $this->location($this->workspace, 'Sede Cerrada', false);
        $this->asAdmin();

        $this->post('/bookings', $this->bookPayload())->assertSessionHasNoErrors()->assertSessionHas('success');

        $appointment = Appointment::firstOrFail();
        $this->assertSame($north->id, $appointment->location_id);
        $this->assertSame('Sede Norte', $appointment->location_name);

        // Renaming the location later never rewrites the appointment that was made there.
        $north->update(['name' => 'Sede Norte Renovada']);
        $this->assertSame('Sede Norte', $appointment->fresh()->location_name);
    }

    public function test_with_several_locations_the_booking_must_name_one_and_only_an_active_one_of_the_workspace(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $south = $this->location($this->workspace, 'Sede Sur');
        $closed = $this->location($this->workspace, 'Sede Cerrada', false);
        $foreign = $this->location($this->other, 'Ajena');
        $this->asAdmin();

        $this->post('/bookings', $this->bookPayload())->assertSessionHas('error');
        $this->post('/bookings', $this->bookPayload(['location_id' => $closed->id]))->assertSessionHas('error');
        $this->post('/bookings', $this->bookPayload(['location_id' => $foreign->id]))->assertSessionHas('error');
        $this->post('/bookings', $this->bookPayload(['location_id' => 'abc']))->assertSessionHasErrors('location_id');
        $this->post('/bookings', $this->bookPayload(['location_id' => 999999]))->assertSessionHas('error');
        $this->assertSame(0, Appointment::count(), 'a refused booking leaves nothing behind');

        $this->post('/bookings', $this->bookPayload(['location_id' => $south->id]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($south->id, Appointment::firstOrFail()->location_id);
        $this->get('/bookings')->assertInertia(fn ($page) => $page->where('options.locationRequired', true)->has('options.locations', 2));
        $this->assertNotNull($north->id);
    }

    public function test_the_availability_search_also_needs_the_location_when_there_are_several(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $this->location($this->workspace, 'Sede Sur');
        $this->asAdmin();
        $query = ['service_id' => $this->service->id, 'from' => '2026-10-13', 'days' => 1];

        $this->getJson('/bookings/availability?'.http_build_query($query))->assertStatus(422)->assertJsonPath('reason', 'invalid');
        $this->getJson('/bookings/availability?'.http_build_query($query + ['location_id' => $north->id]))->assertOk()->assertJsonPath('slots.0.startsAt', '2026-10-13T09:00:00Z');
    }

    public function test_a_service_only_offered_in_one_location_cannot_be_booked_in_another(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $south = $this->location($this->workspace, 'Sede Sur');
        $north->services()->sync([$this->service->id => ['workspace_id' => $this->workspace->id]]);
        $other = $this->makeService($this->workspace, ['name' => 'Control']);
        $this->professional->services()->attach($other->id, ['workspace_id' => $this->workspace->id]);
        $this->asAdmin();

        // Offered at north only.
        $this->post('/bookings', $this->bookPayload(['location_id' => $south->id]))->assertSessionHas('error');
        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id]))->assertSessionHas('success');
        // "Control" has no location of its own: it is offered in every location.
        $this->post('/bookings', $this->bookPayload(['service_id' => $other->id, 'location_id' => $south->id, 'starts_at' => '2026-10-13T12:00:00Z']))->assertSessionHas('success');
        $this->assertSame(2, Appointment::count());
    }

    public function test_hours_set_for_one_location_do_not_open_another_and_hours_without_location_open_all(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $south = $this->location($this->workspace, 'Sede Sur');
        // Replace the generic Monday-Friday hours: mornings only at north, afternoons only at south.
        $this->professional->scheduleRules()->delete();
        $this->professional->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'location_id' => $north->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        $this->professional->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'location_id' => $south->id, 'weekday' => 2, 'starts_at' => '14:00:00', 'ends_at' => '17:00:00']);
        $this->asAdmin();

        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id, 'starts_at' => '2026-10-13T15:00:00Z']))->assertSessionHas('error');
        $this->post('/bookings', $this->bookPayload(['location_id' => $south->id, 'starts_at' => '2026-10-13T10:00:00Z']))->assertSessionHas('error');
        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id, 'starts_at' => '2026-10-13T10:00:00Z']))->assertSessionHas('success');
        $this->post('/bookings', $this->bookPayload(['location_id' => $south->id, 'starts_at' => '2026-10-13T15:00:00Z']))->assertSessionHas('success');

        // A professional whose hours belong to another location is not even offered here.
        $lonely = $this->makeProfessional($this->workspace, [$this->service], 'Luis', [2]);
        $lonely->scheduleRules()->delete();
        $lonely->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'location_id' => $south->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $this->service->id, 'location_id' => $north->id, 'from' => '2026-10-13', 'days' => 1]))
            ->assertOk()->assertJsonMissing(['professionalName' => 'Luis']);
        $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $this->service->id, 'location_id' => $south->id, 'from' => '2026-10-13', 'days' => 1]))
            ->assertOk()->assertJsonFragment(['professionalName' => 'Luis']);
    }

    public function test_the_schedule_form_ties_intervals_to_locations_of_the_same_workspace_only(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $foreign = $this->location($this->other, 'Ajena');
        $this->asAdmin();
        $base = ['name' => 'Ana', 'service_ids' => [$this->service->id]];

        $this->put("/professionals/{$this->professional->id}", $base + ['schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', 'location_id' => $foreign->id]]])->assertSessionHasErrors('schedule.0.location_id');
        $this->put("/professionals/{$this->professional->id}", $base + ['schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', 'location_id' => $north->id], ['weekday' => 2, 'starts_at' => '14:00', 'ends_at' => '17:00']]])->assertSessionHasNoErrors();

        $rules = $this->professional->scheduleRules()->orderBy('starts_at')->get();
        $this->assertSame([$north->id, null], $rules->pluck('location_id')->all());
        $this->assertTrue(AuditLog::where('resource_type', 'professional')->get()->flatMap(fn ($log) => $log->changes)->contains(fn ($c) => $c['field'] === 'Horario semanal' && str_contains((string) $c['after'], 'Sede Norte')), 'the audit names the location of the schedule change');

        // Without the module in the plan nothing can be tied to a location.
        $this->contractModules($this->workspace, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->asAdmin();
        $this->put("/professionals/{$this->professional->id}", $base + ['schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00', 'location_id' => $north->id]]])->assertSessionHasErrors('schedule');
    }

    // --- deactivation and history --------------------------------------------------------------------------------------------------

    public function test_deactivating_a_location_keeps_its_history_and_blocks_new_bookings_and_moves(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $south = $this->location($this->workspace, 'Sede Sur');
        $this->asAdmin();
        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id]))->assertSessionHas('success');
        $appointment = Appointment::firstOrFail();

        $this->post("/locations/{$north->id}/deactivate")->assertSessionHas('warning');
        $this->assertSame(1, Appointment::count());
        $this->assertSame($north->id, $appointment->fresh()->location_id);

        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id, 'starts_at' => '2026-10-13T12:00:00Z']))->assertSessionHas('error');
        // Moving it would take a place nobody attends any more; cancelling is always possible.
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T12:00:00Z'])->assertSessionHas('error');
        $this->assertSame('2026-10-13 10:00:00', $appointment->fresh()->starts_at->toDateTimeString());
        $this->post("/bookings/{$appointment->id}/cancel", [])->assertSessionHas('success');
        $this->assertSame(Appointment::CANCELLED, $appointment->fresh()->status);
        $this->assertNotNull($south->id);
    }

    public function test_an_appointment_is_rescheduled_inside_its_own_location(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $south = $this->location($this->workspace, 'Sede Sur');
        $this->professional->scheduleRules()->delete();
        $this->professional->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'location_id' => $north->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        $this->professional->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'location_id' => $south->id, 'weekday' => 2, 'starts_at' => '14:00:00', 'ends_at' => '17:00:00']);
        $this->asAdmin();
        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id]))->assertSessionHas('success');
        $appointment = Appointment::firstOrFail();

        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T15:00:00Z'])->assertSessionHas('error');
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T11:00:00Z'])->assertSessionHas('success');

        $moved = $appointment->fresh();
        $this->assertSame('2026-10-13 11:00:00', $moved->starts_at->toDateTimeString());
        $this->assertSame($north->id, $moved->location_id, 'moving never changes the location');
        $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $this->service->id, 'from' => '2026-10-13', 'days' => 1, 'ignore_appointment' => $appointment->id]))
            ->assertOk()->assertJsonMissing(['label' => '15:00']);
    }

    public function test_appointments_made_before_locations_existed_keep_working_untouched(): void
    {
        $this->asAdmin();
        $this->post('/bookings', $this->bookPayload())->assertSessionHas('success');
        $old = Appointment::firstOrFail();

        // Locations are created afterwards: the old appointment has none and can still be moved and cancelled.
        $this->location($this->workspace, 'Sede Norte');
        $this->location($this->workspace, 'Sede Sur');
        $this->post("/bookings/{$old->id}/reschedule", ['starts_at' => '2026-10-13T11:00:00Z'])->assertSessionHas('success');
        $this->assertNull($old->fresh()->location_id);
        $this->post("/bookings/{$old->id}/cancel", [])->assertSessionHas('success');
    }

    public function test_locations_are_ignored_while_the_plan_does_not_include_the_module(): void
    {
        $this->location($this->workspace, 'Sede Norte');
        $this->location($this->workspace, 'Sede Sur');
        $this->contractModules($this->workspace, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->asAdmin();

        // Two sedes exist in the table but the plan has no Sedes: booking does not ask for one, and a named one is refused.
        $this->post('/bookings', $this->bookPayload())->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertNull(Appointment::firstOrFail()->location_id);
        $this->post('/bookings', $this->bookPayload(['location_id' => $this->workspace->locations()->first()->id, 'starts_at' => '2026-10-13T12:00:00Z']))->assertSessionHas('error');
    }

    // --- the open channels follow the same rules -----------------------------------------------------------------------------------

    public function test_the_public_page_lists_locations_requires_one_and_filters_services_and_professionals_by_it(): void
    {
        $north = $this->location($this->workspace, 'Sede Norte');
        $south = $this->location($this->workspace, 'Sede Sur');
        $north->services()->sync([$this->service->id => ['workspace_id' => $this->workspace->id]]);
        $control = $this->makeService($this->workspace, ['name' => 'Control']);
        $this->professional->services()->attach($control->id, ['workspace_id' => $this->workspace->id]);
        // "Control" is restricted to south so the filter has something to filter.
        $south->services()->sync([$control->id => ['workspace_id' => $this->workspace->id]]);
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $api = fn (string $path = '') => "/api/booking/{$key}{$path}";

        $this->getJson($api('/config'))->assertOk()->assertJsonPath('locationRequired', true)->assertJsonCount(2, 'locations')->assertJsonPath('locations.0.name', 'Sede Norte');
        $this->getJson($api('/services?location_id='.$north->id))->assertOk()->assertJsonCount(1, 'services')->assertJsonPath('services.0.name', 'Consulta');
        $this->getJson($api('/services?location_id='.$south->id))->assertOk()->assertJsonCount(1, 'services')->assertJsonPath('services.0.name', 'Control');
        $this->getJson($api('/availability?service_id='.$this->service->id.'&from=2026-10-13&days=1'))->assertStatus(422);

        $payload = ['service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']];
        $this->postJson($api('/appointments'), $payload)->assertStatus(422);
        $this->postJson($api('/appointments'), $payload + ['location_id' => $south->id])->assertStatus(409)->assertJsonPath('reason', 'slot_unavailable'); // "Consulta" is not offered at south
        $response = $this->postJson($api('/appointments'), $payload + ['location_id' => $north->id])->assertCreated()->assertJsonPath('appointment.location', 'Sede Norte');

        $this->getJson($api('/appointments/'.$response->json('token')))->assertOk()->assertJsonPath('appointment.location', 'Sede Norte');
    }

    public function test_ava_chat_lists_the_locations_and_books_in_one_with_the_same_rules(): void
    {
        $this->contractModules($this->workspace, [...self::MODULES, 'assistants', 'conversations']);
        $this->contractModules($this->other, [...self::MODULES, 'assistants', 'conversations']);
        $north = $this->location($this->workspace, 'Sede Norte');
        $this->location($this->workspace, 'Sede Sur');
        $foreign = $this->location($this->other, 'Ajena');
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $token = app(AgentAccess::class)->generate($bot);
        $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura']);
        $call = fn (string $method, string $path, array $data = []) => $this->withToken($token)->json($method, "/api/agent/booking{$path}", $data);
        $book = ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'service_id' => $this->service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '573001112233']];

        $call('GET', '/locations')->assertOk()->assertJsonPath('required', true)->assertJsonCount(2, 'locations')->assertJsonPath('locations.0.name', 'Sede Norte');
        $call('GET', '/availability?service_id='.$this->service->id.'&from=2026-10-13&days=1')->assertStatus(422);

        $call('POST', '/appointments', $book)->assertStatus(422)->assertJsonPath('reason', 'invalid');
        $call('POST', '/appointments', $book + ['location_id' => $foreign->id])->assertStatus(422);
        $call('POST', '/appointments', $book + ['location_id' => $north->id])->assertCreated()->assertJsonPath('appointment.location', 'Sede Norte')->assertJsonPath('appointment.price', 25)->assertJsonPath('confirmed', true);

        $appointment = Appointment::firstOrFail();
        $this->assertSame($north->id, $appointment->location_id);
        $this->assertSame('whatsapp', $appointment->origin_channel);

        // The same call again is the same booking, not a second one in another place.
        $call('POST', '/appointments', $book + ['location_id' => $north->id])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, Appointment::count());

        // Another Workspace's assistant sees none of this Workspace's locations.
        $otherBot = $this->other->chatbots()->create(['name' => 'Otro', 'instructions' => 'x']);
        $otherToken = app(AgentAccess::class)->generate($otherBot);
        $this->withToken($otherToken)->getJson('/api/agent/booking/locations')->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.name', 'Ajena')->assertJsonPath('required', false);
    }

    public function test_clientes_shows_where_and_for_how_much_an_appointment_was_made_from_the_same_workspace_data(): void
    {
        $this->contractModules($this->workspace, [...self::MODULES, 'customers']);
        $north = $this->location($this->workspace, 'Sede Norte');
        $this->asAdmin();
        $this->post('/bookings', $this->bookPayload(['location_id' => $north->id]))->assertSessionHas('success');
        $customer = $this->workspace->customers()->create(['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+573001112233', 'phone_normalized' => '573001112233', 'phone_e164' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        $this->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page->has('appointments', 1)->where('appointments.0.location', 'Sede Norte')->where('appointments.0.price', 25)->where('appointments.0.currency', 'DOP'));
    }

    public function test_a_location_id_of_another_workspace_sent_to_the_public_page_is_refused(): void
    {
        $this->location($this->workspace, 'Sede Norte');
        $this->location($this->workspace, 'Sede Sur');
        $foreign = $this->location($this->other, 'Ajena');
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();

        $this->postJson("/api/booking/{$key}/appointments", ['service_id' => $this->service->id, 'location_id' => $foreign->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertStatus(422);
        $this->getJson("/api/booking/{$key}/services?location_id={$foreign->id}")->assertStatus(422);
        $this->assertSame(0, Appointment::count());
    }
}
