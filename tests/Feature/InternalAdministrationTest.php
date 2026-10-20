<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\PlatformAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The internal administration of Ava Platform (a superuser with the role `admin` inside Desarrollo_DEV, such as admin@ava.com) always has every
 * module and every permission, however the plan, the seeder, the cache or other roles change; nobody else gets any of it, and a client Workspace
 * stays limited by its plan and its roles. The catalog the role editor shows is also limited by who is looking.
 */
class InternalAdministrationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    private User $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        // The plan of the administrative Workspace is deliberately tiny: it must make no difference.
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', ['dashboard']);
        $this->client = $this->bookingWorkspace('CLIENTE', ['dashboard', 'customers']);
        $this->internal = User::factory()->create(['email' => 'admin@ava.com']);
        $this->internal->forceFill(['is_superuser' => true])->save();
    }

    private function signInInternal(): static
    {
        return $this->actingAs($this->internal)->withSession(['workspace_id' => $this->dev->id]);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> */
    private function everything(): array
    {
        return app(PlatformAccess::class)->internalAdministration();
    }

    /** The state of a database that was never seeded after the split: the old, broad catalog, assigned to `admin`. */
    private function oldDatabase(): void
    {
        $fine = collect(config('permissions.legacy'))->flatMap(fn (array $split) => $split['grants'])->unique()->all();
        $old = collect(array_keys(config('permissions.permissions')))->reject(fn (string $name) => in_array($name, $fine, true))
            ->merge(['manage-settings', 'manage-chatbots', 'manage-bookings', 'manage-services', 'manage-customers', 'manage-campaigns', 'manage-catalog', 'manage-orders', 'manage-plans', 'manage-modules'])
            ->reject(fn (string $name) => str_ends_with($name, '-plans') && $name !== 'manage-plans' || str_ends_with($name, '-modules') && $name !== 'manage-modules')
            ->unique()->values()->all();

        DB::table('role_has_permissions')->delete();
        DB::table('permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($old as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        Role::findByName('admin', 'web')->syncPermissions(array_values(array_diff($old, ['manage-plans', 'manage-modules', 'access-private-data'])));
    }

    // --- the modules and permissions never go missing -------------------------------------------------------------------------------------

    public function test_the_internal_administration_has_every_module_and_every_permission_whatever_the_plan(): void
    {
        $props = $this->signInInternal()->props('/dashboard');

        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), $props['modules']);
        $this->assertEqualsCanonicalizing($this->everything(), $props['auth']['user']['permissions']);
        $this->assertContains('view-plans', $props['auth']['user']['permissions']);
        $this->assertNotContains('access-private-data', $props['auth']['user']['permissions'], 'the support access keeps its own justified, temporary flow');

        foreach (config('modules.implemented') as $key => $module) {
            $this->get(route($module['route']))->assertOk();
        }
        $this->get(route('plans.index'))->assertOk();
    }

    public function test_the_menu_entries_that_vanished_after_the_split_are_back_even_on_a_database_that_was_never_seeded(): void
    {
        $this->oldDatabase();
        $this->assertDatabaseMissing('permissions', ['name' => 'view-settings']);

        $props = $this->signInInternal()->props('/dashboard');

        foreach (['view-settings', 'view-integrations', 'view-audit', 'view-services', 'view-professionals', 'view-locations', 'view-plans', 'view-modules'] as $permission) {
            $this->assertContains($permission, $props['auth']['user']['permissions'], "[{$permission}] is part of the internal administration");
        }
        foreach (['settings.index', 'integrations.index', 'audit.index', 'services.index', 'professionals.index', 'locations.index', 'bookings.index', 'customers.index', 'campaigns.index', 'orders.index', 'catalog.index', 'chatbots.index', 'conversations.index', 'users.index', 'plans.index', 'reports.index', 'dashboard'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_a_plan_change_a_seeder_run_and_a_cache_flush_take_nothing_away(): void
    {
        $this->signInInternal();
        $before = $this->props('/dashboard');

        $this->contractModules($this->dev, ['dashboard']);
        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class])->assertSuccessful();
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $this->props('/dashboard');
        $this->assertEqualsCanonicalizing($before['auth']['user']['permissions'], $after['auth']['user']['permissions']);
        $this->assertEqualsCanonicalizing($before['modules'], $after['modules']);
    }

    public function test_editing_other_roles_or_the_admin_role_rows_does_not_change_it(): void
    {
        foreach (['supervisor', 'agente', 'cliente', 'plataforma', 'soporte'] as $role) {
            Role::findByName($role, 'web')->syncPermissions([]);
        }
        Role::findByName('admin', 'web')->syncPermissions(['view-dashboard']);

        $props = $this->signInInternal()->props('/dashboard');

        $this->assertEqualsCanonicalizing($this->everything(), $props['auth']['user']['permissions']);
        $this->get(route('settings.index'))->assertOk();
    }

    // --- nobody else gets it ------------------------------------------------------------------------------------------------------------

    public function test_an_account_that_is_not_a_superuser_is_limited_by_its_role_even_inside_the_internal_workspace_and_whatever_its_email(): void
    {
        $other = User::factory()->create(['email' => 'admin@ava.com.co']);
        $lookalike = User::factory()->create(['email' => 'otro-admin@ava.com']);
        foreach ([$other, $lookalike] as $user) {
            $this->dev->users()->attach($user->id, ['role' => 'admin']);
        }
        Role::findByName('admin', 'web')->revokePermissionTo('edit-settings');

        foreach ([$other, $lookalike] as $user) {
            $permissions = $this->actingAs($user)->withSession(['workspace_id' => $this->dev->id])->props('/dashboard')['auth']['user']['permissions'];

            $this->assertNotContains('edit-settings', $permissions);
            $this->assertNotContains('view-plans', $permissions);
            $this->post('/settings', ['name' => 'X'])->assertForbidden();
            $this->get(route('plans.index'))->assertForbidden();
        }
    }

    public function test_the_flag_and_not_the_address_decides_who_is_the_internal_administration(): void
    {
        $this->internal->forceFill(['is_superuser' => false])->save();

        $this->actingAs($this->internal->fresh())->withSession(['workspace_id' => $this->dev->id])->get('/dashboard')->assertRedirect();
    }

    public function test_a_superuser_acting_with_another_role_keeps_that_role_and_only_adds_the_commercial_administration(): void
    {
        $this->dev->users()->attach($this->internal->id, ['role' => 'supervisor']);

        $permissions = $this->signInInternal()->props('/dashboard')['auth']['user']['permissions'];

        $this->assertEqualsCanonicalizing([...Role::findByName('supervisor', 'web')->permissions->pluck('name')->all(), ...app(PlatformAccess::class)->commercial()], $permissions);
        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('plans.index'))->assertOk();
    }

    public function test_inside_a_client_workspace_the_same_account_gets_nothing_extra_and_the_plan_still_limits_it(): void
    {
        $this->client->users()->attach($this->internal->id, ['role' => 'admin']);

        $props = $this->actingAs($this->internal)->withSession(['workspace_id' => $this->client->id])->props('/dashboard');

        $this->assertEqualsCanonicalizing(Role::findByName('admin', 'web')->permissions->pluck('name')->all(), $props['auth']['user']['permissions']);
        $this->assertEqualsCanonicalizing(['dashboard', 'customers'], $props['modules']);
        $this->get(route('plans.index'))->assertForbidden();
        $this->get(route('settings.index'))->assertForbidden(); // the module is not in the plan, though the role holds the permission
        $this->post('/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
        $this->post('/plans', ['name' => 'Nuevo'])->assertForbidden();
    }

    public function test_a_client_member_cannot_enter_the_internal_workspace_by_changing_the_session_or_the_request(): void
    {
        $member = User::factory()->create();
        $this->client->users()->attach($member->id, ['role' => 'admin']);

        $this->actingAs($member)->withSession(['workspace_id' => $this->dev->id])->get('/dashboard')->assertRedirect();
        $this->actingAs($member)->withSession(['workspace_id' => $this->client->id])->get('/dashboard?workspace=all')->assertForbidden();
        $this->get('/plans')->assertForbidden();
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => 1])->assertForbidden();
    }

    // --- what the role editor shows depends on who is looking ----------------------------------------------------------------------------------

    public function test_a_client_administrator_never_sees_a_platform_permission_in_any_list(): void
    {
        $withUsers = $this->bookingWorkspace('CLIENTEU', ['dashboard', 'users_roles']);
        $admin = User::factory()->create();
        $withUsers->users()->attach($admin->id, ['role' => 'admin']);

        $platform = collect(config('permissions.permissions'))->filter(fn (array $p) => $p['platform'] ?? false)->keys()->all();
        $this->assertNotEmpty($platform);

        $this->actingAs($admin)->withSession(['workspace_id' => $withUsers->id])
            ->get('/users?permissions_per_page=100&roles_per_page=100')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissionCatalog.items', fn ($items) => collect($items)->pluck('name')->intersect($platform)->isEmpty() && $items->count() === count(config('permissions.permissions')) - count($platform))
            ->where('permissionNames', fn ($names) => collect($names)->intersect($platform)->isEmpty())
            ->where('permissions.data', fn ($rows) => collect($rows)->pluck('name')->intersect($platform)->isEmpty())
            ->where('roles.data', fn ($roles) => collect($roles)->every(fn ($role) => collect($role['permissions'])->intersect($platform)->isEmpty())));
    }

    public function test_the_platform_administration_sees_the_whole_catalog_and_can_hand_out_platform_permissions(): void
    {
        $role = Role::findOrCreate('comercial', 'web');

        $this->signInInternal()->get('/users?permissions_per_page=100')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissionNames', fn ($names) => collect($names)->count() === count(config('permissions.permissions')))
            ->where('permissionCatalog.items', fn ($items) => collect($items)->contains('name', 'view-plans')));

        $this->put("/roles/{$role->id}", ['permissions' => ['view-plans', 'edit-plans']])->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->hasAllPermissions(['view-plans', 'edit-plans']));
    }

    public function test_a_manipulated_request_cannot_hand_a_platform_or_unknown_permission_to_a_role(): void
    {
        $role = Role::findOrCreate('contable', 'web');
        $this->put("/roles/{$role->id}", ['permissions' => ['view-plans']])->assertRedirect(); // not signed in: sent to the login

        // A client administrator reaches neither the editor nor the endpoint.
        $admin = User::factory()->create();
        $this->client->users()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin)->withSession(['workspace_id' => $this->client->id]);
        $this->put("/roles/{$role->id}", ['permissions' => ['view-plans']])->assertForbidden();
        $this->post('/roles', ['name' => 'poderoso', 'permissions' => ['assign-plans']])->assertForbidden();
        $this->assertSame([], $role->fresh()->permissions->all());

        // Not even the platform administration can assign a permission the catalog does not define (one left in a database from an earlier version).
        Permission::findOrCreate('manage-bookings', 'web');
        $this->signInInternal()->put("/roles/{$role->id}", ['permissions' => ['manage-bookings']])->assertSessionHasErrors('permissions.0');
        $this->assertSame([], $role->fresh()->permissions->all());
    }

    // --- the catalog is written for a person, not for a developer ----------------------------------------------------------------------------

    public function test_every_permission_has_a_functional_name_and_a_description_in_spanish(): void
    {
        $labels = [];

        foreach (config('permissions.permissions') as $name => $permission) {
            $this->assertNotSame($name, $permission['label'], "[{$name}] shows its identifier as a name");
            $this->assertDoesNotMatchRegularExpression('/^[a-z]+(-[a-z]+)+$/', $permission['label'], "[{$name}] label looks like an identifier");
            $this->assertDoesNotMatchRegularExpression('/\b(Manage|View|Create|Edit|Delete)\b/', $permission['label'].' '.$permission['description'], "[{$name}] has English words");
            $this->assertMatchesRegularExpression('/^\p{Lu}/u', $permission['label'], "[{$name}] label starts with a capital");
            $this->assertGreaterThan(20, mb_strlen($permission['description']), "[{$name}] needs a real description");
            $this->assertStringEndsWith('.', $permission['description'], "[{$name}] description is a sentence");
            $this->assertArrayNotHasKey($permission['label'], $labels, "[{$name}] repeats the label of [".($labels[$permission['label']] ?? '').']');
            $labels[$permission['label']] = $name;
            $this->assertArrayHasKey($permission['group'], config('permissions.groups'), "[{$name}] belongs to a group that exists");
        }
    }

    public function test_the_editor_never_receives_the_identifiers_as_text_to_show(): void
    {
        $this->signInInternal()->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissionCatalog.items', fn ($items) => collect($items)->every(fn ($item) => $item['label'] !== $item['name'] && filled($item['description']))));
    }

    public function test_the_operations_of_a_resource_are_the_permissions_its_routes_ask_for(): void
    {
        $expected = [
            'customers' => ['view-customers', 'view-customer-details', 'create-customers', 'edit-customers', 'activate-customers', 'anonymize-customers'],
            'orders' => ['view-orders', 'view-order-details', 'create-orders', 'edit-orders', 'cancel-orders', 'process-orders'],
            'catalog' => ['view-catalog', 'create-products', 'edit-products', 'activate-products'],
            'assistants' => ['view-chatbots', 'create-chatbots', 'edit-chatbots', 'activate-chatbots', 'manage-chatbot-channels', 'manage-agent-tokens'],
            'conversations' => ['view-conversations', 'take-conversations', 'reply-conversations', 'release-conversations', 'resolve-conversations', 'manage-conversations', 'use-conversation-demo'],
            'plans' => ['view-plans', 'create-plans', 'edit-plans', 'activate-plans', 'assign-plans'],
            'modules' => ['view-modules', 'create-modules', 'edit-modules', 'activate-modules'],
        ];

        foreach ($expected as $group => $names) {
            $actual = collect(config('permissions.permissions'))->filter(fn (array $p) => $p['group'] === $group)->keys()->all();
            $this->assertEqualsCanonicalizing($names, $actual, "operations of [{$group}]");
        }
    }

    public function test_the_modules_of_the_internal_workspace_do_not_come_from_a_plan(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), app(ModuleAccess::class)->keys($this->dev));
        $this->dev->update(['plan_id' => null]);
        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), app(ModuleAccess::class)->keys($this->dev->fresh()));
        $this->assertEqualsCanonicalizing(['dashboard', 'customers'], app(ModuleAccess::class)->keys($this->client));
    }
}
