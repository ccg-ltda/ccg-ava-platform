<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Database\Seeders\ModuleCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * "A module exists" is not enough: it must be reachable from the interface. A module is usable from the screen only when its code
 * (config/modules.php `implemented`), its catalog row (ModuleCatalogSeeder), its permissions in the database (RolesAndPermissionsSeeder)
 * and its menu entry (resources/js/config/navigation.js) all line up. These tests fail the day a new module is implemented without one of them,
 * which is what left Sedes, Catálogo and Pedidos invisible in Desarrollo_DEV. They also prove that the administrative Workspace gets every module WITHOUT
 * opening the others: a client plan, a role without permission and another Workspace stay closed.
 */
class ModuleRegistrationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    /** Modules with no page of their own in the menu (they are configured inside another one). */
    private const NO_MENU = ['calendar_providers'];

    private Workspace $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', []);
        $this->dev->update(['plan_id' => null]);
    }

    private function member(Workspace $workspace, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    /** @return list<string> the module keys the sidebar can offer, read from the SAME file the frontend uses */
    private function menuModules(): array
    {
        preg_match_all("/module: '([a-z_]+)'/", file_get_contents(base_path('resources/js/config/navigation.js')), $found);

        return array_values(array_unique($found[1]));
    }

    public function test_every_implemented_module_is_complete_code_route_permission_and_menu(): void
    {
        $menu = $this->menuModules();

        foreach (config('modules.implemented') as $key => $module) {
            $this->assertTrue(Route::has($module['route']), "{$key}: its route {$module['route']} does not exist");
            $this->assertArrayHasKey($module['permission'], config('permissions.permissions'), "{$key}: its permission {$module['permission']} is not in config/permissions.php");
            $this->assertTrue(Permission::where('name', $module['permission'])->exists(), "{$key}: its permission {$module['permission']} is not created by RolesAndPermissionsSeeder");
            $this->assertTrue(in_array($key, $menu, true) || in_array($key, self::NO_MENU, true), "{$key}: no menu entry in navigation.js (declare it, or list it as NO_MENU on purpose)");
        }

        // And the other way round: the menu never names a module that does not exist.
        $this->assertSame([], array_values(array_diff($menu, array_keys(config('modules.implemented')))));
    }

    public function test_the_catalog_seeder_registers_every_module_once_and_can_run_again(): void
    {
        // Start from a catalog that lacks the newest modules (what a database migrated before them looks like).
        DB::table('module_plan')->whereIn('module_id', Module::whereIn('key', ['locations', 'product_catalog', 'orders'])->pluck('id'))->delete();
        Module::whereIn('key', ['locations', 'product_catalog', 'orders'])->delete();
        $this->assertSame(15, Module::count());
        $this->seed(ModuleCatalogSeeder::class);
        $first = Module::orderBy('key')->get(['key', 'name', 'module_category_id'])->toArray();

        $this->seed(ModuleCatalogSeeder::class);
        $this->seed(ModuleCatalogSeeder::class);

        $this->assertSame($first, Module::orderBy('key')->get(['key', 'name', 'module_category_id'])->toArray(), 'running it again changes nothing');
        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), Module::pluck('key')->all());
        $this->assertSame(Module::count(), Module::distinct('key')->count('key'), 'no duplicated rows');

        // A name an administrator edited is never overwritten by the seeder.
        Module::where('key', 'orders')->update(['name' => 'Mis pedidos']);
        $this->seed(ModuleCatalogSeeder::class);
        $this->assertSame('Mis pedidos', Module::where('key', 'orders')->value('name'));
    }

    public function test_the_permission_seeder_gives_admin_every_client_permission_and_nobody_else_gains_any(): void
    {
        $roles = fn () => Role::with('permissions')->get()->mapWithKeys(fn ($role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])->all();
        $before = $roles();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($before, $roles(), 'running it again changes no role');
        $client = array_values(array_diff(array_keys(config('permissions.permissions')), RolesAndPermissionsSeeder::reserved()));
        $this->assertEqualsCanonicalizing($client, Role::findByName('admin', 'web')->permissions->pluck('name')->all());
        foreach (['view-catalog', 'create-products', 'edit-products', 'activate-products', 'view-orders', 'view-order-details', 'create-orders', 'edit-orders', 'cancel-orders', 'process-orders'] as $permission) {
            $this->assertFalse(Role::findByName('cliente', 'web')->hasPermissionTo($permission));
            $this->assertFalse(Role::findByName('supervisor', 'web')->hasPermissionTo($permission));
            $this->assertFalse(Role::findByName('agente', 'web')->hasPermissionTo($permission));
        }
    }

    public function test_the_administrative_workspace_has_every_module_and_its_admin_can_open_each_one(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), app(ModuleAccess::class)->keys($this->dev));
        $this->member($this->dev);

        foreach (config('modules.implemented') as $key => $module) {
            $this->get(route($module['route']))->assertOk();
        }

        // What the sidebar receives is what the backend allows: every module, and the menu is built from those same keys.
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('modules', fn ($modules) => collect($modules)->sort()->values()->all() === collect(array_keys(config('modules.implemented')))->sort()->values()->all()));
    }

    public function test_being_the_administrative_workspace_opens_nothing_for_users_without_permission_or_for_other_workspaces(): void
    {
        $client = $this->bookingWorkspace('CLIENTE_UNO', ['dashboard']);
        $newPages = ['locations.index', 'catalog.index', 'orders.index'];

        // A role without the permission is closed even inside the administrative Workspace.
        $this->member($this->dev, 'cliente');
        foreach ($newPages as $name) {
            $this->get(route($name))->assertForbidden();
        }

        // A client whose plan lacks the modules is closed even for its administrator; the administrative status is not inherited.
        $this->member($client);
        foreach ($newPages as $name) {
            $this->get(route($name))->assertForbidden();
        }
        $this->assertSame(['dashboard'], app(ModuleAccess::class)->keys($client->fresh()));

        // Handing the modules to that client's plan is what opens them there: the plan, never the status of Desarrollo_DEV.
        $this->contractModules($client, ['dashboard', 'service_catalog', 'locations', 'product_catalog', 'orders']);
        $this->member($client);
        foreach ($newPages as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_the_diagnosis_names_a_module_or_permission_left_out_of_the_database(): void
    {
        Artisan::call('integrations:diagnose');
        $healthy = Artisan::output();
        $this->assertStringContainsString('Todos los módulos implementados están en el catálogo.', $healthy);
        $this->assertStringContainsString('Todos los permisos del sistema existen.', $healthy);

        Module::where('key', 'orders')->delete();
        Permission::where('name', 'view-orders')->delete();
        Artisan::call('integrations:diagnose');
        $output = Artisan::output();

        $this->assertStringContainsString('que faltan en el catálogo (orders)', $output);
        $this->assertStringContainsString('ModuleCatalogSeeder', $output);
        $this->assertStringContainsString('Permisos que faltan (view-orders)', $output);
        $this->assertStringContainsString('RolesAndPermissionsSeeder', $output);
    }

    public function test_the_diagnosis_reports_plans_without_changing_them(): void
    {
        $this->seed(ModuleCatalogSeeder::class);
        $default = Plan::where('key', 'full_platform')->firstOrFail();

        Artisan::call('integrations:diagnose');
        $healthy = Artisan::output();
        $this->assertStringContainsString('El plan por defecto tiene los 8 módulos compartidos', $healthy);
        $this->assertStringContainsString('Ningún Workspace usa un plan desactivado.', $healthy);
        $this->assertStringContainsString('Ningún plan tiene dependencias incompletas.', $healthy);

        // Drift of the default plan (an administrator added modules) is reported as a pending decision, never fixed.
        $default->modules()->attach(Module::where('key', 'customers')->value('id'));
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('tiene 9 módulos y el código lo crea con 8; de más (customers)', Artisan::output());
        $this->assertSame(9, $default->modules()->count());

        // An incomplete plan and a client Workspace on an inactive plan are named by id.
        $incomplete = Plan::create(['name' => 'Incompleto']);
        $incomplete->modules()->attach(Module::where('key', 'campaigns')->value('id'));
        $off = Plan::create(['name' => 'Apagado', 'is_active' => false]);
        $client = $this->bookingWorkspace('CLIENTE_X', []);
        $client->update(['plan_id' => $off->id]);

        $this->assertSame(1, Artisan::call('integrations:diagnose'));
        $output = Artisan::output();
        $this->assertStringContainsString("Planes con dependencias incompletas (no se pueden asignar): plan(es) {$incomplete->id}", $output);
        $this->assertStringContainsString("Workspaces con plan desactivado (sin módulos): plan(es) {$off->id}", $output);
    }

    public function test_the_diagnosis_blocks_when_the_default_plan_is_missing_or_off(): void
    {
        $this->seed(ModuleCatalogSeeder::class);
        $default = Plan::where('key', 'full_platform')->firstOrFail();

        $default->update(['is_active' => false]);
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('El plan por defecto está desactivado', Artisan::output());

        $default->modules()->detach();
        Workspace::whereNotNull('plan_id')->update(['plan_id' => null]);
        $default->delete();
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('No existe el plan por defecto (full_platform)', Artisan::output());
    }
}
