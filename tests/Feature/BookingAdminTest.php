<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BookingCustomer;
use App\Models\Module;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** The admin side of Servicios y Reservas: catalog, professionals, schedules, rules, agenda and its actions, with permissions, plan and isolation. */
class BookingAdminTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace();
        $this->actAsMember($this->workspace);
    }

    private function appointment(array $overrides = [], string $start = '2026-10-13 10:00:00'): Appointment
    {
        $service = $this->workspace->services()->first() ?? $this->makeService($this->workspace);
        $professional = $this->workspace->professionals()->first() ?? $this->makeProfessional($this->workspace, [$service]);

        return $this->appointments()->book($this->workspace, $service, $professional->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Laura Pérez', 'phone' => '+57 300 111 2233', 'email' => 'laura@example.com'] + $overrides, 'admin')['appointment'];
    }

    // --- services ----------------------------------------------------------------------------------------------------------

    public function test_a_service_is_created_edited_deactivated_and_audited(): void
    {
        $this->post('/services', ['name' => 'Manicure', 'description' => 'Clásica', 'duration_minutes' => 45, 'buffer_minutes' => 10, 'price' => '30.50', 'category' => 'Uñas'])->assertSessionHasNoErrors();

        $service = $this->workspace->services()->firstOrFail();
        $this->assertSame([45, 10, '30.50', true], [$service->duration_minutes, $service->buffer_minutes, $service->price, $service->is_active]);

        $this->put("/services/{$service->id}", ['name' => 'Manicure gel', 'duration_minutes' => 60])->assertSessionHasNoErrors();
        $this->assertSame('Manicure gel', $service->fresh()->name);
        $this->post("/services/{$service->id}/deactivate")->assertSessionHas('success');
        $this->assertFalse($service->fresh()->is_active);
        $this->post("/services/{$service->id}/activate");
        $this->assertTrue($service->fresh()->is_active);

        $this->assertTrue(AuditLog::where('resource_type', 'service')->where('action', 'created')->exists());
        $this->assertTrue(AuditLog::where('resource_type', 'service')->where('action', 'updated')->get()->flatMap(fn ($log) => $log->changes)->contains(fn ($c) => $c['field'] === 'Nombre' && $c['after'] === 'Manicure gel'));
    }

    public function test_a_service_is_validated(): void
    {
        $this->post('/services', ['name' => '', 'duration_minutes' => 2])->assertSessionHasErrors(['name', 'duration_minutes']);
        $this->post('/services', ['name' => 'X', 'duration_minutes' => 30, 'price' => -1, 'buffer_minutes' => 999])->assertSessionHasErrors(['price', 'buffer_minutes']);

        $this->post('/services', ['name' => 'Corte', 'duration_minutes' => 30]);
        $this->post('/services', ['name' => 'Corte', 'duration_minutes' => 30])->assertSessionHasErrors('name');
    }

    public function test_services_are_never_deleted_and_there_is_no_delete_route(): void
    {
        $service = $this->makeService($this->workspace);

        $this->delete("/services/{$service->id}")->assertStatus(405);
        $this->assertNotNull($service->fresh());
    }

    // --- professionals, resources, schedules --------------------------------------------------------------------------------

    public function test_a_professional_is_created_with_services_and_a_weekly_schedule_with_a_break(): void
    {
        $service = $this->makeService($this->workspace);

        $this->post('/professionals', [
            'name' => 'Ana', 'specialty' => 'Manicurista', 'phone' => '+573001112233', 'service_ids' => [$service->id],
            'schedule' => [
                ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['weekday' => 1, 'starts_at' => '14:00', 'ends_at' => '18:00'],
                ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '16:00'],
            ],
        ])->assertSessionHasNoErrors();

        $professional = $this->workspace->professionals()->with(['services', 'scheduleRules'])->firstOrFail();
        $this->assertSame([$service->id], $professional->services->pluck('id')->all());
        $this->assertCount(3, $professional->scheduleRules);
        $this->assertSame($this->workspace->id, $professional->scheduleRules->first()->workspace_id);

        $this->put("/professionals/{$professional->id}", ['name' => 'Ana', 'service_ids' => [$service->id], 'schedule' => [['weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '12:00']]])->assertSessionHasNoErrors();
        $this->assertSame([3], $professional->fresh()->scheduleRules->pluck('weekday')->all());
        $this->assertTrue(AuditLog::where('resource_type', 'professional')->get()->flatMap(fn ($log) => $log->changes)->contains(fn ($c) => $c['field'] === 'Horario semanal'));
    }

    public function test_an_invalid_schedule_is_refused(): void
    {
        $this->post('/professionals', ['name' => 'Ana', 'schedule' => [['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '10:00']]])->assertSessionHasErrors('schedule');
        $this->post('/professionals', ['name' => 'Ana', 'schedule' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'], ['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '15:00']]])->assertSessionHasErrors('schedule');
        $this->post('/professionals', ['name' => 'Ana', 'schedule' => [['weekday' => 9, 'starts_at' => '09:00', 'ends_at' => '10:00']]])->assertSessionHasErrors('schedule.0.weekday');
        $this->assertSame(0, $this->workspace->professionals()->count());
    }

    public function test_resources_and_schedule_exceptions_are_managed(): void
    {
        $this->post('/professionals/resources', ['name' => 'Silla A', 'kind' => 'Silla'])->assertSessionHasNoErrors();
        $resource = $this->workspace->bookableResources()->firstOrFail();
        $this->put("/professionals/resources/{$resource->id}", ['name' => 'Silla 1'])->assertSessionHasNoErrors();
        $this->post("/professionals/resources/{$resource->id}/deactivate");
        $this->assertFalse($resource->fresh()->is_active);

        $this->post('/professionals/exceptions', ['date_from' => '2026-12-24', 'date_to' => '2026-12-25', 'reason' => 'Navidad'])->assertSessionHasNoErrors();
        $exception = $this->workspace->scheduleExceptions()->firstOrFail();
        $this->assertNull($exception->professional_id);
        $this->post('/professionals/exceptions', ['date_from' => '2026-12-26', 'date_to' => '2026-12-25'])->assertSessionHasErrors('date_to');
        $this->post('/professionals/exceptions', ['date_from' => '2026-12-26', 'date_to' => '2026-12-26', 'starts_at' => '10:00'])->assertSessionHasErrors('ends_at');
        $this->delete("/professionals/exceptions/{$exception->id}")->assertSessionHas('success');
        $this->assertSame(0, $this->workspace->scheduleExceptions()->count());
        $this->assertTrue(AuditLog::where('resource_type', 'schedule_exception')->where('action', 'deleted')->exists());
    }

    // --- rules ------------------------------------------------------------------------------------------------------------

    private function settingsPayload(array $overrides = []): array
    {
        return $overrides + ['provider' => 'internal', 'requires_confirmation' => true, 'min_notice_minutes' => 120, 'max_advance_days' => 30, 'cancel_notice_hours' => 4, 'slot_step_minutes' => 30, 'require_phone' => true, 'require_email' => true, 'public_enabled' => true];
    }

    public function test_the_booking_rules_are_saved_and_the_public_page_gets_a_stable_key(): void
    {
        $this->put('/bookings/settings', $this->settingsPayload())->assertSessionHasNoErrors();

        $setting = $this->workspace->bookingSetting()->firstOrFail();
        $this->assertSame([120, 30, 4, 30, true], [$setting->min_notice_minutes, $setting->max_advance_days, $setting->cancel_notice_hours, $setting->slot_step_minutes, $setting->requires_confirmation]);
        $this->assertSame(32, strlen($setting->public_key));

        $key = $setting->public_key;
        $this->put('/bookings/settings', $this->settingsPayload(['public_enabled' => false]));
        $this->put('/bookings/settings', $this->settingsPayload());
        $this->assertSame($key, $setting->fresh()->public_key);
        $this->assertTrue(AuditLog::where('resource_type', 'booking_settings')->exists());
    }

    public function test_the_rules_are_validated_and_an_external_agenda_needs_the_module_and_its_integration(): void
    {
        $this->put('/bookings/settings', $this->settingsPayload(['slot_step_minutes' => 7, 'max_advance_days' => 0]))->assertSessionHasErrors(['slot_step_minutes', 'max_advance_days']);
        $this->put('/bookings/settings', $this->settingsPayload(['provider' => 'unknown']))->assertSessionHasErrors('provider');
        $this->put('/bookings/settings', $this->settingsPayload(['provider' => 'booker']))->assertSessionHasErrors('provider'); // no module, no integration
        $this->assertNull($this->workspace->bookingSetting()->first());
    }

    // --- agenda and actions ------------------------------------------------------------------------------------------------

    public function test_the_agenda_lists_appointments_of_the_range_with_the_minimum_about_who_booked(): void
    {
        $this->appointment();

        $this->get('/bookings?from=2026-10-13&days=7')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Bookings/Index')
            ->has('appointments', 1)
            ->where('appointments.0.service', 'Manicure')
            ->where('appointments.0.status', 'confirmed')
            ->where('appointments.0.customer.name', 'Laura Pérez')
            ->where('capabilities.provider', 'internal')
            ->has('options.services', 1)
            ->has('customers.data', 1));
        $this->get('/bookings?from=2026-10-20&days=7')->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 0));
    }

    public function test_staff_create_an_appointment_for_a_customer_who_is_reused_by_phone(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);

        foreach (['2026-10-13T10:00:00Z', '2026-10-13T12:00:00Z'] as $start) {
            $this->post('/bookings', ['service_id' => $service->id, 'professional_id' => $professional->id, 'starts_at' => $start, 'customer' => ['name' => 'Laura', 'phone' => '+57 300 111 2233']])->assertSessionHasNoErrors()->assertSessionHas('success');
        }

        $this->assertSame(2, $this->workspace->appointments()->count());
        $this->assertSame(1, BookingCustomer::count());
        $this->assertSame('573001112233', BookingCustomer::first()->phone);
        $this->assertTrue(AuditLog::where('resource_type', 'appointment')->where('action', 'created')->exists());
    }

    public function test_a_taken_slot_is_an_error_toast_and_creates_nothing(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $payload = ['service_id' => $service->id, 'professional_id' => $professional->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']];

        $this->post('/bookings', $payload)->assertSessionHas('success');
        $this->post('/bookings', $payload)->assertSessionHas('error');

        $this->assertSame(1, $this->workspace->appointments()->count());
    }

    public function test_confirm_reschedule_cancel_and_the_end_of_the_life_of_an_appointment(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => Appointment::REQUESTED]);

        $this->post("/bookings/{$appointment->id}/status", ['status' => 'confirmed'])->assertSessionHas('success');
        $this->assertSame('confirmed', $appointment->fresh()->status);

        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-14T11:00:00Z'])->assertSessionHas('success');
        $fresh = $appointment->fresh();
        $this->assertSame('2026-10-14 11:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $fresh->reschedule_count);

        $this->post("/bookings/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('error'); // has not started
        $this->travelTo(CarbonImmutable::parse('2026-10-14 13:00:00', 'UTC'));
        $this->post("/bookings/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        $this->assertSame('completed', $appointment->fresh()->status);

        $other = $this->appointment(['name' => 'Otra', 'phone' => '+573009998877'], '2026-10-16 10:00:00');
        $this->post("/bookings/{$other->id}/cancel", ['reason' => 'No puede asistir'])->assertSessionHas('success');
        $this->assertSame(['cancelled', 'No puede asistir'], [$other->fresh()->status, $other->fresh()->cancel_reason]);
        $this->post("/bookings/{$other->id}/cancel")->assertSessionHas('error'); // already closed
        $this->post("/bookings/{$other->id}/reschedule", ['starts_at' => '2026-10-17T10:00:00Z'])->assertSessionHas('error');
    }

    public function test_rescheduling_to_a_taken_time_changes_nothing(): void
    {
        $first = $this->appointment();
        $second = $this->appointment(['name' => 'Otra', 'phone' => '+573009998877'], '2026-10-13 12:00:00');

        $this->post("/bookings/{$second->id}/reschedule", ['starts_at' => '2026-10-13T10:00:00Z'])->assertSessionHas('error');

        $this->assertSame('2026-10-13 12:00:00', $second->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-13 10:00:00', $first->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_an_appointment_can_be_moved_within_its_own_time(): void
    {
        $appointment = $this->appointment();

        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T10:30:00Z'])->assertSessionHas('success');

        $this->assertSame('2026-10-13 10:30:00', $appointment->fresh()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_the_free_starts_for_the_dialogs_come_from_the_availability_endpoint(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);

        $this->getJson("/bookings/availability?service_id={$service->id}&from=2026-10-13&days=1")->assertOk()
            ->assertJsonPath('slots.0.label', '09:00 AM')->assertJsonPath('slots.0.professionalName', 'Ana');
        $this->getJson('/bookings/availability')->assertStatus(422);
    }

    // --- permissions, plan and isolation ------------------------------------------------------------------------------------

    public function test_reading_operating_and_configuring_are_three_separate_permissions(): void
    {
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-dashboard', 'view-bookings', 'view-services', 'view-professionals']);
        Role::findOrCreate('recepcion', 'web')->syncPermissions(['view-dashboard', 'view-bookings', 'view-services', 'view-professionals', 'create-bookings', 'edit-bookings', 'cancel-bookings']);
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $appointment = $this->appointment();
        $book = ['service_id' => $service->id, 'professional_id' => $professional->id, 'starts_at' => '2026-10-13T14:00:00Z', 'customer' => ['name' => 'X Y', 'phone' => '+573001112233']];

        $this->actAsMember($this->workspace, 'lector');
        $this->get('/bookings')->assertOk();
        $this->get('/services')->assertOk();
        $this->get('/professionals')->assertOk();
        $this->post('/bookings', $book)->assertForbidden();
        $this->post("/bookings/{$appointment->id}/cancel")->assertForbidden();
        $this->post('/services', ['name' => 'Nuevo', 'duration_minutes' => 30])->assertForbidden();
        $this->put('/bookings/settings', $this->settingsPayload())->assertForbidden();

        $this->actAsMember($this->workspace, 'recepcion');
        $this->post('/bookings', $book)->assertSessionHas('success');
        $this->post('/services', ['name' => 'Nuevo', 'duration_minutes' => 30])->assertForbidden();
        $this->put('/bookings/settings', $this->settingsPayload())->assertForbidden();
        $this->post('/professionals', ['name' => 'Otra'])->assertForbidden();

        $this->actAsMember($this->workspace, 'cliente');
        foreach (['/bookings', '/services', '/professionals'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_each_page_needs_its_own_module_in_the_plan_and_the_dependencies_hold(): void
    {
        foreach ([['/services', 'service_catalog'], ['/professionals', 'professionals'], ['/bookings', 'bookings']] as [$url, $module]) {
            $this->contractModules($this->workspace, array_diff(['dashboard', 'service_catalog', 'professionals', 'bookings'], [$module]));
            $this->get($url)->assertForbidden();
        }

        // Without the catalog neither the professionals nor the agenda are operative: they depend on it.
        $this->contractModules($this->workspace, ['dashboard', 'professionals', 'bookings']);
        foreach (['/services', '/professionals', '/bookings'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->contractModules($this->workspace, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        foreach (['/services', '/professionals', '/bookings'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_plan_cannot_be_saved_without_the_dependencies_of_the_booking_modules(): void
    {
        $dev = Workspace::create(['organization_id' => $this->workspace->organization_id, 'code' => 'DESARROLLO_DEV', 'name' => 'Dev']);
        $this->actAsMember($dev, 'plataforma');
        $plan = $this->workspace->plan;

        $this->put("/plans/{$plan->id}/modules", ['module_ids' => [Module::where('key', 'bookings')->value('id')]])->assertSessionHasErrors('module_ids');
        $this->put("/plans/{$plan->id}/modules", ['module_ids' => Module::whereIn('key', ['service_catalog', 'professionals', 'bookings'])->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->put("/plans/{$plan->id}/modules", ['module_ids' => Module::whereIn('key', ['calendar_providers', 'bookings', 'service_catalog', 'professionals'])->pluck('id')->all()])->assertSessionHasErrors('module_ids'); // needs Integraciones
    }

    public function test_nothing_of_another_workspace_can_be_read_or_changed(): void
    {
        $other = $this->bookingWorkspace('OTRO');
        $otherService = $this->makeService($other, ['name' => 'Ajeno']);
        $otherPro = $this->makeProfessional($other, [$otherService], 'Ajena');
        $foreign = $this->appointments()->book($other, $otherService, $otherPro->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), ['name' => 'Otro', 'phone' => '+573005550000', 'email' => null], 'admin')['appointment'];
        $service = $this->makeService($this->workspace);

        $this->put("/services/{$otherService->id}", ['name' => 'Hackeado', 'duration_minutes' => 30])->assertNotFound();
        $this->post("/services/{$otherService->id}/deactivate")->assertNotFound();
        $this->put("/professionals/{$otherPro->id}", ['name' => 'Hackeada'])->assertNotFound();
        $this->post("/bookings/{$foreign->id}/cancel")->assertNotFound();
        $this->post("/bookings/{$foreign->id}/reschedule", ['starts_at' => '2026-10-14T10:00:00Z'])->assertNotFound();
        $this->post('/professionals', ['name' => 'Mía', 'service_ids' => [$otherService->id]])->assertSessionHasErrors('service_ids.0');
        $this->put("/services/{$service->id}", ['name' => 'Mío', 'duration_minutes' => 30, 'resource_ids' => [$other->bookableResources()->create(['name' => 'R'])->id]])->assertSessionHasErrors('resource_ids.0');
        $this->post('/bookings', ['service_id' => $otherService->id, 'starts_at' => '2026-10-13T12:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertSessionHas('error');

        $this->get('/bookings?from=2026-10-13&days=7')->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 0)->has('customers.data', 0)->has('options.services', 1));
        $this->assertSame('Ajeno', $otherService->fresh()->name);
        $this->assertSame('confirmed', $foreign->fresh()->status);
    }
}
