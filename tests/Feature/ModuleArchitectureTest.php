<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * How modules, categories, navigation groups, plans and permissions fit together: a plan combines modules of any category, the
 * dependencies are enforced when a plan is saved AND when it is applied, nothing outside the plan is reachable by URL, and the
 * pieces that are not contractable are declared, not accidental.
 */
class ModuleArchitectureTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', []);
        $this->dev->update(['plan_id' => null]);
        $this->client = $this->bookingWorkspace('CLIENTE_UNO', ['dashboard']);
    }

    private function platformAdmin(): User
    {
        $user = $this->member($this->dev, 'plataforma');
        $this->actingAs($user)->withSession(['workspace_id' => $this->dev->id]);

        return $user;
    }

    private function ids(array $keys): array
    {
        return Module::whereIn('key', $keys)->pluck('id')->all();
    }

    // --- every page is a module page or a declared core page --------------------------------------------------------

    public function test_every_signed_in_route_is_behind_a_module_or_declared_as_core(): void
    {
        $undeclared = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route) => ! Str::startsWith($route->uri(), ['api/', '_', 'up', 'horizon', 'storage', 'sanctum', 'book/']))
            ->filter(fn (LaravelRoute $route) => collect($route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || Str::startsWith($middleware, 'auth:'))))
            ->reject(fn (LaravelRoute $route) => $this->hasModule($route))
            ->reject(fn (LaravelRoute $route) => $this->isCore($route, config('modules.core_routes')))
            ->map(fn (LaravelRoute $route) => $route->methods()[0].' /'.$route->uri().' ['.$route->getName().']')
            ->values()->all();

        $this->assertSame([], $undeclared, 'a route outside the plan must be a declared core route (config/modules.php core_routes) or sit behind workspace.module');
    }

    public function test_core_routes_are_not_modules_and_the_platform_ones_need_the_platform_administration(): void
    {
        $keys = array_keys(config('modules.implemented'));

        foreach (config('modules.core_routes') as $pattern) {
            $this->assertNotContains($pattern, $keys, 'a core route is not a catalog module');
            $matching = collect(Route::getRoutes()->getRoutes())->filter(fn (LaravelRoute $route) => $this->isCore($route, [$pattern]));
            $this->assertNotEmpty($matching, "core route pattern {$pattern} matches a route");
            $matching->each(fn (LaravelRoute $route) => $this->assertFalse($this->hasModule($route), "{$route->getName()} must not depend on a plan"));
        }

        foreach (['plans.index', 'plans.store', 'modules.store', 'module-categories.store', 'workspaces.plan.update', 'support-access.store'] as $name) {
            $middleware = collect(Route::getRoutes()->getByName($name)->gatherMiddleware())->filter(fn ($m) => is_string($m))->implode('|');
            $this->assertMatchesRegularExpression('/workspace\.(platform|permission)/', $middleware, "{$name} is for the platform administration");
        }
    }

    /** A core pattern names a route (`profile.*`) or, for the few unnamed ones, its URI (`confirm-password`). */
    private function isCore(LaravelRoute $route, array $patterns): bool
    {
        return Str::is($patterns, (string) $route->getName()) || Str::is($patterns, $route->uri());
    }

    private function hasModule(LaravelRoute $route): bool
    {
        return collect($route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'workspace.module:'));
    }

    // --- plans combine modules of any category; dependencies are enforced on save AND on apply -----------------------

    public function test_a_plan_combines_modules_of_different_categories_and_groups_without_a_predefined_solution(): void
    {
        $this->platformAdmin();
        $keys = ['dashboard', 'conversations', 'customers', 'campaigns', 'service_catalog', 'professionals', 'bookings'];

        $this->post('/plans', ['name' => 'Servicios con campañas', 'module_ids' => $this->ids($keys)])->assertSessionHasNoErrors();
        $plan = Plan::where('name', 'Servicios con campañas')->firstOrFail();
        $categories = $plan->modules()->with('category')->get()->pluck('category.key')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['shared', 'communication', 'services_bookings'], $categories);

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $plan->id])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($keys, app(ModuleAccess::class)->keys($this->client->fresh()));
    }

    public function test_an_incomplete_plan_is_saved_with_a_warning_but_refused_when_applied_to_a_workspace(): void
    {
        $this->platformAdmin();

        // When saved: allowed (warned about in Planes).
        $this->post('/plans', ['name' => 'Incompleto', 'module_ids' => $this->ids(['campaigns', 'bookings'])])->assertSessionHasNoErrors(); // E4: advertir, no bloquear
        $this->assertNotNull(Plan::where('name', 'Incompleto')->first());

        // A plan that was saved before a dependency existed is incomplete: it exists, but it can not be applied.
        $legacy = Plan::create(['name' => 'Anterior']);
        $legacy->modules()->sync($this->ids(['dashboard', 'campaigns']));
        $before = $this->client->fresh()->plan_id;

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $legacy->id])->assertSessionHasErrors('plan_id');
        $this->assertStringContainsString('Campañas', session('errors')->first('plan_id'));
        $this->assertStringContainsString('Clientes', session('errors')->first('plan_id'));
        $this->assertSame($before, $this->client->fresh()->plan_id, 'the Workspace keeps its plan');

        // Completing it makes it applicable.
        $this->put("/plans/{$legacy->id}/modules", ['module_ids' => $this->ids(['dashboard', 'customers', 'campaigns'])])->assertSessionHasNoErrors();
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $legacy->id])->assertSessionHasNoErrors();
        $this->assertSame($legacy->id, $this->client->fresh()->plan_id);
    }

    public function test_the_plan_list_marks_incomplete_plans_and_the_catalog_explains_the_requirements(): void
    {
        $this->platformAdmin();
        $legacy = Plan::create(['name' => 'Anterior']);
        $legacy->modules()->sync($this->ids(['dashboard', 'campaigns']));

        $props = $this->get('/plans')->assertOk()->viewData('page')['props'];
        $plan = collect($props['plans'])->firstWhere('name', 'Anterior');
        $this->assertSame('campaigns', $plan['unmet'][0]['key']);
        $this->assertSame(['customers'], $plan['unmet'][0]['missing']);

        $modules = collect($props['catalog'])->flatMap(fn ($category) => $category['modules']);
        $this->assertSame(['customers'], collect($modules->firstWhere('key', 'campaigns')['requires'])->pluck('key')->all());
        $this->assertSame([], $modules->firstWhere('key', 'customers')['requires'], 'Clientes needs nothing');
        $this->assertSame([], $modules->firstWhere('key', 'conversations')['requires']);
    }

    // --- independence of the capabilities --------------------------------------------------------------------------

    public function test_bookings_do_not_need_customers_and_customers_do_not_need_campaigns(): void
    {
        $this->contractModules($this->client, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->assertSame(['service_catalog', 'professionals'], config('modules.dependencies.bookings'), 'bookings never needed Clientes: they keep their own minimal contact data');
        $this->assertNotContains('customers', config('modules.dependencies.bookings'));
        $this->assertNotContains('customers', config('modules.dependencies.service_catalog') ?? []);

        $user = $this->member($this->client, 'admin');
        $this->actingAs($user)->withSession(['workspace_id' => $this->client->id]);
        $this->get('/bookings')->assertOk();
        $this->get('/customers')->assertForbidden();
        $this->get('/campaigns')->assertForbidden();

        // Clientes alone: usable, with no campaigns.
        $this->contractModules($this->client, ['dashboard', 'customers']);
        $this->get('/customers')->assertOk();
        $this->get('/campaigns')->assertForbidden();
        $this->get('/bookings')->assertForbidden();
    }

    public function test_the_customer_page_only_shows_what_the_plan_includes_and_never_fails_without_it(): void
    {
        $this->contractModules($this->client, ['dashboard', 'customers']);
        $user = $this->member($this->client, 'admin');
        $this->actingAs($user)->withSession(['workspace_id' => $this->client->id]);
        $customer = $this->client->customers()->create(['first_name' => 'Ana', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        $this->get("/customers/{$customer->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations', null)->where('appointments', null)->where('campaigns', null));

        $this->contractModules($this->client, ['dashboard', 'customers', 'conversations', 'service_catalog', 'professionals', 'bookings', 'campaigns']);
        $this->get("/customers/{$customer->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversations', 0)->has('appointments', 0)->has('campaigns', 0));
    }

    public function test_conversations_work_without_customers_and_the_assistant_still_needs_them(): void
    {
        $this->contractModules($this->client, ['dashboard', 'conversations']);
        $user = $this->member($this->client, 'admin');
        $this->actingAs($user)->withSession(['workspace_id' => $this->client->id]);

        $this->get('/conversations')->assertOk();
        $this->get('/customers')->assertForbidden();
        $this->assertSame([], app(ModuleAccess::class)->dependencies()['conversations'] ?? []);
    }

    // --- catalog, categories and groups can grow --------------------------------------------------------------------

    public function test_a_category_and_a_module_created_from_the_interface_reserve_their_place_but_enable_nothing(): void
    {
        $this->platformAdmin();

        $this->post('/module-categories', ['name' => 'Bienestar'])->assertSessionHasNoErrors();
        $category = ModuleCategory::where('name', 'Bienestar')->firstOrFail();
        $this->post('/modules', ['name' => 'Historia clínica', 'module_category_id' => $category->id])->assertSessionHasNoErrors();
        $module = Module::where('name', 'Historia clínica')->firstOrFail();

        $catalog = collect($this->get('/plans')->viewData('page')['props']['catalog'])->flatMap(fn ($c) => $c['modules'])->firstWhere('key', $module->key);
        $this->assertFalse($catalog['implemented']);
        $this->assertSame('solutions', $catalog['group']['key'], 'what the code does not place goes to the default group');

        $plan = Plan::create(['name' => 'Con pendiente']);
        $plan->modules()->sync([$module->id, ...$this->ids(['dashboard'])]);
        $this->client->update(['plan_id' => $plan->id]);

        $this->assertSame(['dashboard'], app(ModuleAccess::class)->keys($this->client->fresh()));
        $this->assertNotContains($module->key, array_keys(config('modules.implemented')));
    }

    public function test_no_access_decision_names_a_business_type(): void
    {
        // Categories and groups are labels. The code that decides what a Workspace may use never mentions a type of business.
        foreach (['app/Services/ModuleAccess.php', 'app/Http/Middleware/EnsureWorkspaceModule.php', 'app/Services/ModuleCatalog.php'] as $file) {
            $source = file_get_contents(base_path($file));

            foreach (['health', 'delivery', 'ecommerce', 'services_bookings', 'Salud', 'eCommerce'] as $type) {
                $this->assertStringNotContainsString($type, $source, "{$file} mentions {$type}");
            }
        }
    }

    // --- Desarrollo_DEV and the clients --------------------------------------------------------------------------------

    public function test_the_administrative_workspace_has_every_implemented_module_and_no_plan_while_clients_keep_theirs(): void
    {
        $access = app(ModuleAccess::class);

        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), $access->keys($this->dev->fresh()));
        $this->assertSame(['dashboard'], $access->keys($this->client->fresh()));

        $this->platformAdmin();
        $this->put("/workspaces/{$this->dev->id}/plan", ['plan_id' => Plan::first()->id])->assertSessionHasErrors('workspace_id');
        $this->assertNull($this->dev->fresh()->plan_id);
    }

    public function test_a_superuser_of_a_client_workspace_gets_no_platform_privilege_and_no_other_workspace(): void
    {
        $superuser = $this->member($this->client, 'admin');
        $superuser->forceFill(['is_superuser' => true])->save();
        $this->actingAs($superuser)->withSession(['workspace_id' => $this->client->id]);
        $plan = Plan::first();

        $this->get('/plans')->assertForbidden();
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $plan->id])->assertForbidden();
        $this->post('/module-categories', ['name' => 'X'])->assertForbidden();
        $this->post('/support-access', ['workspace_id' => $this->dev->id, 'data_type' => 'conversations', 'reason' => 'un motivo suficientemente largo'])->assertForbidden();
        $this->get("/dashboard?workspace={$this->dev->id}")->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('platform', false));
    }

    // --- plan versus permission ----------------------------------------------------------------------------------------

    public function test_the_plan_decides_if_a_module_exists_and_the_role_decides_what_a_user_does_in_it(): void
    {
        $this->contractModules($this->client, ['dashboard', 'customers']);

        // Module available, role without permission: nothing.
        $viewer = $this->member($this->client, 'cliente');
        $this->actingAs($viewer)->withSession(['workspace_id' => $this->client->id]);
        $this->get('/customers')->assertForbidden();

        // Permission without module: nothing either.
        $this->contractModules($this->client, ['dashboard']);
        $admin = $this->member($this->client, 'admin');
        $this->actingAs($admin)->withSession(['workspace_id' => $this->client->id]);
        $this->get('/customers')->assertForbidden();

        // Both: allowed; the menu data lists the module only then.
        $this->contractModules($this->client, ['dashboard', 'customers']);
        $this->get('/customers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('modules', fn ($modules) => collect($modules)->sort()->values()->all() === ['customers', 'dashboard']));
    }
}
