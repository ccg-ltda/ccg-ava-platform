<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLimitOverride;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use App\Services\WorkspaceLimits;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Limits in two levels: the standard a plan sells and the particular one of ONE Workspace. The precedence is one rule (particular > plan > none), the
 * `users` limit is enforced where members are added, a particular limit never touches the plan or another Workspace, only the platform administration
 * sets it (with a reason and an author), and the administrative Workspace is never limited. Also: the editor of a plan does not fail in SQL when the
 * database lacks the commercial columns, and the module names used in dependencies are the ones of the sidebar.
 */
class WorkspaceLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Workspace $acme;

    private Workspace $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $this->plan = Plan::create(['name' => 'Salud base', 'is_active' => true]);
        $this->plan->modules()->sync(Module::whereIn('key', ['dashboard', 'users_roles'])->pluck('id'));
        $org = Organization::create(['name' => 'Cliente']);
        $this->acme = Workspace::create(['organization_id' => $org->id, 'code' => 'ACME', 'name' => 'Acme', 'plan_id' => $this->plan->id]);
        $this->beta = Workspace::create(['organization_id' => $org->id, 'code' => 'BETA', 'name' => 'Beta', 'plan_id' => $this->plan->id]);
    }

    private function limits(): WorkspaceLimits
    {
        return app(WorkspaceLimits::class);
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

    private function clientAdmin(Workspace $workspace): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'admin']);

        return $user;
    }

    private function newUser(Workspace $workspace, string $email): array
    {
        return ['workspace_id' => $workspace->id, 'name' => 'Nuevo Usuario', 'email' => $email, 'password' => 'ClaveSegura123!', 'password_confirmation' => 'ClaveSegura123!', 'role' => 'cliente'];
    }

    private function asClient(User $admin, Workspace $workspace): void
    {
        $this->actingAs($admin)->withSession(['workspace_id' => $workspace->id]);
    }

    // --- precedence ------------------------------------------------------------------------------------------------

    public function test_precedence_is_particular_then_plan_then_none_and_an_absent_value_is_never_a_number(): void
    {
        $this->assertSame(['value' => null, 'source' => 'none'], array_intersect_key($this->limits()->effective($this->acme, 'users'), ['value' => 1, 'source' => 1]));

        PlanLimit::create(['plan_id' => $this->plan->id, 'metric' => 'users', 'value' => 5]);
        $this->assertSame([5, 'plan'], [$this->limits()->effective($this->acme, 'users')['value'], $this->limits()->effective($this->acme, 'users')['source']]);

        $this->limits()->setOverride($this->acme, 'users', 12, 'Contrato especial firmado', null);
        $acme = $this->limits()->effective($this->acme, 'users');
        $this->assertSame([12, 'workspace', 5], [$acme['value'], $acme['source'], $acme['standard']]);
        $this->assertSame(5, $this->limits()->effective($this->beta, 'users')['value'], 'another Workspace of the same plan keeps the standard');

        $this->limits()->setOverride($this->acme, 'users', null, 'Sin tope mientras negociamos', null);
        $this->assertNull($this->limits()->effective($this->acme, 'users')['value']);
        $this->assertSame('workspace', $this->limits()->effective($this->acme, 'users')['source'], 'explicitly unlimited is not the same as no limit defined');

        $this->limits()->clearOverride($this->acme, 'users');
        $this->assertSame([5, 'plan'], [$this->limits()->effective($this->acme, 'users')['value'], $this->limits()->effective($this->acme, 'users')['source']]);

        // Changing the plan's standard changes who inherits it, never a Workspace with its own limit.
        $this->limits()->setOverride($this->acme, 'users', 12, 'Contrato especial firmado', null);
        $this->limits()->setPlanLimits($this->plan, ['users' => 3]);
        $this->assertSame([12, 3], [$this->limits()->effective($this->acme, 'users')['value'], $this->limits()->effective($this->beta, 'users')['value']]);
    }

    public function test_the_administrative_workspace_is_never_limited_and_takes_no_exception(): void
    {
        $dev = Workspace::create(['organization_id' => $this->acme->organization_id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        PlanLimit::create(['plan_id' => $this->plan->id, 'metric' => 'users', 'value' => 1]);

        $this->assertSame('administrative', $this->limits()->effective($dev, 'users')['source']);
        $this->limits()->assertCanAddMembers($dev);
        $this->expectExceptionMessageMatches('/administrativo/');
        $this->limits()->setOverride($dev, 'users', 1, 'No debería', null);
    }

    // --- enforcement where members are added ----------------------------------------------------------------------------

    public function test_a_workspace_at_its_user_limit_cannot_add_members_and_the_attempt_is_audited(): void
    {
        $admin = $this->clientAdmin($this->acme); // 1 member
        $this->limits()->setPlanLimits($this->plan, ['users' => 2]);
        $this->asClient($admin, $this->acme);

        $this->post('/users', $this->newUser($this->acme, 'uno@cliente.co'))->assertSessionHasNoErrors(); // the 2nd
        $this->assertSame(2, $this->limits()->usage($this->acme, 'users'));

        $this->post('/users', $this->newUser($this->acme, 'dos@cliente.co'))->assertSessionHasErrors('email');
        $this->assertSame(2, $this->limits()->usage($this->acme, 'users'));
        $this->assertFalse(User::where('email', 'dos@cliente.co')->exists(), 'the account is not even created');
        $this->assertSame(1, AuditLog::where('workspace_id', $this->acme->id)->where('resource_type', 'membership')->where('outcome', 'failed')->count());

        // Another Workspace of the same plan has its own count and is not blocked.
        $other = $this->clientAdmin($this->beta);
        $this->asClient($other, $this->beta);
        $this->post('/users', $this->newUser($this->beta, 'beta1@cliente.co'))->assertSessionHasNoErrors();
    }

    public function test_a_particular_limit_lets_one_workspace_grow_without_touching_the_plan_or_the_others(): void
    {
        $admin = $this->clientAdmin($this->acme);
        $this->limits()->setPlanLimits($this->plan, ['users' => 1]);
        $this->asClient($admin, $this->acme);
        $this->post('/users', $this->newUser($this->acme, 'bloqueado@cliente.co'))->assertSessionHasErrors('email');

        $this->platformAdmin();
        $this->put("/workspaces/{$this->acme->id}/limits", ['metric' => 'users', 'mode' => 'set', 'value' => 3, 'reason' => 'Ampliación acordada'])->assertSessionHasNoErrors();
        $this->assertSame(1, PlanLimit::where('plan_id', $this->plan->id)->value('value'), 'the shared plan is untouched');

        $this->asClient($admin, $this->acme);
        $this->post('/users', $this->newUser($this->acme, 'cabe@cliente.co'))->assertSessionHasNoErrors();

        $betaAdmin = $this->clientAdmin($this->beta);
        $this->asClient($betaAdmin, $this->beta);
        $this->post('/users', $this->newUser($this->beta, 'beta@cliente.co'))->assertSessionHasErrors('email');

        // Reset returns to the standard.
        $this->platformAdmin();
        $this->put("/workspaces/{$this->acme->id}/limits", ['metric' => 'users', 'mode' => 'reset'])->assertSessionHasNoErrors();
        $this->assertNull(WorkspaceLimitOverride::where('workspace_id', $this->acme->id)->first());
    }

    public function test_the_override_keeps_its_reason_and_author_and_is_audited_in_the_workspace(): void
    {
        $author = $this->platformAdmin();
        $this->put("/workspaces/{$this->acme->id}/limits", ['metric' => 'users', 'mode' => 'set', 'value' => 7, 'reason' => 'Piloto con 7 sedes'])->assertSessionHasNoErrors();

        $row = WorkspaceLimitOverride::where('workspace_id', $this->acme->id)->firstOrFail();
        $this->assertSame([7, 'Piloto con 7 sedes', $author->id], [$row->value, $row->reason, $row->created_by]);
        $this->assertTrue(AuditLog::where('workspace_id', $this->acme->id)->where('resource_type', 'workspace_limits')->exists());

        $page = $this->get("/workspaces/{$this->acme->id}/modules")->viewData('page')['props']['limits'];
        $this->assertTrue($page['ready']);
        $this->assertSame(['users', 7, 'workspace', 'Piloto con 7 sedes'], [$page['metrics'][0]['key'], $page['metrics'][0]['value'], $page['metrics'][0]['source'], $page['metrics'][0]['override']['reason']]);
        $this->assertSame(['users', 'locations', 'ai_executions'], array_column($page['metrics'], 'key'));
        $this->assertSame(0, $page['metrics'][2]['usage']);
        $this->assertNotEmpty($page['period']['renews']);
    }

    public function test_the_override_is_validated_on_the_server(): void
    {
        $this->platformAdmin();
        $url = "/workspaces/{$this->acme->id}/limits";

        $this->put($url, ['metric' => 'users', 'mode' => 'set', 'value' => 3])->assertSessionHasErrors('reason');
        $this->put($url, ['metric' => 'users', 'mode' => 'set', 'value' => 3, 'reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->put($url, ['metric' => 'users', 'mode' => 'set', 'reason' => 'Motivo válido'])->assertSessionHasErrors('value');
        $this->put($url, ['metric' => 'users', 'mode' => 'set', 'value' => 0, 'reason' => 'Motivo válido'])->assertSessionHasErrors('value');
        $this->put($url, ['metric' => 'users', 'mode' => 'set', 'value' => 'abc', 'reason' => 'Motivo válido'])->assertSessionHasErrors('value');
        $this->put($url, ['metric' => 'messages', 'mode' => 'set', 'value' => 5, 'reason' => 'Motivo válido'])->assertSessionHasErrors('metric'); // not enforced, so not configurable
        $this->put($url, ['metric' => 'users', 'mode' => 'forever', 'reason' => 'Motivo válido'])->assertSessionHasErrors('mode');

        $this->assertSame(0, WorkspaceLimitOverride::count());
    }

    public function test_only_the_platform_administration_can_set_a_limit(): void
    {
        $client = $this->clientAdmin($this->acme);
        $this->asClient($client, $this->acme);

        // A client administrator, even of that very Workspace, and of another one.
        $this->put("/workspaces/{$this->acme->id}/limits", ['metric' => 'users', 'mode' => 'unlimited', 'reason' => 'Me lo concedo'])->assertForbidden();
        $this->put("/workspaces/{$this->beta->id}/limits", ['metric' => 'users', 'mode' => 'unlimited', 'reason' => 'Me lo concedo'])->assertForbidden();
        $this->get("/workspaces/{$this->acme->id}/modules")->assertForbidden();

        // A platform user without the permission to assign plans.
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => $this->acme->organization_id, 'name' => 'Dev']);
        $supervisor = User::factory()->create();
        $dev->users()->attach($supervisor->id, ['role' => 'supervisor']);
        $this->actingAs($supervisor)->withSession(['workspace_id' => $dev->id]);
        $this->put("/workspaces/{$this->acme->id}/limits", ['metric' => 'users', 'mode' => 'unlimited', 'reason' => 'Sin permiso'])->assertForbidden();

        $this->assertSame(0, WorkspaceLimitOverride::count());
        $this->platformAdmin();
        $this->put('/workspaces/'.$dev->id.'/limits', ['metric' => 'users', 'mode' => 'unlimited', 'reason' => 'Dev no tiene límites'])->assertSessionHasErrors('workspace_id');
    }

    // --- the standard in the plan editor ---------------------------------------------------------------------------------

    public function test_the_plan_editor_saves_and_clears_the_standard_limit_and_a_client_cannot(): void
    {
        $this->platformAdmin();
        $base = ['name' => 'Salud base'];

        $this->put("/plans/{$this->plan->id}", $base + ['limits' => ['users' => '8']])->assertSessionHasNoErrors();
        $this->assertSame(8, PlanLimit::where('plan_id', $this->plan->id)->value('value'));
        $this->assertSame(8, collect($this->get('/plans')->viewData('page')['props']['plans'])->firstWhere('name', 'Salud base')['limits']['users']);

        // A form that does not know the limits never erases them.
        $this->put("/plans/{$this->plan->id}", $base)->assertSessionHasNoErrors();
        $this->assertSame(8, PlanLimit::where('plan_id', $this->plan->id)->value('value'));

        foreach (['0', '-3', 'abc', '2000000', '1.5'] as $bad) {
            $this->put("/plans/{$this->plan->id}", $base + ['limits' => ['users' => $bad]])->assertSessionHasErrors('limits.users');
        }
        $this->assertSame(8, PlanLimit::where('plan_id', $this->plan->id)->value('value'));

        $this->put("/plans/{$this->plan->id}", $base + ['limits' => ['users' => '']])->assertSessionHasNoErrors();
        $this->assertSame(0, PlanLimit::where('plan_id', $this->plan->id)->count(), 'empty = the plan sets none');

        $this->asClient($this->clientAdmin($this->acme), $this->acme);
        $this->put("/plans/{$this->plan->id}", $base + ['limits' => ['users' => '1']])->assertForbidden();
    }

    // --- a database that is not ready ------------------------------------------------------------------------------------

    public function test_without_the_limit_tables_nothing_is_limited_and_nothing_breaks(): void
    {
        Schema::drop('workspace_limit_overrides');
        Schema::drop('plan_limits');
        $admin = $this->clientAdmin($this->acme);
        $this->asClient($admin, $this->acme);

        $this->assertFalse($this->limits()->available());
        $this->post('/users', $this->newUser($this->acme, 'libre@cliente.co'))->assertSessionHasNoErrors();

        $this->platformAdmin();
        $this->assertFalse($this->get("/workspaces/{$this->acme->id}/modules")->viewData('page')['props']['limits']['ready']);
        $this->put("/workspaces/{$this->acme->id}/limits", ['metric' => 'users', 'mode' => 'set', 'value' => 2, 'reason' => 'Sin tablas'])->assertSessionHasErrors('value');
        $this->put("/plans/{$this->plan->id}", ['name' => 'Salud base', 'limits' => ['users' => '3']])->assertSessionHasErrors('limits');
    }

    public function test_saving_a_plan_before_the_commercial_migrations_is_a_controlled_error_not_a_sql_exception(): void
    {
        // The state of `ccg_ava` when the incident happened: `plans` without the commercial columns.
        Workspace::query()->update(['plan_id' => null]); // SQLite rebuilds the table to drop a column: nothing may point at plans meanwhile
        DB::table('module_plan')->delete();
        Schema::table('plans', function ($table) {
            $table->dropForeign(['module_category_id']);
        });
        Schema::table('plans', fn ($table) => $table->dropColumn(['module_category_id', 'price_monthly_cents', 'price_yearly_cents', 'currency', 'is_public', 'requires_quote']));
        $this->platformAdmin();

        $this->assertFalse(Plan::commercialReady());
        $props = $this->get('/plans')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['commercial']['ready']);

        // The scenario of the incident: monthly and yearly prices, online sale.
        $this->put("/plans/{$this->plan->id}", [
            'name' => 'Salud base', 'is_public' => true, 'requires_quote' => false, 'currency' => 'COP', 'price_monthly' => '59900', 'price_yearly' => '599000',
        ])->assertSessionHasErrors('offer');

        // What the database can store still saves (the editor omits the commercial fields while it is not ready).
        $sync = ['module_ids' => Module::whereIn('key', ['dashboard'])->pluck('id')->all()];
        $this->put("/plans/{$this->plan->id}", ['name' => 'Salud renombrado'] + $sync)->assertSessionHasNoErrors();
        $this->assertSame('Salud renombrado', $this->plan->fresh()->name);
    }

    public function test_saving_a_plan_with_monthly_and_yearly_prices_for_online_sale_works_once_the_migrations_are_applied(): void
    {
        $this->platformAdmin();
        $health = ModuleCategory::where('key', 'health')->firstOrFail();
        $health->update(['is_public' => true]);

        $this->put("/plans/{$this->plan->id}", [
            'name' => 'Salud base', 'solution_ids' => [$health->id], 'is_public' => true, 'requires_quote' => false, 'currency' => 'COP',
            'price_monthly' => '59900', 'price_yearly' => '599000', 'limits' => ['users' => '5'],
        ])->assertSessionHasNoErrors();

        $plan = $this->plan->fresh();
        $this->assertSame([5990000, 59900000, 'online'], [$plan->price_monthly_cents, $plan->price_yearly_cents, $plan->offerState()['state']]);
        $this->assertSame(5, $this->limits()->planLimits($plan)['users']);
    }

    // --- canonical names ---------------------------------------------------------------------------------------------------

    public function test_dependencies_are_named_like_the_sidebar_and_the_labels_follow_navigation(): void
    {
        $navigation = file_get_contents(resource_path('js/config/navigation.js'));
        preg_match_all("/label: '([^']+)', route: '[a-z.]+', icon: [A-Za-z0-9]+, permission: '[a-z-]+', module: '([a-z_]+)'/", $navigation, $found, PREG_SET_ORDER);
        $fromMenu = collect($found)->mapWithKeys(fn ($m) => [$m[2] => $m[1]])->all();

        $this->assertNotEmpty($fromMenu);
        foreach (config('modules.labels') as $key => $label) {
            $this->assertSame($fromMenu[$key] ?? null, $label, "the label of {$key} must be the one of the sidebar");
        }
        foreach ($fromMenu as $key => $label) {
            $this->assertSame($label, config("modules.labels.{$key}"), "{$key} is in the sidebar and needs its label");
        }

        $this->platformAdmin();
        $catalog = collect($this->get('/plans?tab=modulos')->viewData('page')['props']['catalog'])->flatMap(fn ($category) => $category['modules'])->keyBy('key');

        $this->assertSame(['Servicios', 'Profesionales'], array_column($catalog['bookings']['requires'], 'name'));
        $this->assertSame('Catálogo de servicios', $catalog['service_catalog']['name'], 'the module keeps its full name on its own card');
        $this->assertSame('Agenda', $catalog['bookings']['label']);
        $this->assertSame(['Agenda', 'Integraciones'], array_column($catalog['calendar_providers']['requires'], 'name'));
        $this->assertStringContainsString('sin página propia', mb_strtolower($catalog['calendar_providers']['hint']));
    }

    public function test_the_internal_workspace_keeps_every_module_whatever_the_plans_do(): void
    {
        $dev = Workspace::create(['organization_id' => $this->acme->organization_id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        PlanLimit::create(['plan_id' => $this->plan->id, 'metric' => 'users', 'value' => 1]);
        $this->limits()->setPlanLimits($this->plan, ['users' => 1]);

        $implemented = array_keys(config('modules.implemented'));
        $keys = app(ModuleAccess::class)->keys($dev);
        $this->assertEqualsCanonicalizing($implemented, $keys);
        $this->assertNull($dev->fresh()->plan_id);
        $this->assertNotNull(app(ModuleCatalog::class));
    }
}
