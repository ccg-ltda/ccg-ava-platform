<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\SignupRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use App\Services\WorkspaceLimits;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Block 2: a solution is ACTIVE and, separately, PUBLIC; free trials can be scheduled (no access before their start, access from it, restricted after the end,
 * idempotent expiry); one commercial solution per Workspace; and a limit of active locations enforced on the server. Everything is decided on the server.
 * SQLite cannot prove the row-lock serialization of two simultaneous creations: that needs PostgreSQL (see RECOMENDACIONES §39).
 */
class SolutionVisibilityTrialsAndLocationLimitsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private ModuleCategory $health;

    private ModuleCategory $shop;

    private Plan $healthPlan;

    private Plan $healthPlus;

    private Plan $shopPlan;

    private Plan $base;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $this->health = ModuleCategory::where('key', 'health')->firstOrFail();
        $this->health->update(['is_public' => true]);
        $this->shop = ModuleCategory::create(['key' => 'tienda_x', 'name' => 'Tienda X', 'description' => 'Comercio', 'is_active' => true, 'is_public' => false, 'sort_order' => 90]);

        $this->healthPlan = $this->plan('Salud base', $this->health, ['dashboard', 'assistants', 'conversations']);
        $this->healthPlus = $this->plan('Salud plus', $this->health, ['dashboard', 'assistants', 'conversations', 'reports']);
        $this->shopPlan = $this->plan('Tienda base', $this->shop, ['dashboard', 'customers']);
        $this->base = Plan::create(['name' => 'Base interna']);
        $this->base->modules()->sync(Module::whereIn('key', ['dashboard'])->pluck('id'));
    }

    /** @param  list<string>  $modules */
    private function plan(string $name, ModuleCategory $solution, array $modules): Plan
    {
        $plan = Plan::create(['name' => $name, 'module_category_id' => $solution->id, 'currency' => 'COP', 'is_public' => true, 'price_monthly_cents' => 4990000]);
        $plan->modules()->sync(Module::whereIn('key', $modules)->pluck('id'));

        return $plan;
    }

    private function platformAdmin(): User
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $user = User::factory()->create();
        Role::findOrCreate('plataforma', 'web');
        $dev->users()->attach($user->id, ['role' => 'plataforma']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        return $user;
    }

    private function client(string $code = 'CLIENTE', ?Plan $plan = null): Workspace
    {
        $workspace = Workspace::create(['organization_id' => Organization::create(['name' => "Org {$code}"])->id, 'code' => $code, 'name' => "Cliente {$code}"]);
        $plan && $workspace->update(['plan_id' => $plan->id]);

        return $workspace;
    }

    private function modules(Workspace $workspace): array
    {
        $keys = app(ModuleAccess::class)->keys($workspace->fresh());
        sort($keys);

        return $keys;
    }

    private function trial(Workspace $workspace, Plan $plan, ?string $starts, string $ends, string $reason = 'Piloto acordado con ventas')
    {
        return $this->post("/workspaces/{$workspace->id}/trial", array_filter(['plan_id' => $plan->id, 'starts_at' => $starts, 'ends_at' => $ends, 'reason' => $reason], fn ($v) => $v !== null));
    }

    /** The public wizard up to the point where it offers solutions. */
    private function wizardAtSolutions(): void
    {
        auth()->logout();
        $this->flushSession();
        $this->post('/registro/correo', ['email' => 'ana@empresa.co'])->assertRedirect();
        $this->post('/registro/codigo', ['code' => Mail::sent(SignupCodeMail::class)->last()->code])->assertSessionHasNoErrors();
        $this->post('/registro/empresa', ['admin_name' => 'Ana Pérez', 'company_name' => 'Empresa', 'tax_id' => null, 'phone' => '+57 300 111 2233', 'workspace_code' => 'empresa-uno', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123'])->assertSessionHasNoErrors();
    }

    // --- solution: active and public ----------------------------------------------------------------------------------------

    public function test_only_an_active_and_public_solution_is_offered_and_a_manipulated_request_can_not_contract_the_others(): void
    {
        $inactive = ModuleCategory::create(['key' => 'inactiva_x', 'name' => 'Inactiva', 'is_active' => false, 'is_public' => true, 'sort_order' => 91]);
        $inactivePlan = $this->plan('Plan inactiva', $inactive, ['dashboard']);

        $this->wizardAtSolutions();
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'solution')->where('solutions', fn ($list) => collect($list)->pluck('key')->all() === [(string) $this->health->id]));

        // Active but private, and inactive: both refused when the browser forces the key or the plan id.
        $this->post('/registro/solucion', ['solution' => (string) $this->shop->id])->assertSessionHasErrors('solution');
        $this->post('/registro/solucion', ['solution' => (string) $inactive->id])->assertSessionHasErrors('solution');
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id])->assertSessionHasNoErrors();
        $this->post('/registro/plan', ['plan_id' => $this->shopPlan->id])->assertSessionHasErrors('plan');
        $this->post('/registro/plan', ['plan_id' => $inactivePlan->id])->assertSessionHasErrors('plan');
        $this->assertNull(SignupRequest::firstOrFail()->plan_id);

        // The administration still sees and uses the private one (it is active).
        $this->assertTrue($this->shop->fresh()->is_active);
        $this->assertSame('incomplete', $this->shopPlan->fresh()->offerState()['state']);
    }

    public function test_an_administrator_shows_and_hides_a_solution_and_nothing_is_deleted_or_changed_for_workspaces(): void
    {
        $workspace = $this->client('CON_SALUD', $this->healthPlan);
        $before = $this->modules($workspace);
        $this->platformAdmin();

        $this->post("/module-categories/{$this->shop->id}/publish")->assertSessionHasNoErrors();
        $this->assertTrue($this->shop->fresh()->is_public);
        $this->assertSame('online', $this->shopPlan->fresh()->offerState()['state']);
        $this->assertTrue(AuditLog::where('resource_type', 'module_category')->where('resource_id', $this->shop->id)->exists());

        $this->post("/module-categories/{$this->health->id}/unpublish")->assertSessionHasNoErrors();
        $this->assertFalse($this->health->fresh()->is_public);
        $this->assertSame(2, Plan::where('module_category_id', $this->health->id)->count());
        $this->assertSame($workspace->plan_id, $workspace->fresh()->plan_id);
        $this->assertSame($before, $this->modules($workspace), 'hiding a solution changes nothing for a Workspace that already has it');
        $this->assertSame('incomplete', $this->healthPlan->fresh()->offerState()['state']);

        // An inactive solution can not be made public; a shared-capabilities category is not a solution.
        $this->post("/module-categories/{$this->shop->id}/deactivate");
        $this->post("/module-categories/{$this->shop->id}/publish")->assertSessionHasErrors('category');
        $shared = ModuleCategory::where('key', 'shared')->first() ?? ModuleCategory::where('key', '!=', 'health')->get()->first(fn ($c) => ! $c->isSolution());

        if ($shared) {
            $this->post("/module-categories/{$shared->id}/publish")->assertSessionHasErrors('category');
        }
    }

    public function test_only_the_platform_administration_changes_the_visibility_of_a_solution(): void
    {
        $workspace = $this->client('CLIENTE_A', $this->healthPlan);
        $client = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $workspace->users()->attach($client->id, ['role' => 'admin']);
        $this->actingAs($client)->withSession(['workspace_id' => $workspace->id]);

        $this->post("/module-categories/{$this->shop->id}/publish")->assertForbidden();
        $this->post("/module-categories/{$this->health->id}/unpublish")->assertForbidden();
        $this->assertTrue($this->health->fresh()->is_public);
        $this->assertFalse($this->shop->fresh()->is_public);
    }

    public function test_the_technical_key_of_a_solution_never_changes_with_its_name(): void
    {
        $this->platformAdmin();
        $key = $this->health->key;

        $this->put("/module-categories/{$this->health->id}", ['name' => 'Clínicas y Bienestar', 'description' => 'x', 'key' => 'otra', 'is_public' => false])->assertSessionHasNoErrors();

        $fresh = $this->health->fresh();
        $this->assertSame([$key, 'Clínicas y Bienestar', true], [$fresh->key, $fresh->name, $fresh->is_public], 'neither the key nor the visibility change through the name form');
    }

    // --- scheduled trials ---------------------------------------------------------------------------------------------------

    public function test_a_scheduled_trial_grants_nothing_before_its_start_and_everything_after_without_the_scheduler(): void
    {
        $workspace = $this->client('PROGRAMADA', $this->base);
        $this->platformAdmin();
        $base = $this->modules($workspace);

        $this->trial($workspace, $this->healthPlan, now()->addDays(3)->toDateString(), now()->addDays(10)->toDateString())->assertSessionHasNoErrors();

        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(Subscription::SCHEDULED, $subscription->status);
        $this->assertTrue($subscription->trial_starts_at->isFuture());
        $this->assertSame($this->base->id, $workspace->fresh()->plan_id, 'the plan of the Workspace is not touched until the trial starts');
        $this->assertSame($base, $this->modules($workspace), 'before the start: nothing from the trial, nothing taken away');
        $this->assertSame(['dashboard'], $this->modules($workspace));
        $this->assertSame($this->base->id, $workspace->fresh()->plan_id);
        $this->get("/workspaces/{$workspace->id}/modules")->assertOk()->assertInertia(fn ($page) => $page->where('data.subscription.status', 'scheduled')->where('data.subscription.trial.scheduled', true));

        // The start day arrives and NO scheduler runs: the first request already sees the trial's modules.
        $this->travel(3)->days();
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace));
        $started = $subscription->fresh();
        $this->assertSame([Subscription::TRIALING, $this->healthPlan->id], [$started->status, $workspace->fresh()->plan_id]);

        // Expired: access restricted, nothing deleted.
        $this->travel(8)->days();
        $this->assertSame([], $this->modules($workspace));
        $this->assertSame(1, Workspace::whereKey($workspace->id)->count());
    }

    public function test_starting_and_expiring_are_idempotent_and_audited_once(): void
    {
        $workspace = $this->client('IDEMPOTENTE', $this->base);
        $this->platformAdmin();
        $this->trial($workspace, $this->healthPlan, now()->addDays(2)->toDateString(), now()->addDays(5)->toDateString())->assertSessionHasNoErrors();
        $count = fn () => AuditLog::where('resource_type', 'subscription')->where('workspace_id', $workspace->id)->count();

        Artisan::call('subscriptions:expire-trials');
        $this->assertSame(Subscription::SCHEDULED, Subscription::firstOrFail()->status, 'not due: the command starts nothing');

        $this->travel(2)->days();
        Artisan::call('subscriptions:expire-trials');
        $afterStart = $count();
        $this->assertSame(Subscription::TRIALING, Subscription::firstOrFail()->status);

        foreach (range(1, 3) as $_) {
            Artisan::call('subscriptions:expire-trials');
        }

        $this->assertSame($afterStart, $count(), 'repeating the command changes and audits nothing');
        $this->modules($workspace); // a request-time lookup of a started trial changes nothing either
        $this->assertSame($afterStart, $count());

        $this->travel(4)->days();
        Artisan::call('subscriptions:expire-trials');
        Artisan::call('subscriptions:expire-trials');
        $this->assertSame(Subscription::EXPIRED, Subscription::firstOrFail()->status);
        $this->assertSame($afterStart + 1, $count(), 'one audited expiry, not two');
    }

    public function test_trial_dates_are_validated_and_a_past_start_means_now(): void
    {
        $workspace = $this->client('FECHAS', $this->base);
        $this->platformAdmin();

        $this->trial($workspace, $this->healthPlan, now()->addDays(5)->toDateString(), now()->addDays(2)->toDateString())->assertSessionHasErrors('ends_at');
        $this->trial($workspace, $this->healthPlan, now()->addDays(500)->toDateString(), now()->addDays(510)->toDateString())->assertSessionHasErrors('starts_at');
        $this->trial($workspace, $this->healthPlan, 'ayer', now()->addDays(5)->toDateString())->assertSessionHasErrors('starts_at');
        $this->assertSame(0, Subscription::count());

        $this->trial($workspace, $this->healthPlan, now()->subDays(2)->toDateString(), now()->addDays(5)->toDateString())->assertSessionHasNoErrors();
        $this->assertSame(Subscription::TRIALING, Subscription::firstOrFail()->status, 'a start in the past begins now');
        $this->assertSame($this->healthPlan->id, $workspace->fresh()->plan_id);
    }

    public function test_a_scheduled_trial_is_not_duplicated_and_can_be_extended_or_cancelled_keeping_the_workspace(): void
    {
        $workspace = $this->client('UNICA', $this->base);
        $this->platformAdmin();
        $this->trial($workspace, $this->healthPlan, now()->addDays(2)->toDateString(), now()->addDays(9)->toDateString())->assertSessionHasNoErrors();

        $this->trial($workspace, $this->healthPlan, now()->addDays(3)->toDateString(), now()->addDays(9)->toDateString())->assertSessionHasErrors('trial');
        $this->assertSame(1, Subscription::where('workspace_id', $workspace->id)->count());

        $this->put("/workspaces/{$workspace->id}/trial", ['ends_at' => now()->addDays(12)->toDateString(), 'reason' => 'Ampliada por el responsable'])->assertSessionHasNoErrors();
        $this->put("/workspaces/{$workspace->id}/trial", ['ends_at' => now()->addDays(1)->toDateString(), 'reason' => 'Antes del inicio'])->assertSessionHasErrors('ends_at');
        $this->post("/workspaces/{$workspace->id}/trial/cancel", ['reason' => 'Cliente desiste de la prueba'])->assertSessionHasNoErrors();

        $this->assertSame(Subscription::VOIDED, Subscription::firstOrFail()->status);
        $this->assertSame($this->base->id, $workspace->fresh()->plan_id);
        $this->assertSame(['dashboard'], $this->modules($workspace), 'cancelling a scheduled trial leaves the Workspace as it was');
    }

    public function test_a_scheduled_trial_whose_plan_became_unusable_is_not_started_and_the_workspace_keeps_what_it_has(): void
    {
        $workspace = $this->client('PLAN_CAE', $this->base);
        $this->platformAdmin();
        $this->trial($workspace, $this->healthPlan, now()->addDays(1)->toDateString(), now()->addDays(6)->toDateString())->assertSessionHasNoErrors();
        $this->healthPlan->update(['is_active' => false]);

        $this->travel(1)->days();

        $this->assertSame(['dashboard'], $this->modules($workspace));
        $this->assertSame([Subscription::VOIDED, $this->base->id], [Subscription::firstOrFail()->status, $workspace->fresh()->plan_id]);
    }

    public function test_the_internal_workspace_has_no_trials_and_keeps_every_module(): void
    {
        $this->platformAdmin();
        $dev = Workspace::where('code', config('workspace.admin_code'))->firstOrFail();

        $this->trial($dev, $this->healthPlan, now()->addDays(2)->toDateString(), now()->addDays(9)->toDateString())->assertSessionHasErrors('workspace_id');
        $this->assertSame(0, Subscription::count());
        $this->travel(30)->days();
        $this->assertCount(count(app(ModuleAccess::class)->implemented()), app(ModuleAccess::class)->keys($dev));
    }

    public function test_the_conditions_frozen_for_a_scheduled_trial_do_not_change_when_the_plan_is_edited(): void
    {
        $workspace = $this->client('CONGELADA', $this->base);
        $this->platformAdmin();
        PlanLimit::create(['plan_id' => $this->healthPlan->id, 'metric' => 'users', 'value' => 5]);
        $this->trial($workspace, $this->healthPlan, now()->addDays(2)->toDateString(), now()->addDays(9)->toDateString())->assertSessionHasNoErrors();

        $this->healthPlan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports', 'customers'])->pluck('id'));
        PlanLimit::where('plan_id', $this->healthPlan->id)->update(['value' => 1]);
        $this->travel(2)->days();

        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace), 'the modules accepted when it was scheduled');
        $this->assertSame(5, app(WorkspaceLimits::class)->effective($workspace->fresh(), 'users')['value']);
    }

    // --- one solution per Workspace -----------------------------------------------------------------------------------------

    public function test_a_workspace_without_a_solution_takes_a_trial_and_then_belongs_to_that_solution(): void
    {
        $workspace = $this->client('SIN_SOLUCION', $this->base);
        $this->platformAdmin();

        $this->trial($workspace, $this->healthPlan, null, now()->addDays(7)->toDateString())->assertSessionHasNoErrors();
        $this->assertSame($this->health->id, app(ModuleCatalog::class)->solutionOf($workspace->fresh()));
    }

    public function test_a_workspace_with_a_solution_only_takes_plans_of_that_solution_whatever_ids_the_request_carries(): void
    {
        $workspace = $this->client('CON_SOLUCION', $this->healthPlan);
        $this->platformAdmin();

        // Another solution: by trial (now or scheduled) and by a plan change.
        $this->trial($workspace, $this->shopPlan, null, now()->addDays(7)->toDateString())->assertSessionHasErrors('plan_id');
        $this->trial($workspace, $this->shopPlan, now()->addDays(2)->toDateString(), now()->addDays(7)->toDateString())->assertSessionHasErrors('plan_id');
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->shopPlan->id])->assertSessionHasErrors('plan_id');
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->base->id])->assertSessionHasErrors('plan_id'); // a plan with no solution is not "the same"
        $this->assertSame($this->healthPlan->id, $workspace->fresh()->plan_id);
        $this->assertSame(0, Subscription::count());

        // The same solution: fine, by plan change and by trial.
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->healthPlus->id])->assertSessionHasNoErrors();
        $this->assertSame($this->healthPlus->id, $workspace->fresh()->plan_id);
        $this->trial($workspace, $this->healthPlan, null, now()->addDays(7)->toDateString())->assertSessionHasNoErrors();
    }

    public function test_a_scheduled_trial_reserves_its_solution_against_a_plan_of_another_one(): void
    {
        $workspace = $this->client('RESERVA', $this->base);
        $this->platformAdmin();
        $this->trial($workspace, $this->healthPlan, now()->addDays(4)->toDateString(), now()->addDays(11)->toDateString())->assertSessionHasNoErrors();

        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->shopPlan->id])->assertSessionHasErrors('plan_id');
        $this->assertSame($this->base->id, $workspace->fresh()->plan_id);
    }

    public function test_a_historical_workspace_without_an_identifiable_solution_is_never_reassigned_automatically(): void
    {
        $workspace = $this->client('HISTORICO', $this->base);
        Subscription::create(['workspace_id' => $workspace->id, 'plan_id' => $this->base->id, 'status' => Subscription::ACTIVE, 'payment_status' => 'confirmed', 'payment_reference' => 'TX-HIST', 'activated_at' => now()->subYear()]);

        $this->assertNull(app(ModuleCatalog::class)->solutionOf($workspace->fresh()));
        $this->assertSame(['dashboard'], $this->modules($workspace));
        $this->assertNull(Subscription::firstOrFail()->terms, 'nothing was frozen or rewritten');
        $this->assertSame($this->base->id, $workspace->fresh()->plan_id);
    }

    public function test_a_public_signup_is_not_blocked_by_the_solution_rule_and_creates_a_workspace_with_that_solution(): void
    {
        $this->wizardAtSolutions();
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id])->assertSessionHasNoErrors();
        $this->post('/registro/plan', ['plan_id' => $this->healthPlan->id])->assertSessionHasNoErrors();
        $this->post('/registro/contratar', ['accepted' => ['terms', 'privacy_notice']])->assertSessionHasNoErrors();

        $this->assertSame(SignupRequest::AWAITING_PAYMENT, SignupRequest::firstOrFail()->status);
        $this->assertSame(0, Workspace::where('code', 'EMPRESA-UNO')->count(), 'no payment confirmed: nothing is created or activated');
    }

    // --- location limit -----------------------------------------------------------------------------------------------------

    private const LOCATION_MODULES = ['dashboard', 'locations'];

    /** The form of a location: a sede always offers at least one service, so the Workspace gets one when it has none. */
    private function sede(string $name, Workspace $workspace): array
    {
        $service = $workspace->services()->first() ?? $workspace->services()->create(['name' => 'Servicio', 'duration_minutes' => 30, 'buffer_minutes' => 0]);

        return ['name' => $name, 'address' => 'Calle 1', 'city' => 'Bogotá', 'service_ids' => [$service->id]];
    }

    public function test_the_location_limit_comes_from_the_plan_and_blocks_new_active_locations_only_at_the_maximum(): void
    {
        $workspace = $this->bookingWorkspace('SEDES_A', self::LOCATION_MODULES);
        PlanLimit::create(['plan_id' => $workspace->plan_id, 'metric' => 'locations', 'value' => 2]);
        $this->actAsMember($workspace, 'admin');

        $this->post('/locations', $this->sede('Norte', $workspace))->assertSessionHasNoErrors();
        $this->post('/locations', $this->sede('Sur', $workspace))->assertSessionHasNoErrors();
        $this->post('/locations', $this->sede('Centro', $workspace))->assertSessionHasErrors('name');
        $this->assertSame(2, Location::where('workspace_id', $workspace->id)->count());
        $this->assertTrue(AuditLog::where('workspace_id', $workspace->id)->where('resource_type', 'location')->where('outcome', 'failed')->exists());

        // Existing ones stay editable and the page still opens.
        $norte = Location::where('name', 'Norte')->firstOrFail();
        $this->put("/locations/{$norte->id}", $this->sede('Norte renombrada', $workspace))->assertSessionHasNoErrors();
        $this->get('/locations')->assertOk();

        // Deactivating frees a slot; reactivating past the maximum is refused.
        $sur = Location::where('name', 'Sur')->firstOrFail();
        $this->post("/locations/{$sur->id}/deactivate")->assertSessionHasNoErrors();
        $this->post('/locations', $this->sede('Centro', $workspace))->assertSessionHasNoErrors();
        $this->post("/locations/{$sur->id}/activate")->assertSessionHasErrors('name');
        $this->assertFalse($sur->fresh()->is_active);
        $this->assertSame(2, Location::where('workspace_id', $workspace->id)->where('is_active', true)->count());
    }

    public function test_without_a_configured_limit_locations_are_not_limited_and_nothing_is_invented(): void
    {
        $workspace = $this->bookingWorkspace('SEDES_B', self::LOCATION_MODULES);
        $this->actAsMember($workspace, 'admin');

        foreach (['A', 'B', 'C', 'D'] as $n) {
            $this->post('/locations', $this->sede("Sede {$n}", $workspace))->assertSessionHasNoErrors();
        }

        $effective = app(WorkspaceLimits::class)->effective($workspace->fresh(), 'locations');
        $this->assertSame([null, 'none'], [$effective['value'], $effective['source']]);
        $this->assertSame(4, Location::where('workspace_id', $workspace->id)->count());
    }

    public function test_a_workspace_exception_and_the_frozen_contract_decide_the_location_limit_and_other_workspaces_are_not_counted(): void
    {
        $a = $this->bookingWorkspace('SEDES_C', self::LOCATION_MODULES);
        $b = $this->bookingWorkspace('SEDES_D', self::LOCATION_MODULES);
        PlanLimit::create(['plan_id' => $a->plan_id, 'metric' => 'locations', 'value' => 5]);
        $limits = app(WorkspaceLimits::class);

        // Frozen: the contract says 1 while the plan now says 5.
        Subscription::create(['workspace_id' => $a->id, 'plan_id' => $a->plan_id, 'status' => Subscription::ACTIVE, 'payment_status' => 'confirmed', 'payment_reference' => 'TX-S', 'activated_at' => now(), 'terms' => ['version' => 1, 'source' => 'signup', 'plan' => ['id' => $a->plan_id, 'name' => 'x'], 'solution' => null, 'price' => null, 'modules' => ['dashboard', 'locations'], 'limits' => ['users' => null, 'locations' => 1, 'ai_executions' => null]], 'terms_source' => 'signup', 'terms_captured_at' => now()]);
        $this->actAsMember($a, 'admin');
        $this->post('/locations', $this->sede('Una', $a))->assertSessionHasNoErrors();
        $this->post('/locations', $this->sede('Dos', $a))->assertSessionHasErrors('name');
        $this->assertSame([1, 'contract'], [$limits->effective($a->fresh(), 'locations')['value'], $limits->effective($a->fresh(), 'locations')['source']]);

        // An authorized exception for THIS Workspace.
        $limits->setOverride($a, 'locations', 3, 'Piloto con tres sedes', null);
        $this->post('/locations', $this->sede('Dos', $a))->assertSessionHasNoErrors();

        // Another Workspace's locations are not counted here, nor here the other's.
        $b->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->assertSame(2, $limits->usage($a->fresh(), 'locations'));
        $this->assertSame(1, $limits->usage($b->fresh(), 'locations'));
    }

    public function test_the_internal_workspace_has_no_location_limit(): void
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $limits = app(WorkspaceLimits::class);

        $this->assertSame('administrative', $limits->effective($dev, 'locations')['source']);
        $limits->assertCanAddLocation($dev); // never refuses
        $this->assertTrue(true);
    }
}
