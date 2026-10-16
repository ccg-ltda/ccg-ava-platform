<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingSetting;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Two solutions reuse the same modules (Agenda, Profesionales, Catálogo de servicios): the code is shared, the data and the booking rules
 * never are. Every case below shares the SAME plan modules between Workspaces and proves that nothing crosses.
 */
class SolutionReuseIsolationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $clinic;

    private Workspace $salon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        // Same modules, different business: what a clinic and a salon would each contract.
        $this->clinic = $this->bookingWorkspace('CLINICA');
        $this->salon = $this->bookingWorkspace('SALON');
    }

    private function booked(Workspace $workspace, string $name = 'Paciente'): Appointment
    {
        $service = $this->makeService($workspace, ['name' => "Servicio {$workspace->code}"]);
        $professional = $this->makeProfessional($workspace, [$service], "Prof {$workspace->code}");

        return $this->appointments()->book($workspace, $service, $professional->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), ['name' => $name, 'phone' => '3001112233', 'email' => null], 'admin')['appointment'];
    }

    // --- reuse: one implementation, labelled for both solutions -------------------------------------------------------

    public function test_the_agenda_modules_are_labelled_as_reusable_by_health_and_nothing_else_changes(): void
    {
        $catalog = collect(app(ModuleCatalog::class)->grouped())->flatMap(fn (array $category) => collect($category['modules'])->map(fn (array $module) => $module + ['category' => $category['key']]))->keyBy('key');

        foreach (['service_catalog', 'locations', 'professionals', 'bookings'] as $key) {
            $this->assertSame(['health'], array_column($catalog[$key]['alsoFor'], 'key'), $key);
            $this->assertSame('services_bookings', $catalog[$key]['category'], 'the module stays one single record in its own category');
        }

        // Catálogo de productos and Pedidos belong to E-commerce and are also offered to Delivery: one implementation, never a copy.
        foreach (['product_catalog', 'orders'] as $key) {
            $this->assertSame(['delivery'], array_column($catalog[$key]['alsoFor'], 'key'), $key);
            $this->assertSame('ecommerce', $catalog[$key]['category'], $key);
        }

        foreach ($catalog->except(['service_catalog', 'locations', 'professionals', 'bookings', 'product_catalog', 'orders']) as $module) {
            $this->assertSame([], $module['alsoFor'], $module['key']);
        }

        $this->assertSame(1, Module::where('key', 'bookings')->count());
        $this->assertTrue(ModuleCategory::where('key', 'health')->exists());
    }

    public function test_the_label_grants_nothing_a_plan_decides_and_dependencies_still_apply(): void
    {
        $access = app(ModuleAccess::class);
        $plain = $this->bookingWorkspace('SOLO_AGENDA', ['bookings']);

        $this->assertSame([], $access->keys($plain->fresh()), 'bookings without its dependencies is not operative');
        $this->assertContains('bookings', $access->keys($this->clinic->fresh()));
        $this->assertEqualsCanonicalizing(['dashboard', 'service_catalog', 'professionals', 'bookings'], $access->keys($this->salon->fresh()));
    }

    // --- operating rules: one row per Workspace -----------------------------------------------------------------------

    public function test_a_workspace_without_its_own_rules_keeps_the_defaults_every_existing_client_has(): void
    {
        $this->assertNull($this->clinic->bookingSetting);

        $setting = BookingSetting::for($this->clinic);
        $this->assertSame([config('bookings.defaults.min_notice_minutes'), config('bookings.defaults.max_advance_days'), config('bookings.defaults.cancel_notice_hours'), config('bookings.defaults.slot_step_minutes')], [$setting->min_notice_minutes, $setting->max_advance_days, $setting->cancel_notice_hours, $setting->slot_step_minutes]);
        $this->assertSame([60, 60, 2, 15], [$setting->min_notice_minutes, $setting->max_advance_days, $setting->cancel_notice_hours, $setting->slot_step_minutes]);
    }

    public function test_the_rules_of_one_workspace_never_change_those_of_another(): void
    {
        $this->actAsMember($this->clinic);
        $rules = ['provider' => 'internal', 'requires_confirmation' => true, 'min_notice_minutes' => 1440, 'max_advance_days' => 120, 'cancel_notice_hours' => 24, 'slot_step_minutes' => 30, 'require_phone' => true, 'require_email' => true, 'public_enabled' => false];

        $this->put('/bookings/settings', $rules)->assertSessionHasNoErrors();

        $clinic = BookingSetting::for($this->clinic->fresh());
        $this->assertSame([true, 1440, 120, 24, 30, true], [$clinic->requires_confirmation, $clinic->min_notice_minutes, $clinic->max_advance_days, $clinic->cancel_notice_hours, $clinic->slot_step_minutes, $clinic->require_email]);

        $salon = BookingSetting::for($this->salon->fresh());
        $this->assertNull($this->salon->fresh()->bookingSetting, 'the other Workspace got no row');
        $this->assertSame([false, 60, 60, 2, 15, false], [$salon->requires_confirmation, $salon->min_notice_minutes, $salon->max_advance_days, $salon->cancel_notice_hours, $salon->slot_step_minutes, $salon->require_email]);
        $this->assertSame(1, BookingSetting::count());
    }

    public function test_the_rules_decide_what_is_offered_per_workspace(): void
    {
        $this->clinic->bookingSetting()->create(['min_notice_minutes' => 1440] + config('bookings.defaults'));
        $this->clinic->bookingSetting->update(['min_notice_minutes' => 1440]);
        $clinicService = $this->makeService($this->clinic);
        $clinicPro = $this->makeProfessional($this->clinic, [$clinicService]);
        $salonService = $this->makeService($this->salon);
        $salonPro = $this->makeProfessional($this->salon, [$salonService]);

        $from = $this->now();
        $clinicFirst = $this->calculator()->slots($this->clinic->fresh(), $clinicService, $clinicPro->id, $from, $from->addDays(7))[0] ?? null;
        $salonFirst = $this->calculator()->slots($this->salon->fresh(), $salonService, $salonPro->id, $from, $from->addDays(7))[0] ?? null;

        $this->assertNotNull($clinicFirst);
        $this->assertNotNull($salonFirst);
        $this->assertTrue($clinicFirst['starts_at']->gte($from->addMinutes(1440)), 'the clinic asked for a day of notice');
        $this->assertTrue($salonFirst['starts_at']->lt($clinicFirst['starts_at']), 'the salon keeps the default notice');
    }

    // --- identifiers manipulated across Workspaces --------------------------------------------------------------------

    public function test_resources_exceptions_and_the_agenda_of_another_workspace_cannot_be_reached_by_id(): void
    {
        $foreign = $this->booked($this->salon, 'Ajeno');
        $resource = $this->salon->bookableResources()->create(['name' => 'Sala ajena']);
        $exception = $this->salon->scheduleExceptions()->create(['professional_id' => null, 'date_from' => '2026-12-24', 'date_to' => '2026-12-25', 'starts_at' => null, 'ends_at' => null, 'reason' => 'Ajeno']);
        $foreignPro = $this->salon->professionals()->first();
        $foreignKey = BookingSetting::for($this->salon)->ensurePublicKey();
        $this->salon->bookingSetting()->updateOrCreate([], ['public_enabled' => true]);
        $this->actAsMember($this->clinic);

        $this->put("/professionals/resources/{$resource->id}", ['name' => 'Hackeado'])->assertNotFound();
        $this->post("/professionals/resources/{$resource->id}/deactivate")->assertNotFound();
        $this->post("/professionals/resources/{$resource->id}/activate")->assertNotFound();
        $this->put("/professionals/exceptions/{$exception->id}", ['date_from' => '2026-12-01', 'date_to' => '2026-12-02'])->assertNotFound();
        $this->delete("/professionals/exceptions/{$exception->id}")->assertNotFound();
        $this->post('/professionals/exceptions', ['professional_id' => $foreignPro->id, 'date_from' => '2026-12-01', 'date_to' => '2026-12-02'])->assertSessionHasErrors('professional_id');
        $this->post("/professionals/{$foreignPro->id}/deactivate")->assertNotFound();
        $this->post("/bookings/{$foreign->id}/status", ['status' => 'completed'])->assertNotFound();
        $this->post("/bookings/{$foreign->id}/refresh")->assertNotFound();
        // A service of another Workspace is simply not one of ours: no slots, and nothing about it is revealed.
        $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $foreign->service_id, 'professional_id' => $foreignPro->id, 'from' => '2026-10-13', 'days' => 3]))->assertOk()->assertJsonPath('slots', []);
        $own = $this->makeService($this->clinic);
        $this->makeProfessional($this->clinic, [$own]);
        $this->getJson('/bookings/availability?'.http_build_query(['service_id' => $own->id, 'ignore_appointment' => $foreign->id, 'from' => '2026-10-13', 'days' => 3]))->assertNotFound();

        $this->assertTrue($resource->fresh()->is_active);
        $this->assertSame('Sala ajena', $resource->fresh()->name);
        $this->assertNotNull($exception->fresh());
        $this->assertTrue($foreignPro->fresh()->is_active);
        $this->assertSame('confirmed', $foreign->fresh()->status);
        $this->assertSame($foreignKey, $this->salon->fresh()->bookingSetting->public_key);
    }

    public function test_the_own_filters_and_dialogs_never_list_the_other_workspace(): void
    {
        $this->booked($this->salon, 'Ajeno');
        $other = $this->salon->professionals()->first();
        $this->actAsMember($this->clinic);

        $this->get("/bookings?from=2026-10-13&days=7&professional={$other->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('appointments', 0)->has('customers.data', 0)->has('options.services', 0)->has('options.professionals', 0));
        $this->get('/professionals')->assertInertia(fn (AssertableInertia $page) => $page->has('professionals', 0)->has('services', 0));
        $this->get('/services')->assertOk()->assertDontSee('Servicio SALON');
    }

    public function test_rotating_the_public_link_only_changes_the_own_workspace(): void
    {
        $this->salon->bookingSetting()->create(['public_enabled' => true, 'public_key' => str_repeat('a', 32)] + config('bookings.defaults'));
        $this->salon->bookingSetting->forceFill(['public_enabled' => true, 'public_key' => str_repeat('a', 32)])->save();
        $this->actAsMember($this->clinic);

        $this->post('/bookings/settings/public-key')->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('a', 32), $this->salon->fresh()->bookingSetting->public_key);
        $this->assertNotSame(str_repeat('a', 32), $this->clinic->fresh()->bookingSetting->public_key);
    }

    // --- Desarrollo_DEV ------------------------------------------------------------------------------------------------

    public function test_the_administrative_workspace_reads_only_its_own_agenda_and_keeps_no_plan(): void
    {
        $foreign = $this->booked($this->salon, 'Ajeno');
        $dev = $this->bookingWorkspace('DESARROLLO_DEV', []);
        $dev->update(['plan_id' => null]);
        $this->actAsMember($dev);

        $this->assertNull($dev->fresh()->plan_id);
        $this->assertContains('bookings', app(ModuleAccess::class)->keys($dev->fresh()), 'the administrative Workspace has every implemented module without a plan');
        $this->get("/bookings?from=2026-10-13&days=7&workspace={$this->salon->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 0)->has('customers.data', 0));
        $this->post("/bookings/{$foreign->id}/cancel")->assertNotFound();
        $this->assertSame('confirmed', $foreign->fresh()->status);
    }
}
