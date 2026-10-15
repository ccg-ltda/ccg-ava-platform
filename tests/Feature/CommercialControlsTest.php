<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Module dependencies, Dashboard/Reportes limited to the contracted modules, the separate commercial permissions and the
 * explicit plan of every Workspace. Plan availability, role permissions and Workspace isolation stay independent controls.
 */
class CommercialControlsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Workspace $dev;

    private Workspace $client;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->organization = Organization::create(['name' => 'CCG']);
        $this->dev = $this->workspace('DESARROLLO_DEV');
        $this->client = $this->workspace('CLIENTE_UNO');
        $this->other = $this->workspace('CLIENTE_DOS');
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => $this->organization->id, 'code' => $code, 'name' => $code]);
    }

    private function actIn(Workspace $workspace, string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    /** @param  list<string>  $keys */
    private function contract(Workspace $workspace, array $keys): Plan
    {
        $plan = Plan::create(['name' => 'Plan '.$workspace->code.Plan::count()]);
        $plan->modules()->sync(Module::whereIn('key', $keys)->pluck('id'));
        $workspace->update(['plan_id' => $plan->id]);

        return $plan;
    }

    private function conversation(Workspace $workspace, string $contact): void
    {
        $bot = Chatbot::where('workspace_id', $workspace->id)->first() ?? $workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => $contact]);
        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => now()]);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    // --- dependencies --------------------------------------------------------------------------------------------------

    public function test_assistants_are_not_operative_without_conversations_and_nothing_else_gains_a_dependency(): void
    {
        $access = app(ModuleAccess::class);

        $this->assertSame([
            'assistants' => ['conversations'],
            'professionals' => ['service_catalog'],
            'bookings' => ['service_catalog', 'professionals'],
            'calendar_providers' => ['bookings', 'integrations'],
            'campaigns' => ['customers'],
        ], $access->dependencies());

        $this->contract($this->client, ['assistants', 'dashboard']);
        $this->assertSame(['dashboard'], $access->keys($this->client->fresh()));

        $this->contract($this->client, ['assistants', 'conversations']);
        $this->assertEqualsCanonicalizing(['assistants', 'conversations'], $access->keys($this->client->fresh()));

        // Conversaciones, Reportes and the rest work on their own.
        foreach (array_diff(array_keys(config('modules.implemented')), array_keys($access->dependencies())) as $key) {
            $this->contract($this->client, [$key]);
            $this->assertSame([$key], $access->keys($this->client->fresh()), $key);
        }
    }

    public function test_the_routes_of_assistants_answer_403_when_the_plan_lacks_conversations(): void
    {
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['assistants']);

        $this->get('/chatbots')->assertForbidden();
        $this->get('/conversations')->assertForbidden();
    }

    public function test_the_server_refuses_a_plan_that_leaves_a_module_without_its_dependency(): void
    {
        $this->actIn($this->dev, 'plataforma');
        $plan = $this->contract($this->other, ['dashboard']);

        $assistants = Module::where('key', 'assistants')->value('id');
        $conversations = Module::where('key', 'conversations')->value('id');

        $this->put("/plans/{$plan->id}/modules", ['module_ids' => [$assistants]])->assertSessionHasErrors('module_ids');
        $this->assertSame(['dashboard'], $plan->modules()->pluck('key')->all());

        $this->post('/plans', ['name' => 'Incompleto', 'module_ids' => [$assistants]])->assertSessionHasErrors('module_ids');
        $this->assertNull(Plan::where('name', 'Incompleto')->first());

        $this->put("/plans/{$plan->id}/modules", ['module_ids' => [$assistants, $conversations]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['assistants', 'conversations'], $plan->modules()->pluck('key')->all());
    }

    public function test_the_catalog_and_the_plan_list_explain_the_dependency(): void
    {
        $this->actIn($this->dev, 'plataforma');

        $catalog = collect($this->props('/plans')['catalog'])->flatMap(fn ($category) => $category['modules'])->keyBy('key');
        $this->assertSame('conversations', $catalog['assistants']['requires'][0]['key']);
        $this->assertSame([], $catalog['conversations']['requires']);

        // A plan saved before the rule existed is flagged, not hidden.
        $legacy = Plan::create(['name' => 'Heredado']);
        $legacy->modules()->sync([Module::where('key', 'assistants')->value('id')]);
        $summary = collect($this->props('/plans')['plans'])->firstWhere('name', 'Heredado');
        $this->assertSame('assistants', $summary['unmet'][0]['key']);
        $this->assertSame(['conversations'], $summary['unmet'][0]['missing']);
    }

    // --- Dashboard and Reportes only show what the plan includes -----------------------------------------------------

    public function test_the_dashboard_sends_nothing_about_modules_the_workspace_has_not_contracted(): void
    {
        $this->conversation($this->client, '573001');
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['dashboard', 'users_roles']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $props = $this->props('/dashboard');

        $this->assertSame(['conversations' => false, 'assistants' => false, 'users' => true], $props['sources']);
        $this->assertSame(['users'], array_keys($props['kpis']['data']));
        foreach (['activity', 'states', 'channels', 'demo', 'attention', 'recentConversations', 'integrations', 'recentActivity'] as $key) {
            $this->assertNull($props[$key], $key);
        }
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => preg_match('/from "(conversations|messages|chatbots|chatbot_executions)"/i', $sql))), 'the server must not even read the tables of modules the plan lacks');
    }

    public function test_the_dashboard_keeps_the_figures_of_the_contracted_modules_and_the_role_permissions(): void
    {
        $this->conversation($this->client, '573001');
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['dashboard', 'conversations', 'audit']);

        $props = $this->props('/dashboard');

        $this->assertSame(1, $props['kpis']['data']['conversations']['value']);
        $this->assertArrayNotHasKey('assistants', $props['kpis']['data']);
        $this->assertArrayNotHasKey('users', $props['kpis']['data']);
        $this->assertNotNull($props['activity']['data']);
        $this->assertNotNull($props['recentConversations']);
        $this->assertNotNull($props['recentActivity']);

        // The role still limits what the module shows: `cliente` has no view-conversations.
        $this->actIn($this->client, 'cliente');
        $props = $this->props('/dashboard');
        $this->assertNull($props['recentConversations']);
        $this->assertNull($props['recentActivity']);
        $this->assertNotNull($props['channels']);
    }

    public function test_reports_hide_the_conversation_and_team_figures_the_plan_lacks(): void
    {
        $this->conversation($this->client, '573001');
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['reports', 'dashboard']);

        $props = $this->props('/reports');

        $this->assertSame(['conversations' => false, 'team' => false], $props['sources']);
        foreach (['figures', 'activity', 'channels', 'states', 'chatHours', 'messageHours', 'stats', 'roleBreakdown', 'recentUsers', 'teamGrowth'] as $key) {
            $this->assertNull($props[$key], $key);
        }
        $this->assertSame([], $props['metrics']);

        $this->contract($this->client, ['reports', 'conversations']);
        $props = $this->props('/reports');
        $this->assertTrue($props['sources']['conversations']);
        $this->assertNull($props['stats']);
        $this->assertNotNull($props['figures']['data']);
        $this->assertNotEmpty($props['metrics']);

        $this->contract($this->client, ['reports', 'users_roles']);
        $props = $this->props('/reports');
        $this->assertTrue($props['sources']['team']);
        $this->assertNull($props['figures']);
        $this->assertNotNull($props['stats']);
    }

    public function test_the_platform_administrator_sees_a_client_through_the_plan_of_that_client(): void
    {
        $this->conversation($this->client, '573001');
        $this->conversation($this->other, '573002');
        $this->contract($this->client, ['dashboard']);
        $this->contract($this->other, ['dashboard', 'conversations']);
        $this->actIn($this->dev, 'admin', superuser: true);

        $client = $this->props("/dashboard?workspace={$this->client->id}");
        $this->assertFalse($client['sources']['conversations']);
        $this->assertNull($client['channels']);

        $other = $this->props("/dashboard?workspace={$this->other->id}");
        $this->assertSame(1, $other['kpis']['data']['conversations']['value']);

        // "All" is the administrative view: it has every module.
        $this->assertTrue($this->props('/dashboard?workspace=all')['sources']['conversations']);
    }

    public function test_a_client_cannot_borrow_the_dashboard_scope_of_another_workspace(): void
    {
        $this->actIn($this->client, 'admin', superuser: true);

        $this->get("/dashboard?workspace={$this->other->id}")->assertForbidden();
        $this->get('/reports?workspace=all')->assertForbidden();
    }

    // --- commercial permissions ----------------------------------------------------------------------------------------

    public function test_the_new_permissions_exist_and_only_the_plataforma_role_holds_them(): void
    {
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder'])->assertSuccessful();

        foreach (['admin', 'supervisor', 'cliente', 'agente'] as $role) {
            $this->assertFalse(Role::findByName($role, 'web')->hasAnyPermission(['manage-plans', 'manage-modules']), $role);
        }
        $this->assertTrue(Role::findByName('plataforma', 'web')->hasAllPermissions(['manage-plans', 'manage-modules']));
    }

    public function test_plan_administration_and_catalog_administration_are_separate_permissions(): void
    {
        Role::findOrCreate('comercial', 'web')->syncPermissions(['manage-plans', 'view-dashboard']);
        Role::findOrCreate('catalogador', 'web')->syncPermissions(['manage-modules', 'view-dashboard']);
        $category = ModuleCategory::where('key', 'health')->value('id');

        $this->actIn($this->dev, 'comercial');
        $this->assertSame(['plans' => true, 'modules' => false], $this->props('/plans')['can']);
        $this->post('/plans', ['name' => 'Nuevo'])->assertSessionHasNoErrors();
        $this->post('/modules', ['name' => 'Citas', 'module_category_id' => $category])->assertForbidden();
        $this->post('/module-categories', ['name' => 'Otra'])->assertForbidden();

        $this->actIn($this->dev, 'catalogador');
        $props = $this->props('/plans');
        $this->assertSame(['plans' => false, 'modules' => true], $props['can']);
        $this->assertSame([], $props['plans']);
        $this->post('/modules', ['name' => 'Citas', 'module_category_id' => $category])->assertSessionHasNoErrors();
        $this->post('/plans', ['name' => 'Otro'])->assertForbidden();
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => Plan::first()->id])->assertForbidden();
        $this->get('/plans/'.Plan::first()->id)->assertForbidden();
    }

    public function test_a_role_without_the_permissions_cannot_open_planes_even_in_the_administrative_workspace(): void
    {
        $this->actIn($this->dev, 'supervisor');
        $this->get('/plans')->assertForbidden();
        $this->post('/plans', ['name' => 'X'])->assertForbidden();

        $props = $this->props('/dashboard');
        $this->assertFalse($props['platform']);
        $this->assertSame(['plans' => false, 'modules' => false], $props['platformAccess']);
    }

    public function test_in_a_client_workspace_nobody_reaches_the_commercial_administration_whatever_the_role_or_superuser_flag(): void
    {
        $plan = Plan::first();

        foreach ([['admin', true], ['admin', false], ['plataforma', true], ['plataforma', false]] as [$role, $superuser]) {
            $this->actIn($this->client, $role, $superuser);

            $this->get('/plans')->assertForbidden();
            $this->get("/plans/{$plan->id}")->assertForbidden();
            $this->post('/plans', ['name' => 'Cliente'])->assertForbidden();
            $this->put("/plans/{$plan->id}/modules", ['module_ids' => []])->assertForbidden();
            $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $plan->id])->assertForbidden();
            $this->post('/modules', ['name' => 'X', 'module_category_id' => 1])->assertForbidden();
            $this->post('/module-categories', ['name' => 'X'])->assertForbidden();
            $this->assertFalse($this->props('/dashboard')['platform']);
        }
    }

    public function test_the_permission_comes_from_the_role_or_from_being_superuser_inside_the_administrative_workspace(): void
    {
        // An admin of the administrative Workspace without the permission and without being superuser: refused.
        $this->actIn($this->dev, 'admin', false);
        $this->get('/plans')->assertForbidden();
        $this->post('/plans', ['name' => 'X'])->assertForbidden();
        $this->post('/module-categories', ['name' => 'X'])->assertForbidden();

        // A superuser working inside the administrative Workspace always administers plans and the catalog, whatever their role
        // (otherwise the account that assigns roles could never open Planes).
        $this->actIn($this->dev, 'admin', true);
        $this->get('/plans')->assertOk();
        $this->post('/plans', ['name' => 'Por superusuario'])->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('platform', true));

        // With the permission, a plain user (not superuser) is enough.
        $user = $this->actIn($this->dev, 'plataforma');
        $this->assertFalse($user->is_superuser);
        $this->get('/plans')->assertOk();
        $this->post('/plans', ['name' => 'Sin superusuario'])->assertSessionHasNoErrors();
        $this->post('/module-categories', ['name' => 'Categoría nueva'])->assertSessionHasNoErrors();
    }

    public function test_the_permission_does_not_widen_the_general_privileges_of_its_holder(): void
    {
        $this->actIn($this->dev, 'plataforma');

        foreach (['/users', '/settings', '/integrations', '/chatbots', '/conversations', '/audit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->put("/workspaces/{$this->client->id}/members/1", ['role' => 'admin'])->assertForbidden();
        $this->post('/roles', ['name' => 'x'])->assertForbidden();
    }

    public function test_only_a_superuser_decides_who_receives_the_commercial_permissions(): void
    {
        $role = Role::findOrCreate('contable', 'web');

        $this->actIn($this->dev, 'plataforma'); // holds the permission but not the roles catalog
        $this->put("/roles/{$role->id}", ['permissions' => ['manage-plans']])->assertForbidden();

        $this->actIn($this->dev, 'admin', superuser: true);
        $this->put("/roles/{$role->id}", ['permissions' => ['manage-plans']])->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->hasPermissionTo('manage-plans'));
    }

    public function test_changing_the_plan_of_a_workspace_does_not_change_the_roles_of_its_users(): void
    {
        $user = $this->actIn($this->client, 'agente');
        $this->contract($this->client, ['dashboard', 'conversations']);

        $this->get('/conversations')->assertOk();
        $this->get('/settings')->assertForbidden(); // the plan lacks it AND the role lacks it
        $this->assertSame('agente', $user->roleInWorkspace($this->client));
    }

    // --- explicit plan per Workspace ---------------------------------------------------------------------------------

    public function test_every_new_client_workspace_gets_the_default_plan_whichever_flow_creates_it(): void
    {
        $default = Plan::where('key', 'full_platform')->value('id');

        $this->assertSame($default, $this->client->fresh()->plan_id);
        $this->assertSame($default, $this->workspace('OTRO_NUEVO')->fresh()->plan_id);
        $this->assertNull($this->dev->fresh()->plan_id, 'the administrative Workspace takes no plan');

        $custom = Plan::create(['name' => 'Medida']);
        $created = Workspace::create(['organization_id' => $this->organization->id, 'code' => 'CON_PLAN', 'name' => 'Con plan', 'plan_id' => $custom->id]);
        $this->assertSame($custom->id, $created->fresh()->plan_id);
    }

    public function test_the_default_plan_is_assigned_to_old_workspaces_without_changing_their_access(): void
    {
        Workspace::whereKey([$this->client->id, $this->other->id])->update(['plan_id' => null]);
        $before = app(ModuleAccess::class)->keys($this->client->fresh());

        $this->seed(ModuleCatalogSeeder::class);

        $default = Plan::where('key', 'full_platform')->value('id');
        $this->assertSame($default, $this->client->fresh()->plan_id);
        $this->assertSame($default, $this->other->fresh()->plan_id);
        $this->assertNull($this->dev->fresh()->plan_id);
        $this->assertEqualsCanonicalizing($before, app(ModuleAccess::class)->keys($this->client->fresh()));
        $this->assertSame(0, app(ModuleCatalog::class)->assignDefaultPlanToUnassigned());
    }

    public function test_a_workspace_with_an_explicit_plan_keeps_it_when_the_seeder_runs_again(): void
    {
        $plan = $this->contract($this->client, ['dashboard']);

        $this->seed(ModuleCatalogSeeder::class);

        $this->assertSame($plan->id, $this->client->fresh()->plan_id);
    }

    public function test_assigning_plans_is_controlled_inactive_plans_and_the_administrative_workspace_are_refused(): void
    {
        $this->actIn($this->dev, 'plataforma');
        $inactive = Plan::create(['name' => 'Apagado', 'is_active' => false]);
        $active = Plan::create(['name' => 'Activo']);

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $inactive->id])->assertSessionHasErrors('plan_id');
        $this->put("/workspaces/{$this->dev->id}/plan", ['plan_id' => $active->id])->assertSessionHasErrors('workspace_id');
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => 999999])->assertSessionHasErrors('plan_id');

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $active->id])->assertSessionHasNoErrors();
        $this->assertSame($active->id, $this->client->fresh()->plan_id);
        $this->assertSame(Plan::where('key', 'full_platform')->value('id'), $this->other->fresh()->plan_id, 'another Workspace is untouched');
    }

    public function test_the_administrative_workspace_stays_exempt_from_commercial_restrictions(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);

        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), app(ModuleAccess::class)->keys($this->dev));
        $this->get('/chatbots')->assertOk();
        $this->get('/conversations')->assertOk();
    }

    public function test_the_seeded_workspaces_and_the_e2e_fixtures_receive_the_default_plan(): void
    {
        Workspace::query()->delete();
        $this->seed(DatabaseSeeder::class);

        $default = Plan::where('key', 'full_platform')->value('id');
        $this->assertSame($default, Workspace::where('code', 'OPERACION_PRD')->value('plan_id'));
        $this->assertNull(Workspace::where('code', 'DESARROLLO_DEV')->value('plan_id'));

        $this->artisan('e2e:setup')->assertSuccessful();
        $this->assertSame($default, Workspace::where('code', 'E2E_WS')->value('plan_id'));
    }
}
