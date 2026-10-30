<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Planes: the commercial administration of CCG (plans, the module catalog and the plan of each Workspace). */
class PlansTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Workspace $dev;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $this->organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $this->organization->id, 'code' => 'CLIENTE_UNO', 'name' => 'Cliente Uno']);
    }

    private function actIn(Workspace $workspace, string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function platformAdmin(): User
    {
        return $this->actIn($this->dev, 'plataforma');
    }

    private function moduleId(string $key): int
    {
        return Module::where('key', $key)->firstOrFail()->id;
    }

    /** The provider names ids that only exist on SQLite (sequences restart per test); the denial must not depend on the resource existing. */
    private function realIds(string $uri): string
    {
        $ids = ['plans' => Plan::query()->value('id'), 'modules' => Module::query()->value('id'), 'module-categories' => ModuleCategory::query()->value('id'), 'workspaces' => $this->client->id];

        return preg_replace_callback('#^/(plans|modules|module-categories|workspaces)/\d+#', fn (array $match) => "/{$match[1]}/{$ids[$match[1]]}", $uri);
    }

    private function plan(string $name, array $keys = [], bool $active = true): Plan
    {
        $plan = Plan::create(['name' => $name, 'is_active' => $active]);
        $plan->modules()->sync(array_map(fn (string $key) => $this->moduleId($key), $keys));

        return $plan;
    }

    // --- initial data and compatibility ---------------------------------------------------------------------------

    public function test_the_catalog_starts_with_the_shared_and_the_booking_modules_the_four_areas_and_a_default_plan_of_the_shared_ones(): void
    {
        $this->assertEqualsCanonicalizing(
            ['dashboard', 'assistants', 'conversations', 'reports', 'audit', 'settings', 'integrations', 'users_roles', 'service_catalog', 'locations', 'product_catalog', 'orders', 'professionals', 'bookings', 'calendar_providers', 'customers', 'campaigns', 'reminders'],
            Module::pluck('key')->all(),
        );
        $this->assertEqualsCanonicalizing(['shared', 'health', 'delivery', 'ecommerce', 'services_bookings', 'communication'], ModuleCategory::pluck('key')->all());

        $default = Plan::where('key', 'full_platform')->firstOrFail();
        $this->assertSame(8, $default->modules()->count());
        $this->assertTrue($default->is_active);
        $this->assertTrue(Module::all()->every(fn (Module $module) => $module->isImplemented()));
    }

    public function test_a_workspace_without_a_plan_keeps_every_module_through_the_default_plan(): void
    {
        // A row created before plans existed has no plan_id; it still resolves to the default plan.
        Workspace::whereKey($this->client->id)->update(['plan_id' => null]);

        $this->assertNull($this->client->fresh()->plan_id);
        // The default plan holds the eight SHARED modules; the booking modules are added to plans on purpose.
        $this->assertEqualsCanonicalizing(['dashboard', 'assistants', 'conversations', 'reports', 'audit', 'settings', 'integrations', 'users_roles'], app(ModuleAccess::class)->keys($this->client->fresh()));
    }

    public function test_the_migration_gives_existing_client_workspaces_the_default_plan_and_leaves_the_administrative_one_alone(): void
    {
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_13_100000_create_plans_and_modules_tables.php', '--realpath' => false]);
        $this->assertFalse(Schema::hasTable('plans'));

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_13_100000_create_plans_and_modules_tables.php', '--realpath' => false]);

        $default = Plan::where('key', 'full_platform')->firstOrFail();
        $this->assertSame($default->id, $this->client->fresh()->plan_id);
        $this->assertNull($this->dev->fresh()->plan_id);
        $this->assertSame(8, $default->modules()->count());
    }

    public function test_a_new_workspace_starts_on_the_default_plan(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true); // creating Workspaces is a superuser action, not a commercial one

        $this->post('/workspaces', ['organization_id' => $this->organization->id, 'code' => 'NUEVO_WS', 'name' => 'Nuevo'])->assertSessionHasNoErrors();

        $this->assertSame(Plan::where('key', 'full_platform')->value('id'), Workspace::where('code', 'NUEVO_WS')->value('plan_id'));
    }

    // --- listing and searching --------------------------------------------------------------------------------------

    public function test_plans_are_listed_and_searched_with_their_counts(): void
    {
        $this->plan('Salud básica', ['dashboard', 'conversations']);
        $this->plan('Delivery', ['dashboard']);
        $this->platformAdmin();

        $this->get('/plans')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Plans/Index')->has('plans', 3)->has('catalog', 6)
            ->where('plans.1.name', 'Plataforma completa')->where('plans.1.isDefault', true)->where('plans.1.workspacesCount', 1)->where('plans.1.modulesCount', 8));

        $this->get('/plans?search=salud')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('plans', 1)->where('plans.0.name', 'Salud básica')->where('filters.search', 'salud'));
        $this->get('/plans?search=%25')->assertInertia(fn (AssertableInertia $page) => $page->has('plans', 0));
    }

    // --- creating and editing -----------------------------------------------------------------------------------------

    public function test_a_plan_is_created_with_modules_of_different_categories(): void
    {
        $this->platformAdmin();
        $future = app(ModuleCatalog::class)->createModule([
            'name' => 'Citas', 'description' => 'Agenda de citas', 'module_category_id' => ModuleCategory::where('key', 'health')->value('id'),
        ]);

        $response = $this->post('/plans', [
            'name' => '  Clínica  ', 'description' => '  Plan para clínicas ',
            'module_ids' => [$this->moduleId('dashboard'), $this->moduleId('conversations'), $future->id],
        ]);

        $plan = Plan::where('name', 'Clínica')->firstOrFail();
        $response->assertRedirect(route('plans.show', $plan));
        $this->assertSame('Plan para clínicas', $plan->description);
        $this->assertTrue($plan->is_active);
        $this->assertEqualsCanonicalizing(['dashboard', 'conversations', $future->key], $plan->modules()->pluck('modules.key')->all());
    }

    public function test_a_plan_needs_a_unique_name_and_real_modules(): void
    {
        $this->platformAdmin();
        $this->plan('Existente');

        $this->post('/plans', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/plans', ['name' => 'Existente'])->assertSessionHasErrors('name');
        $this->post('/plans', ['name' => str_repeat('x', 121)])->assertSessionHasErrors('name');
        $this->post('/plans', ['name' => 'Nuevo', 'description' => str_repeat('x', 1001)])->assertSessionHasErrors('description');
        $this->post('/plans', ['name' => 'Nuevo', 'module_ids' => [999999]])->assertSessionHasErrors('module_ids.0');
        $this->post('/plans', ['name' => 'Nuevo', 'module_ids' => [$this->moduleId('audit'), $this->moduleId('audit')]])->assertSessionHasErrors('module_ids.1');

        $this->assertSame(2, Plan::count());
    }

    public function test_a_plan_is_edited_and_keeps_its_modules(): void
    {
        $plan = $this->plan('Viejo', ['dashboard']);
        $this->platformAdmin();

        $this->put("/plans/{$plan->id}", ['name' => 'Nuevo nombre', 'description' => ''])->assertSessionHasNoErrors();
        $this->put("/plans/{$plan->id}", ['name' => 'Plataforma completa'])->assertSessionHasErrors('name');
        $plan->refresh();
        $this->assertSame('Nuevo nombre', $plan->name);
        $this->assertNull($plan->description);
        // The data alone (no modules sent) never touches the modules of the plan.
        $this->assertSame(['dashboard'], $plan->modules()->pluck('modules.key')->all());
    }

    public function test_the_plan_editor_saves_exactly_the_data_and_the_modules_it_shows(): void
    {
        $plan = $this->plan('Editable', ['dashboard', 'customers']);
        $this->platformAdmin();

        // The index hands the editor what the plan has saved: that is what it opens with.
        $this->get('/plans')->assertInertia(fn (AssertableInertia $page) => $page->where('plans', fn ($plans) => collect($plans)->firstWhere('id', $plan->id)['moduleIds'] === $plan->modules()->pluck('modules.id')->all()));

        $chosen = [$this->moduleId('dashboard'), $this->moduleId('audit'), $this->moduleId('settings')];
        $this->put("/plans/{$plan->id}", ['name' => 'Editable 2', 'description' => 'Con auditoría', 'module_ids' => $chosen])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(['Editable 2', 'Con auditoría'], [$plan->name, $plan->description]);
        $this->assertEqualsCanonicalizing($chosen, $plan->modules()->pluck('modules.id')->all());
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'plan', 'resource_id' => $plan->id]);
    }

    public function test_the_plan_editor_is_all_or_nothing(): void
    {
        $plan = $this->plan('Atómico', ['dashboard']);
        $this->platformAdmin();

        // A deactivated module cannot be added: neither the name nor the modules change (an incomplete dependency, in turn, is only warned about: E4).
        Module::where('key', 'campaigns')->update(['is_active' => false]);
        $this->put("/plans/{$plan->id}", ['name' => 'Otro nombre', 'module_ids' => [$this->moduleId('campaigns')]])->assertSessionHasErrors('module_ids');

        $plan->refresh();
        $this->assertSame('Atómico', $plan->name);
        $this->assertSame(['dashboard'], $plan->modules()->pluck('modules.key')->all());
    }

    public function test_the_catalog_organizes_modules_like_the_sidebar_and_says_what_each_one_needs(): void
    {
        $this->platformAdmin();

        $this->get('/plans?tab=modulos')->assertInertia(fn (AssertableInertia $page) => $page->where('catalog', function ($catalog) {
            $modules = collect($catalog)->flatMap(fn ($category) => $category['modules'])->keyBy('key');

            $this->assertSame('General', $modules['dashboard']['section']['name']);
            $this->assertSame('Operación', $modules['customers']['section']['name']);
            $this->assertSame('Operación', $modules['locations']['section']['name']);
            $this->assertSame('Administración', $modules['settings']['section']['name']);
            $this->assertSame('Administración', $modules['calendar_providers']['section']['name']);
            $this->assertNotEmpty($modules['users_roles']['hint']);
            // A module registered from the UI (no page) takes the default section; a hint exists only where the code says so.
            $this->assertNull($modules['customers']['hint']);

            return true;
        }));
    }

    public function test_modules_are_replaced_and_the_same_module_serves_several_plans(): void
    {
        $a = $this->plan('A', ['dashboard']);
        $b = $this->plan('B', ['dashboard', 'audit']);
        $this->platformAdmin();

        $this->put("/plans/{$a->id}/modules", ['module_ids' => [$this->moduleId('audit'), $this->moduleId('reports')]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['audit', 'reports'], $a->modules()->pluck('modules.key')->all());
        $this->assertEqualsCanonicalizing(['dashboard', 'audit'], $b->modules()->pluck('modules.key')->all());

        $this->put("/plans/{$a->id}/modules", ['module_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $a->modules()->count());
        $this->assertSame(2, $b->modules()->count());

        $this->put("/plans/{$a->id}/modules", [])->assertSessionHasErrors('module_ids');
        $this->put("/plans/{$a->id}/modules", ['module_ids' => [999999]])->assertSessionHasErrors('module_ids.0');
    }

    public function test_the_plan_page_lists_its_modules_and_workspaces(): void
    {
        $plan = $this->plan('Premium', ['dashboard', 'audit']);
        $this->client->update(['plan_id' => $plan->id]);
        $this->platformAdmin();

        $this->get("/plans/{$plan->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Plans/Show')->where('plan.name', 'Premium')->where('plan.workspacesCount', 1)->has('plan.moduleIds', 2)
            ->has('workspaces.data', 1)->where('workspaces.data.0.code', 'CLIENTE_UNO')->where('workspaces.data.0.explicit', true)
            ->where('workspaces.meta.total', 1));

        $this->get('/plans/999999')->assertNotFound();
    }

    // --- activating and deactivating ------------------------------------------------------------------------------------

    public function test_a_plan_is_deactivated_and_reactivated_without_losing_anything(): void
    {
        $plan = $this->plan('Temporal', ['dashboard']);
        $this->platformAdmin();

        $this->post("/plans/{$plan->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertSame(1, $plan->modules()->count());

        $this->post("/plans/{$plan->id}/activate")->assertSessionHasNoErrors();
        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_a_plan_with_workspaces_and_the_default_plan_cannot_be_deactivated(): void
    {
        $plan = $this->plan('En uso', ['dashboard']);
        $this->client->update(['plan_id' => $plan->id]);
        $this->platformAdmin();

        $this->post("/plans/{$plan->id}/deactivate")->assertSessionHasErrors('status');
        $this->assertTrue($plan->fresh()->is_active);

        $default = Plan::where('key', 'full_platform')->firstOrFail();
        $this->post("/plans/{$default->id}/deactivate")->assertSessionHasErrors('status');
        $this->assertTrue($default->fresh()->is_active);
    }

    public function test_a_plan_can_be_deactivated_once_its_workspaces_move_away(): void
    {
        $plan = $this->plan('En uso', ['dashboard']);
        $other = $this->plan('Otro', ['dashboard']);
        $this->client->update(['plan_id' => $plan->id]);
        $this->platformAdmin();

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $other->id])->assertSessionHasNoErrors();
        $this->post("/plans/{$plan->id}/deactivate")->assertSessionHasNoErrors();

        $this->assertFalse($plan->fresh()->is_active);
        $this->assertSame($other->id, $this->client->fresh()->plan_id);
    }

    // --- the module catalog -------------------------------------------------------------------------------------------

    public function test_categories_and_modules_are_registered_without_touching_code(): void
    {
        $this->platformAdmin();

        $this->post('/module-categories', ['name' => 'Educación', 'description' => 'Cursos'])->assertSessionHasNoErrors();
        $category = ModuleCategory::where('name', 'Educación')->firstOrFail();
        $this->assertSame('educacion', $category->key);

        $this->post('/modules', ['name' => 'Matrículas', 'description' => 'Inscripciones', 'module_category_id' => $category->id])->assertSessionHasNoErrors();
        $module = Module::where('name', 'Matrículas')->firstOrFail();

        $this->assertSame('matriculas', $module->key);
        $this->assertSame($category->id, $module->module_category_id);
        $this->assertFalse($module->isImplemented());
        $this->assertTrue($module->is_active);
    }

    public function test_a_registered_module_never_becomes_implemented_by_its_name_or_category(): void
    {
        $this->platformAdmin();
        $category = ModuleCategory::where('key', 'shared')->firstOrFail();

        // A name that slugs to an implemented key still gets its own, different key.
        $this->post('/modules', ['name' => 'Dashboard extra', 'module_category_id' => $category->id])->assertSessionHasNoErrors();
        $this->post('/modules', ['name' => 'Dashboard', 'module_category_id' => $category->id])->assertSessionHasErrors('name');
        $this->post('/modules', ['name' => 'Users Roles', 'module_category_id' => $category->id])->assertSessionHasNoErrors();
        $this->post('/modules', ['name' => 'users roles', 'module_category_id' => $category->id])->assertSessionHasNoErrors();

        $keys = Module::whereNotIn('key', array_keys(config('modules.implemented')))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['dashboard_extra', 'users_roles_2', 'users_roles_3'], $keys);
        $this->assertTrue(Module::whereNotIn('key', array_keys(config('modules.implemented')))->get()->every(fn (Module $module) => ! $module->isImplemented()));
    }

    public function test_renaming_or_moving_a_module_keeps_its_stable_key_and_implementation(): void
    {
        $this->platformAdmin();
        $module = Module::where('key', 'audit')->firstOrFail();
        $health = ModuleCategory::where('key', 'health')->firstOrFail();
        $health->update(['is_public' => true]);

        $this->put("/modules/{$module->id}", ['name' => 'Bitácora', 'description' => 'x', 'module_category_id' => $health->id, 'key' => 'hacked'])->assertSessionHasNoErrors();

        $module->refresh();
        $this->assertSame('audit', $module->key);
        $this->assertSame('Bitácora', $module->name);
        $this->assertSame($health->id, $module->module_category_id);
        $this->assertTrue($module->isImplemented());
        $this->assertContains('audit', app(ModuleAccess::class)->keys($this->client));
    }

    public function test_module_and_category_forms_are_validated(): void
    {
        $this->platformAdmin();
        $shared = ModuleCategory::where('key', 'shared')->firstOrFail();
        $shared2 = ModuleCategory::create(['key' => 'old', 'name' => 'Vieja', 'is_active' => false]);

        $this->post('/modules', ['name' => '', 'module_category_id' => $shared->id])->assertSessionHasErrors('name');
        $this->post('/modules', ['name' => 'Nuevo'])->assertSessionHasErrors('module_category_id');
        $this->post('/modules', ['name' => 'Nuevo', 'module_category_id' => 999999])->assertSessionHasErrors('module_category_id');
        $this->post('/modules', ['name' => 'Nuevo', 'module_category_id' => $shared2->id])->assertSessionHasErrors('module_category_id');
        $this->post('/module-categories', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/module-categories', ['name' => 'Salud'])->assertSessionHasErrors('name');
        $this->put("/module-categories/{$shared->id}", ['name' => 'Salud'])->assertSessionHasErrors('name');
        $this->put("/module-categories/{$shared->id}", ['name' => 'Compartidos', 'description' => 'x'])->assertSessionHasNoErrors();

        $this->assertSame('shared', $shared->fresh()->key);
        $this->assertSame('Compartidos', $shared->fresh()->name);
    }

    public function test_a_module_in_use_cannot_be_deactivated_but_an_unused_one_can(): void
    {
        $this->platformAdmin();
        $audit = Module::where('key', 'audit')->firstOrFail();

        $this->post("/modules/{$audit->id}/deactivate")->assertSessionHasErrors('status');
        $this->assertTrue($audit->fresh()->is_active);

        $unused = app(ModuleCatalog::class)->createModule(['name' => 'Futuro', 'module_category_id' => ModuleCategory::where('key', 'delivery')->value('id')]);
        $this->post("/modules/{$unused->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($unused->fresh()->is_active);
        $this->post("/modules/{$unused->id}/activate")->assertSessionHasNoErrors();
        $this->assertTrue($unused->fresh()->is_active);
    }

    public function test_a_deactivated_module_cannot_be_added_to_a_plan_but_may_stay_where_it_is(): void
    {
        $unused = app(ModuleCatalog::class)->createModule(['name' => 'Futuro', 'module_category_id' => ModuleCategory::where('key', 'delivery')->value('id')]);
        $plan = $this->plan('Con futuro', ['dashboard']);
        $plan->modules()->attach($unused->id);
        $unused->update(['is_active' => false]);
        $this->platformAdmin();

        $this->put("/plans/{$plan->id}/modules", ['module_ids' => [$this->moduleId('dashboard'), $unused->id, $this->moduleId('audit')]])->assertSessionHasNoErrors();
        $other = $this->plan('Otro');
        $this->put("/plans/{$other->id}/modules", ['module_ids' => [$unused->id]])->assertSessionHasErrors('module_ids');
        $this->assertSame(0, $other->modules()->count());
    }

    public function test_a_category_can_be_deactivated_without_changing_its_modules(): void
    {
        $this->platformAdmin();
        $shared = ModuleCategory::where('key', 'shared')->firstOrFail();

        $this->post("/module-categories/{$shared->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($shared->fresh()->is_active);
        $this->assertSame(8, $shared->modules()->where('is_active', true)->count());
        $this->post("/module-categories/{$shared->id}/activate")->assertSessionHasNoErrors();
        $this->assertTrue($shared->fresh()->is_active);
    }

    public function test_the_catalog_reports_implementation_from_code_and_usage_by_plans(): void
    {
        app(ModuleCatalog::class)->createModule(['name' => 'Futuro', 'module_category_id' => ModuleCategory::where('key', 'health')->value('id')]);
        $this->platformAdmin();

        $this->get('/plans')->assertInertia(function (AssertableInertia $page) {
            $catalog = collect($page->toArray()['props']['catalog']);
            $modules = $catalog->flatMap(fn ($category) => $category['modules'])->keyBy('key');

            $this->assertTrue($modules['dashboard']['implemented']);
            $this->assertSame('dashboard', $modules['dashboard']['route']);
            $this->assertSame(1, $modules['dashboard']['plansCount']);
            $this->assertFalse($modules['futuro']['implemented']);
            $this->assertNull($modules['futuro']['route']);
            $this->assertSame(0, $modules['futuro']['plansCount']);
            $this->assertSame('Futuro', $catalog->firstWhere('key', 'health')['modules'][0]['name']);
        });
    }

    // --- assigning plans to Workspaces ----------------------------------------------------------------------------------

    public function test_a_plan_is_assigned_to_a_client_workspace_and_restricts_it(): void
    {
        $plan = $this->plan('Solo reportes', ['reports']);
        $this->platformAdmin();

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $plan->id])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame($plan->id, $this->client->fresh()->plan_id);
        $this->assertSame(['reports'], app(ModuleAccess::class)->keys($this->client->fresh()));
    }

    public function test_assignment_is_validated_and_refuses_inactive_plans_and_the_administrative_workspace(): void
    {
        $inactive = $this->plan('Apagado', ['reports'], active: false);
        $active = $this->plan('Activo', ['reports']);
        $this->platformAdmin();

        $this->put("/workspaces/{$this->client->id}/plan", [])->assertSessionHasErrors('plan_id');
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => 999999])->assertSessionHasErrors('plan_id');
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $inactive->id])->assertSessionHasErrors('plan_id');
        $this->put("/workspaces/{$this->dev->id}/plan", ['plan_id' => $active->id])->assertSessionHasErrors('workspace_id');
        $this->put('/workspaces/999999/plan', ['plan_id' => $active->id])->assertNotFound();

        $this->assertSame(Plan::where('key', 'full_platform')->value('id'), $this->client->fresh()->plan_id);
        $this->assertNull($this->dev->fresh()->plan_id);
    }

    public function test_the_administrative_workspace_keeps_every_module_whatever_plan_is_forced_on_it(): void
    {
        $plan = $this->plan('Mínimo', ['reports']);
        $this->dev->forceFill(['plan_id' => $plan->id])->save();

        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), app(ModuleAccess::class)->keys($this->dev->fresh()));
    }

    public function test_workspaces_without_a_plan_show_up_under_the_default_plan(): void
    {
        $default = Plan::where('key', 'full_platform')->firstOrFail();
        Workspace::whereKey($this->client->id)->update(['plan_id' => null]);
        $this->platformAdmin();

        $this->get("/plans/{$default->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaces.meta.total', 1)->where('workspaces.data.0.code', 'CLIENTE_UNO')->where('workspaces.data.0.explicit', false));
    }

    public function test_the_workspaces_of_a_plan_are_paginated_on_the_server(): void
    {
        $plan = $this->plan('Grande', ['dashboard']);
        foreach (range(1, 14) as $i) {
            Workspace::create(['organization_id' => $this->organization->id, 'plan_id' => $plan->id, 'code' => "CLIENTE_{$i}", 'name' => "Cliente {$i}"]);
        }
        $this->platformAdmin();

        $this->get("/plans/{$plan->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces.data', 10)->where('workspaces.meta.total', 14)->where('plan.workspacesCount', 14));
        $this->get("/plans/{$plan->id}?workspaces_page=2&workspaces_per_page=10")->assertInertia(fn (AssertableInertia $page) => $page->has('workspaces.data', 4));
    }

    // --- who may administer ----------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function adminRoutes(): array
    {
        return [
            'plans index' => ['get', '/plans'],
            'plan page' => ['get', '/plans/1'],
            'create plan' => ['post', '/plans'],
            'edit plan' => ['put', '/plans/1'],
            'plan modules' => ['put', '/plans/1/modules'],
            'activate plan' => ['post', '/plans/1/activate'],
            'deactivate plan' => ['post', '/plans/1/deactivate'],
            'assign plan' => ['put', '/workspaces/2/plan'],
            'create module' => ['post', '/modules'],
            'edit module' => ['put', '/modules/1'],
            'activate module' => ['post', '/modules/1/activate'],
            'deactivate module' => ['post', '/modules/1/deactivate'],
            'create category' => ['post', '/module-categories'],
            'edit category' => ['put', '/module-categories/1'],
            'activate category' => ['post', '/module-categories/1/activate'],
            'deactivate category' => ['post', '/module-categories/1/deactivate'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_client_workspace_admin_cannot_reach_the_commercial_administration(string $method, string $uri): void
    {
        $this->actIn($this->client, 'admin');
        $plan = $this->plan('Propio', ['dashboard']);

        $this->{$method}($this->realIds($uri), ['name' => 'X', 'plan_id' => $plan->id, 'module_category_id' => 1])->assertForbidden();
        $this->assertSame(0, Plan::where('name', 'X')->count());
        $this->assertSame(Plan::where('key', 'full_platform')->value('id'), $this->client->fresh()->plan_id);
    }

    #[DataProvider('adminRoutes')]
    public function test_a_superuser_inside_a_client_workspace_cannot_reach_it_either(string $method, string $uri): void
    {
        $this->actIn($this->client, 'admin', superuser: true);

        $this->{$method}($this->realIds($uri), ['name' => 'X'])->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_an_admin_role_without_superuser_inside_the_administrative_workspace_is_refused(string $method, string $uri): void
    {
        $this->actIn($this->dev, 'admin');

        $this->{$method}($this->realIds($uri), ['name' => 'X'])->assertForbidden();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/plans')->assertRedirect();
        $this->post('/plans', ['name' => 'X'])->assertRedirect();
        $this->assertSame(1, Plan::count());
    }

    public function test_the_platform_flag_and_menu_entry_only_exist_for_the_platform_administration(): void
    {
        $this->actIn($this->client, 'admin', superuser: true);
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('platform', false));

        $this->platformAdmin();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('platform', true)->has('modules', 18));
    }

    // --- audit ---------------------------------------------------------------------------------------------------------------

    public function test_every_commercial_change_is_audited(): void
    {
        $this->platformAdmin();

        $this->post('/plans', ['name' => 'Auditado', 'module_ids' => [$this->moduleId('dashboard')]]);
        $plan = Plan::where('name', 'Auditado')->firstOrFail();
        $this->put("/plans/{$plan->id}/modules", ['module_ids' => [$this->moduleId('dashboard'), $this->moduleId('audit')]]);
        $this->post("/plans/{$plan->id}/deactivate");
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $this->plan('Otro plan', ['dashboard'])->id]);
        $this->post('/module-categories', ['name' => 'Nueva']);

        $this->assertTrue(AuditLog::where('resource_type', 'plan')->where('action', 'created')->exists());
        $this->assertTrue(AuditLog::where('resource_type', 'plan')->where('action', 'updated')->get()->flatMap(fn ($log) => $log->changes)
            ->contains(fn ($change) => $change['field'] === 'Módulos' && $change['before'] === 'Dashboard' && $change['after'] === 'Auditoría, Dashboard'));
        $this->assertTrue(AuditLog::where('resource_type', 'plan')->get()->flatMap(fn ($log) => $log->changes)->contains(fn ($change) => $change['field'] === 'Activo' && $change['after'] === 'No'));
        $this->assertTrue(AuditLog::where('resource_type', 'workspace')->where('workspace_code', 'CLIENTE_UNO')->get()->flatMap(fn ($log) => $log->changes)->contains(fn ($change) => $change['field'] === 'Plan'));
        $this->assertTrue(AuditLog::where('resource_type', 'module_category')->where('action', 'created')->exists());
    }
}
