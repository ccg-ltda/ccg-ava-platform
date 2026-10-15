<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingCustomer;
use App\Models\Module;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Appointments for a small clinic, end to end through the real routes and with no clinical data: the plan is built and assigned by the
 * platform, the clinic configures services, professionals, schedules and rules, books, and Clientes (optional) shows the bookings of the
 * same person. A second clinic on the same plan, and a Workspace whose plan lacks modules, prove that sharing code never shares data or access.
 */
class HealthAppointmentsFlowTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $clinicA;

    private Workspace $clinicB;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', []);
        $this->dev->update(['plan_id' => null]);
        $this->clinicA = $this->bookingWorkspace('CLINICA_A', []);
        $this->clinicB = $this->bookingWorkspace('CLINICA_B', []);
        $this->clinicA->update(['plan_id' => null]);
        $this->clinicB->update(['plan_id' => null]);

        // The commercial decision is made by the platform through the real screens: nothing is enabled by the category alone.
        $platform = $this->member($this->dev, 'plataforma');
        $this->actingAs($platform)->withSession(['workspace_id' => $this->dev->id]);
        $ids = Module::whereIn('key', ['service_catalog', 'professionals', 'bookings', 'customers'])->pluck('id')->all();
        $this->post('/plans', ['name' => 'Citas de salud', 'description' => 'Servicios, profesionales, agenda y clientes', 'module_ids' => $ids])->assertSessionHasNoErrors();
        $this->plan = Plan::where('name', 'Citas de salud')->firstOrFail();
        $this->put("/workspaces/{$this->clinicA->id}/plan", ['plan_id' => $this->plan->id])->assertSessionHasNoErrors();
        $this->put("/workspaces/{$this->clinicB->id}/plan", ['plan_id' => $this->plan->id])->assertSessionHasNoErrors();
    }

    private function as(Workspace $workspace, string $role = 'admin'): User
    {
        $user = $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    /** Configures a clinic like its administrator would and returns [service id, professional id]. */
    private function configure(Workspace $workspace): array
    {
        $this->post('/services', ['name' => 'Consulta general', 'duration_minutes' => 30, 'buffer_minutes' => 0])->assertSessionHasNoErrors();
        $service = $workspace->services()->firstOrFail();
        $this->post('/professionals', [
            'name' => 'Dra. Ruiz', 'specialty' => 'Medicina general', 'service_ids' => [$service->id],
            'schedule' => [['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '12:00']],
        ])->assertSessionHasNoErrors();
        $professional = $workspace->professionals()->firstOrFail();
        $this->put('/bookings/settings', ['provider' => 'internal', 'requires_confirmation' => false, 'min_notice_minutes' => 0, 'max_advance_days' => 30, 'cancel_notice_hours' => 4, 'slot_step_minutes' => 30, 'require_phone' => true, 'require_email' => false, 'public_enabled' => false])->assertSessionHasNoErrors();

        return [$service->id, $professional->id];
    }

    public function test_the_platform_plan_gives_the_clinic_exactly_these_modules_and_the_category_alone_gives_nothing(): void
    {
        $this->assertEqualsCanonicalizing(['service_catalog', 'professionals', 'bookings', 'customers'], app(ModuleAccess::class)->keys($this->clinicA->fresh()));

        $this->as($this->clinicA);
        foreach (['/services', '/professionals', '/bookings', '/customers'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/campaigns', '/conversations', '/chatbots'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/plans')->assertForbidden();
    }

    public function test_a_clinic_configures_its_agenda_books_and_sees_the_booking_in_clientes(): void
    {
        $this->as($this->clinicA);
        [$serviceId, $professionalId] = $this->configure($this->clinicA);

        // 2026-10-13 is a Tuesday: the only working day of the professional.
        $slots = $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $serviceId, 'professional_id' => $professionalId, 'from' => '2026-10-13', 'days' => 1]))->assertOk()->json('slots');
        $this->assertCount(6, $slots, '09:00-12:00 every 30 minutes with a 30 minute service');
        $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $serviceId, 'from' => '2026-10-14', 'days' => 1]))->assertOk()->assertJsonPath('slots', []);

        $this->post('/bookings', ['service_id' => $serviceId, 'professional_id' => $professionalId, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+57 300 111 2233']])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post('/bookings', ['service_id' => $serviceId, 'professional_id' => $professionalId, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Otra', 'phone' => '+573009998888']])->assertSessionHas('error');
        $this->post('/bookings', ['service_id' => $serviceId, 'professional_id' => $professionalId, 'starts_at' => '2026-10-13T13:00:00Z', 'customer' => ['name' => 'Fuera', 'phone' => '+573009998888']])->assertSessionHas('error');

        $appointment = Appointment::where('workspace_id', $this->clinicA->id)->firstOrFail();
        $this->assertSame(1, Appointment::count());
        $this->assertSame($this->clinicA->id, $appointment->customer->workspace_id);

        // The optional Clientes module is linked by the same normalized phone, nothing is copied.
        $customer = $this->clinicA->customers()->create(['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '573001112233', 'phone_normalized' => '573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);
        $this->get("/customers/{$customer->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 1)->where('appointments.0.id', $appointment->id));
        $this->assertSame(1, BookingCustomer::count(), 'the booking kept its own minimal contact; no patient record was created');
    }

    public function test_a_second_clinic_on_the_same_plan_has_its_own_everything(): void
    {
        $this->as($this->clinicA);
        [$serviceA, $professionalA] = $this->configure($this->clinicA);
        $this->post('/bookings', ['service_id' => $serviceA, 'professional_id' => $professionalA, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']])->assertSessionHasNoErrors();
        $appointmentA = Appointment::firstOrFail();
        $customerB = $this->clinicB->customers()->create(['first_name' => 'Laura', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        $this->as($this->clinicB);
        $this->get('/bookings?from=2026-10-13&days=7')->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 0)->has('customers.data', 0)->has('options.services', 0)->has('options.professionals', 0)
            ->where('settings.values.cancel_notice_hours', 2)->where('settings.values.slot_step_minutes', 15));
        $this->get("/customers/{$customerB->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 0));
        $this->post('/bookings', ['service_id' => $serviceA, 'professional_id' => $professionalA, 'starts_at' => '2026-10-13T11:00:00Z', 'customer' => ['name' => 'Intruso', 'phone' => '+573005550000']])->assertSessionHas('error');
        $this->post("/bookings/{$appointmentA->id}/cancel")->assertNotFound();
        $this->put("/professionals/{$professionalA}", ['name' => 'Hackeada'])->assertNotFound();
        $this->post('/professionals', ['name' => 'Dr. B', 'service_ids' => [$serviceA]])->assertSessionHasErrors('service_ids.0');

        $this->assertSame(0, Appointment::where('workspace_id', $this->clinicB->id)->count());
        $this->assertSame('confirmed', $appointmentA->fresh()->status);
        $this->assertSame(4, $this->clinicA->fresh()->bookingSetting->cancel_notice_hours, 'the rules of clinic A are untouched');
    }

    public function test_permissions_and_the_plan_are_separate_checks(): void
    {
        // A role without the booking permissions is refused although the plan has the module.
        $this->as($this->clinicA, 'cliente');
        $this->get('/bookings')->assertForbidden();
        $this->post('/services', ['name' => 'X', 'duration_minutes' => 30])->assertForbidden();

        // A plan without Profesionales: the permission does not bring the module back, and Agenda stops being operative.
        $partial = Plan::create(['name' => 'Solo servicios']);
        $partial->modules()->sync(Module::whereIn('key', ['service_catalog', 'bookings'])->pluck('id'));
        $this->clinicB->update(['plan_id' => $partial->id]);
        $this->as($this->clinicB);
        $this->get('/services')->assertOk();
        $this->get('/professionals')->assertForbidden();
        $this->get('/bookings')->assertForbidden();
        $this->post('/bookings', ['service_id' => 1, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'X', 'phone' => '+573001112233']])->assertForbidden();
    }

    public function test_the_administrative_workspace_keeps_every_module_without_a_plan_and_is_not_a_clinic(): void
    {
        $this->assertNull($this->dev->fresh()->plan_id);
        $this->as($this->dev);
        foreach (['/services', '/professionals', '/bookings', '/customers'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->put("/workspaces/{$this->dev->id}/plan", ['plan_id' => $this->plan->id])->assertForbidden();
        $this->assertNull($this->dev->fresh()->plan_id);
    }
}
