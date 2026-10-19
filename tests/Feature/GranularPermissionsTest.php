<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Permissions are granular and independent: each operation of a resource has its own permission, holding one never grants another, and the module
 * of the plan and the permission of the role are two separate conditions that must both hold. The checks run against the real routes.
 */
class GranularPermissionsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $without;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'customers', 'campaigns', 'locations']);
        $this->without = $this->bookingWorkspace('SOLOSALUD', ['dashboard', 'customers']);
    }

    private function customer(Workspace $workspace): Customer
    {
        // A phone number is unique per Workspace: each record gets its own.
        static $n = 0;

        return $workspace->customers()->create(['first_name' => 'Ana', 'phone' => '+5730011'.str_pad((string) ++$n, 5, '0', STR_PAD_LEFT), 'source' => 'manual', 'marketing_consent' => 'unknown'])->fresh();
    }

    private function customerPayload(): array
    {
        return ['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+57 311 222 3344', 'email' => 'laura@example.com', 'source' => 'manual', 'marketing_consent' => 'unknown'];
    }

    /** @return array<string, array{0: string, 1: callable}> operation => [its permission, the request that attempts it] */
    private function customerOperations(Customer $customer): array
    {
        return [
            'list' => ['view-customers', fn () => $this->get('/customers')],
            'detail' => ['view-customer-details', fn () => $this->get("/customers/{$customer->id}")],
            'create' => ['create-customers', fn () => $this->post('/customers', $this->customerPayload())],
            'edit' => ['edit-customers', fn () => $this->put("/customers/{$customer->id}", $this->customerPayload())],
            'toggle' => ['activate-customers', fn () => $this->post("/customers/{$customer->id}/deactivate")],
            'anonymize' => ['anonymize-customers', fn () => $this->post("/customers/{$customer->id}/anonymize")],
        ];
    }

    /** @return array<string, array{string}> */
    public static function customerOperationNames(): array
    {
        return collect(['list', 'detail', 'create', 'edit', 'toggle', 'anonymize'])->mapWithKeys(fn (string $name) => [$name => [$name]])->all();
    }

    #[DataProvider('customerOperationNames')]
    public function test_a_role_with_one_operation_can_do_exactly_that_one_and_open_the_list(string $operation): void
    {
        $customer = $this->customer($this->workspace);
        [$permission] = $this->customerOperations($customer)[$operation];
        Role::findOrCreate('solo', 'web')->syncPermissions(['view-dashboard', 'view-customers', $permission]);
        $this->actAsMember($this->workspace, 'solo');

        foreach (array_keys($this->customerOperations($customer)) as $name) {
            // Each attempt may change the record: rebuild it so a previous one does not decide the next.
            $customer = $this->customer($this->workspace);
            $status = $this->customerOperations($customer)[$name][1]()->getStatusCode();
            $allowed = in_array($name, ['list', $operation], true);

            $allowed
                ? $this->assertNotSame(403, $status, "[{$operation}] must be able to {$name}")
                : $this->assertSame(403, $status, "[{$operation}] must NOT be able to {$name}");
        }
    }

    public function test_an_operation_permission_alone_opens_nothing_because_the_list_is_its_page(): void
    {
        $customer = $this->customer($this->workspace);
        Role::findOrCreate('sin-lista', 'web')->syncPermissions(['view-dashboard', 'create-customers', 'edit-customers', 'activate-customers', 'anonymize-customers', 'view-customer-details']);
        $this->actAsMember($this->workspace, 'sin-lista');

        foreach ($this->customerOperations($customer) as $name => [, $attempt]) {
            $this->assertSame(403, $attempt()->getStatusCode(), "{$name} needs the permission that opens the page");
        }
    }

    public function test_the_agent_example_can_consult_and_create_but_neither_edit_nor_remove(): void
    {
        $customer = $this->customer($this->workspace);
        Role::findOrCreate('agente-clientes', 'web')->syncPermissions(['view-dashboard', 'view-customers', 'view-customer-details', 'create-customers']);
        $this->actAsMember($this->workspace, 'agente-clientes');

        $this->get('/customers')->assertOk();
        $this->get("/customers/{$customer->id}")->assertOk();
        $this->post('/customers', $this->customerPayload())->assertSessionHasNoErrors();
        $this->put("/customers/{$customer->id}", $this->customerPayload())->assertForbidden();
        $this->post("/customers/{$customer->id}/deactivate")->assertForbidden();
        $this->post("/customers/{$customer->id}/anonymize")->assertForbidden();
        $this->assertSame('Ana', $customer->fresh()->first_name);
    }

    public function test_the_interface_only_offers_what_the_role_can_do(): void
    {
        $customer = $this->customer($this->workspace);
        Role::findOrCreate('lectura', 'web')->syncPermissions(['view-dashboard', 'view-customers']);
        $this->actAsMember($this->workspace, 'lectura');

        $this->get('/customers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('view-customers')
                && ! collect($permissions)->contains('view-customer-details')
                && ! collect($permissions)->contains('create-customers')
                && ! collect($permissions)->contains('edit-customers')));
        $this->get("/customers/{$customer->id}")->assertForbidden();
    }

    public function test_the_plan_and_the_permission_are_separate_conditions_that_both_must_hold(): void
    {
        $all = collect(config('permissions.permissions'))->reject(fn ($p) => $p['platform'] ?? false)->keys()->all();

        // The module is NOT in the plan: even a role with every permission is refused, for pages and for operations.
        Role::findOrCreate('todo', 'web')->syncPermissions($all);
        $this->actAsMember($this->without, 'todo');
        $this->get('/campaigns')->assertForbidden();
        $this->get('/locations')->assertForbidden();
        $this->post('/campaigns', ['name' => 'X'])->assertForbidden();
        $this->post('/locations', ['name' => 'X'])->assertForbidden();

        // The module IS in the plan: a role without the permission is refused.
        Role::findOrCreate('nada', 'web')->syncPermissions(['view-dashboard']);
        $this->actAsMember($this->workspace, 'nada');
        $this->get('/campaigns')->assertForbidden();
        $this->get('/locations')->assertForbidden();
        $this->post('/locations', ['name' => 'X'])->assertForbidden();

        // Both hold: the page opens.
        $this->actAsMember($this->workspace, 'todo');
        $this->get('/campaigns')->assertOk();
        $this->get('/locations')->assertOk();
    }

    public function test_the_shared_modules_of_a_plan_decide_what_the_menu_and_the_quick_links_can_offer(): void
    {
        $all = collect(config('permissions.permissions'))->reject(fn ($p) => $p['platform'] ?? false)->keys()->all();
        Role::findOrCreate('todo', 'web')->syncPermissions($all);

        $this->actAsMember($this->without, 'todo');
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('modules', fn ($modules) => ! collect($modules)->contains('locations') && ! collect($modules)->contains('campaigns') && collect($modules)->contains('customers')));

        $this->actAsMember($this->workspace, 'todo');
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('modules', fn ($modules) => collect($modules)->contains('locations') && collect($modules)->contains('campaigns')));
    }

    public function test_a_permission_of_another_workspace_gives_nothing_here_and_its_records_do_not_exist(): void
    {
        $foreign = $this->customer($this->without);
        Role::findOrCreate('editor', 'web')->syncPermissions(['view-dashboard', 'view-customers', 'view-customer-details', 'edit-customers', 'anonymize-customers']);
        $this->actAsMember($this->workspace, 'editor');

        $this->get("/customers/{$foreign->id}")->assertNotFound();
        $this->put("/customers/{$foreign->id}", $this->customerPayload())->assertNotFound();
        $this->post("/customers/{$foreign->id}/anonymize")->assertNotFound();
        $this->assertSame('Ana', $foreign->fresh()->first_name);
    }

    // --- the catalog says exactly what the system enforces --------------------------------------------------------------

    /** @return list<string> every permission some route requires (`workspace.permission:`) */
    private function permissionsRequiredByRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'workspace.permission:'))
            ->flatMap(fn (string $middleware) => explode(',', substr($middleware, strlen('workspace.permission:'))))
            ->unique()->values()->all();
    }

    public function test_every_permission_of_the_catalog_is_enforced_by_a_route_or_by_code_so_none_is_fictitious(): void
    {
        $routes = $this->permissionsRequiredByRoutes();
        $code = collect(['app', 'config/modules.php'])->flatMap(function (string $path) {
            $full = base_path($path);

            return is_dir($full)
                ? collect(Finder::create()->files()->in($full)->name('*.php')->getIterator())->map(fn ($file) => $file->getContents())->all()
                : [file_get_contents($full)];
        })->implode("\n");

        foreach (array_keys(config('permissions.permissions')) as $permission) {
            $used = in_array($permission, $routes, true) || str_contains($code, "'{$permission}'");
            $this->assertTrue($used, "[{$permission}] is in the catalog but nothing enforces or reads it");
        }
    }

    public function test_the_catalog_has_no_broad_manage_permission_left_over_from_before_the_split(): void
    {
        foreach (['manage-settings', 'manage-chatbots', 'manage-bookings', 'manage-services', 'manage-customers', 'manage-campaigns', 'manage-catalog', 'manage-orders'] as $retired) {
            $this->assertArrayNotHasKey($retired, config('permissions.permissions'));
            $this->assertNotContains($retired, $this->permissionsRequiredByRoutes(), "a route still requires the retired [{$retired}]");
        }
    }

    public function test_every_operation_of_a_resource_needs_the_permission_that_opens_its_page_declared_in_the_catalog(): void
    {
        foreach (config('permissions.permissions') as $name => $permission) {
            $list = config("permissions.groups.{$permission['group']}.list");

            if ($list !== null) {
                $this->assertArrayHasKey($list, config('permissions.permissions'), "[{$name}] points to a list permission that does not exist");
            }
        }
    }

    public function test_the_sidebar_and_the_quick_links_are_the_registry_and_not_a_second_list(): void
    {
        $navigation = file_get_contents(resource_path('js/config/navigation.js'));
        preg_match_all("/route: '([a-z.]+)', icon: \w+(?:, permission: '([a-z-]+)')?, module: '([a-z_]+)'/", $navigation, $items, PREG_SET_ORDER);

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            [$route, $permission, $module] = [$item[1], $item[2] ?? '', $item[3]];
            $registry = config("modules.implemented.{$module}");
            $this->assertNotNull($registry, "the menu names an unknown module [{$module}]");
            $this->assertSame($registry['route'], $route, "[{$module}] route differs between the menu and config/modules.php");

            if ($permission !== '') {
                $this->assertSame($registry['permission'], $permission, "[{$module}] permission differs between the menu and config/modules.php");
            }
        }

        // The quick links of the Dashboard are drawn from the very same definition (no list of their own).
        $panels = file_get_contents(resource_path('js/Pages/Dashboard/Partials/Panels.jsx'));
        $this->assertStringContainsString('visibleNavigation(useAccess())', $panels);
    }

    public function test_the_menu_sections_of_the_catalog_are_the_sections_of_the_sidebar(): void
    {
        $navigation = file_get_contents(resource_path('js/config/navigation.js'));
        preg_match_all("/label: '([^']+)',\n        items: \[(.*?)\n        \],/s", $navigation, $sections, PREG_SET_ORDER);

        $fromMenu = [];
        foreach ($sections as [, $label, $block]) {
            preg_match_all("/module: '([a-z_]+)'/", $block, $modules);
            foreach ($modules[1] as $module) {
                $fromMenu[$module] = $label;
            }
        }

        $this->assertNotEmpty($fromMenu);
        foreach ($fromMenu as $module => $label) {
            $section = config('modules.menu.'.$module);
            $this->assertNotNull($section, "[{$module}] has no section in config/modules.php `menu`");
            $this->assertSame($label, config("modules.menu_sections.{$section}.name"), "[{$module}] sits in a different section in the sidebar than in the catalog");
        }

        // A module without a page of its own (calendar_providers) is still placed; nothing in `menu` is unknown.
        foreach (array_keys(config('modules.menu')) as $module) {
            $this->assertArrayHasKey($module, config('modules.implemented'));
        }
        foreach (array_keys(config('modules.implemented')) as $module) {
            $this->assertArrayHasKey($module, config('modules.menu'), "[{$module}] is implemented but has no menu section");
        }
    }
}
