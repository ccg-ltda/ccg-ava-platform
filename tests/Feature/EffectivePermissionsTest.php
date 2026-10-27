<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlatformAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The permissions saved on a role are the ONLY source of what its members may do, and the plan of the Workspace is a second, separate condition.
 * The checks run over every real route that is protected by `workspace.permission:` / `workspace.module:`, so a new route that forgets its permission,
 * or one that keeps accepting a permission that was taken away, fails here without anyone adding a case by hand.
 */
class EffectivePermissionsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    /** Routes of a signed-in, Workspace-bound user that need no permission on purpose: each decides inside, over the user's own data. */
    private const OPEN_ROUTES = [
        'GET profile', 'PATCH profile', 'PUT profile/communications', 'GET profile/photo', 'POST profile/photo', 'DELETE profile/photo',
        'GET workspace/logo', 'GET workspaces/search', 'GET sin-modulos', // sin-modulos: only reads which modules the Workspace has and grants nothing
        'GET help/manuals/{key}', // authorized inside HelpCenter, not by a route permission: general manuals for every member; a module manual only if the plan has the module AND the role holds the permission that opens it
        'GET support/thread', 'POST support/messages', // authorized inside SupportDesk, not by a catalog permission: only the ROLE `admin` of a CLIENT Workspace (403 for any other role, for the administrative Workspace and for guests); SupportDeskTest covers it
        'POST saved-filters/{scope}', 'PUT saved-filters/{scope}/{filter}', 'DELETE saved-filters/{scope}/{filter}',
    ];

    private Workspace $workspace;

    private User $member;

    private int $administrativeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('MATRIX', Module::pluck('key')->all());
        Role::findOrCreate('matrix', 'web');
        $this->member = $this->actAsMember($this->workspace, 'matrix');
        // Some routes allow 10 hits a minute; the matrix hits each route several times.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return list<string> */
    private function catalog(): array
    {
        return array_values(array_diff(
            array_keys(config('permissions.permissions')),
            RolesAndPermissionsSeeder::reserved(),
        ));
    }

    private function roleHolds(array $permissions): void
    {
        Role::findByName('matrix', 'web')->syncPermissions($permissions);
    }

    /**
     * The routes this suite can reason about: those of a Workspace-bound user guarded by permission middleware only (a superuser-only `can:` route or
     * a platform route is tested elsewhere).
     *
     * @return list<LaravelRoute>
     */
    private function guardedRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())->filter(function (LaravelRoute $route) {
            $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

            $special = RolesAndPermissionsSeeder::reserved();

            return $middleware->contains('workspace')
                && $middleware->contains(fn ($m) => str_starts_with($m, 'workspace.permission:'))
                && ! $middleware->contains(fn ($m) => str_starts_with($m, 'can:') || str_starts_with($m, 'workspace.platform:'))
                // Support access (another Workspace's private data) is its own, separately tested boundary.
                && collect($this->requirements($route))->flatten()->intersect($special)->isEmpty()
                // A platform permission (the DEMO bench) only counts in the administrative Workspace; PlatformPermissionBoundaryTest covers it.
                && collect($this->requirements($route))->flatten()->intersect(app(PlatformAccess::class)->platformOnly())->isEmpty();
        })->values()->all();
    }

    /** @return list<list<string>> one "any of these" list per permission middleware of the route */
    private function requirements(LaravelRoute $route): array
    {
        return collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'workspace.permission:'))
            ->map(fn (string $m) => explode(',', substr($m, strlen('workspace.permission:'))))->values()->all();
    }

    private function send(LaravelRoute $route): int
    {
        // `whereNumber` constraints are digits; an alternation (`generate|reset`) takes its first word; the others (`[a-z_]+`) get a word.
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($route) {
            $pattern = $route->wheres[$m[1]] ?? null;

            return match (true) {
                $pattern === null || str_contains($pattern, '0-9') || str_contains($pattern, '\d') => '1',
                str_contains($pattern, '|') => explode('|', $pattern)[0],
                default => 'booker',
            };
        }, $route->uri());
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

        return $this->call($method, '/'.$uri, [])->getStatusCode();
    }

    private function label(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn ($m) => $m !== 'HEAD').' '.$route->uri();
    }

    public function test_the_matrix_covers_a_real_set_of_routes(): void
    {
        // A guard against a silent no-op: if route discovery broke, every other test here would pass without checking anything.
        $this->assertGreaterThan(100, count($this->guardedRoutes()));
    }

    public function test_every_route_of_a_workspace_user_either_needs_a_permission_or_is_a_known_open_one(): void
    {
        $open = collect(Route::getRoutes()->getRoutes())->filter(function (LaravelRoute $route) {
            $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

            return $middleware->contains('workspace')
                && ! $middleware->contains(fn ($m) => str_starts_with($m, 'workspace.permission:') || str_starts_with($m, 'can:') || str_starts_with($m, 'workspace.platform:'));
        })->map(fn (LaravelRoute $route) => $this->label($route))->sort()->values()->all();

        $expected = collect(self::OPEN_ROUTES)->sort()->values()->all();

        $this->assertSame($expected, $open, 'A route of the signed-in area has no permission middleware: protect it, or add it to OPEN_ROUTES with the reason.');
    }

    public function test_taking_away_the_permission_a_route_requires_denies_it_even_to_a_role_that_holds_every_other_permission(): void
    {
        $failures = [];

        foreach ($this->guardedRoutes() as $route) {
            foreach ($this->requirements($route) as $anyOf) {
                $this->roleHolds(array_values(array_diff($this->catalog(), $anyOf)));
                $status = $this->send($route);

                if ($status !== 403) {
                    $failures[] = $this->label($route).' without ['.implode('|', $anyOf)."] answered {$status}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_a_role_that_holds_everything_is_not_stopped_by_the_permission_layer_on_any_guarded_route(): void
    {
        $this->roleHolds($this->catalog());
        $failures = [];

        foreach ($this->guardedRoutes() as $route) {
            if ($this->send($route) === 403) {
                $failures[] = $this->label($route);
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_the_permissions_a_route_names_are_enough_to_pass_the_permission_layer_with_nothing_else_granted(): void
    {
        $failures = [];

        foreach ($this->guardedRoutes() as $route) {
            // One permission of each "any of" list, plus the permissions that open the page of the same resource: the least a role needs.
            $needed = collect($this->requirements($route))->flatMap(function (array $anyOf) {
                $chosen = $anyOf[0];
                $list = config('permissions.groups.'.config("permissions.permissions.{$chosen}.group").'.list');

                return array_filter([$chosen, $list]);
            })->unique()->values()->all();

            $this->roleHolds($needed);

            if ($this->send($route) === 403) {
                $failures[] = $this->label($route).' with only ['.implode(', ', $needed).']';
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_a_module_missing_from_the_plan_denies_its_routes_to_a_role_that_holds_every_permission(): void
    {
        $this->roleHolds($this->catalog());
        $failures = [];

        $byModule = collect(Route::getRoutes()->getRoutes())->flatMap(function (LaravelRoute $route) {
            $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

            if (! $middleware->contains('workspace') || $middleware->contains(fn ($m) => str_starts_with($m, 'can:') || str_starts_with($m, 'workspace.platform:'))) {
                return [];
            }

            return $middleware->filter(fn ($m) => str_starts_with($m, 'workspace.module:'))
                ->map(fn (string $m) => [substr($m, strlen('workspace.module:')), $route])->values()->all();
        })->groupBy(0);

        $this->assertGreaterThan(5, $byModule->count());

        foreach ($byModule as $module => $entries) {
            $this->contractModules($this->workspace, Module::where('key', '!=', $module)->pluck('key')->all());

            foreach ($entries as [, $route]) {
                $status = $this->send($route);

                // The home page is never served without its module; instead of an error it redirects to the explanatory page (/sin-modulos).
                $expected = $module === 'dashboard' && $route->uri() === 'dashboard' ? 302 : 403;

                if ($status !== $expected) {
                    $failures[] = $this->label($route)." without module [{$module}] answered {$status}";
                }
            }
        }

        $this->assertSame([], $failures);
    }

    // --- the saved role is what authorizes: editor -> database -> next request ---------------------------------------

    private function superuser(): User
    {
        $super = User::factory()->create();
        $super->forceFill(['is_superuser' => true])->save();
        // A superuser edits the global catalog from the administrative Workspace.
        $administrative = $this->bookingWorkspace('PLATAFORMA', Module::pluck('key')->all());
        config(['workspace.admin_code' => $administrative->code]);
        $administrative->users()->attach($super->id, ['role' => 'admin']);
        $this->administrativeId = $administrative->id;

        return $super;
    }

    private function editRole(Role $role, array $permissions): void
    {
        $super = $this->superuser();
        $this->actingAs($super)->withSession(['workspace_id' => $this->administrativeId]);
        $this->put("/roles/{$role->id}", ['permissions' => $permissions])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->member)->withSession(['workspace_id' => $this->workspace->id]);
    }

    private function newCustomer(): int
    {
        static $n = 0;

        return $this->workspace->customers()->create(['first_name' => 'Ana', 'phone' => '+5730077'.str_pad((string) ++$n, 5, '0', STR_PAD_LEFT), 'source' => 'manual', 'marketing_consent' => 'unknown'])->id;
    }

    private function payload(): array
    {
        return ['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+57 311 222 3344', 'email' => 'laura@example.com', 'source' => 'manual', 'marketing_consent' => 'unknown'];
    }

    public function test_saving_the_role_in_the_editor_grants_exactly_what_was_selected_on_the_very_next_request(): void
    {
        $role = Role::findByName('matrix', 'web');
        $this->roleHolds(['view-dashboard', 'view-customers']);

        $this->post('/customers', $this->payload())->assertForbidden();
        $id = $this->newCustomer();
        $this->put("/customers/{$id}", $this->payload())->assertForbidden();

        $this->editRole($role, ['view-dashboard', 'view-customers', 'create-customers']);

        $this->assertDatabaseHas('role_has_permissions', ['role_id' => $role->id, 'permission_id' => Permission::findByName('create-customers', 'web')->id]);
        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $role->id, 'permission_id' => Permission::findByName('edit-customers', 'web')->id]);

        $before = Customer::count();
        $this->assertNotSame(403, $this->post('/customers', $this->payload())->getStatusCode());
        $this->assertSame($before + 1, Customer::count(), 'the creation really happened');
        $this->put("/customers/{$id}", $this->payload())->assertForbidden();
        $this->post("/customers/{$id}/anonymize")->assertForbidden();
        $this->post("/customers/{$id}/deactivate")->assertForbidden();
    }

    public function test_unchecking_a_permission_in_the_editor_revokes_it_at_once_and_a_denied_request_changes_nothing(): void
    {
        $role = Role::findByName('matrix', 'web');
        $this->roleHolds(['view-dashboard', 'view-customers', 'create-customers', 'edit-customers', 'activate-customers']);
        $id = $this->newCustomer();

        $this->assertNotSame(403, $this->put("/customers/{$id}", $this->payload() + [])->getStatusCode());

        $this->editRole($role, ['view-dashboard', 'view-customers', 'create-customers']);

        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $role->id, 'permission_id' => Permission::findByName('edit-customers', 'web')->id]);
        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $role->id, 'permission_id' => Permission::findByName('activate-customers', 'web')->id]);

        $name = Customer::find($id)->first_name;
        $active = Customer::find($id)->is_active;
        $this->put("/customers/{$id}", array_merge($this->payload(), ['first_name' => 'Cambiado']))->assertForbidden();
        $this->post("/customers/{$id}/deactivate")->assertForbidden();

        $this->assertSame($name, Customer::find($id)->first_name, 'the denied edit did not change the record');
        $this->assertSame($active, Customer::find($id)->is_active, 'the denied toggle did not change the record');
        // What was kept still works.
        $this->assertNotSame(403, $this->post('/customers', $this->payload())->getStatusCode());
    }

    public function test_the_revocation_survives_a_new_session_and_the_permission_cache_of_the_package(): void
    {
        $role = Role::findByName('matrix', 'web');
        $this->roleHolds(['view-dashboard', 'view-customers', 'create-customers']);

        // Warm the package cache with the old assignment, then revoke through the editor.
        app(PermissionRegistrar::class)->getPermissions();
        $this->editRole($role, ['view-dashboard', 'view-customers']);

        $this->post('/customers', $this->payload())->assertForbidden();

        // Another session of the same person (a fresh login) sees the same thing.
        $this->flushSession();
        $this->actingAs($this->member->fresh())->withSession(['workspace_id' => $this->workspace->id]);
        $this->post('/customers', $this->payload())->assertForbidden();
        $this->assertFalse($role->fresh()->hasPermissionTo('create-customers'));
    }

    public function test_the_interface_receives_the_saved_permissions_on_the_next_load_and_not_the_ones_removed(): void
    {
        $role = Role::findByName('matrix', 'web');
        $this->roleHolds(['view-dashboard', 'view-customers', 'create-customers', 'edit-customers']);

        $this->get('/customers')->assertInertia(fn ($page) => $page->where('auth.user.permissions', fn ($permissions) => in_array('edit-customers', $permissions->all(), true)));

        $this->editRole($role, ['view-dashboard', 'view-customers', 'create-customers']);

        $this->get('/customers')->assertInertia(fn ($page) => $page->where('auth.user.permissions', function ($permissions) {
            $permissions = $permissions->all();

            return in_array('create-customers', $permissions, true) && ! in_array('edit-customers', $permissions, true);
        }));
    }

    public function test_deleting_the_permission_from_the_database_stops_authorizing_the_operation(): void
    {
        $this->roleHolds(['view-dashboard', 'view-customers', 'create-customers']);
        $this->assertNotSame(403, $this->post('/customers', $this->payload())->getStatusCode());

        Permission::findByName('create-customers', 'web')->delete();

        $this->post('/customers', $this->payload())->assertForbidden();
    }

    public function test_a_permission_given_to_another_role_does_not_reach_this_one(): void
    {
        $this->roleHolds(['view-dashboard', 'view-customers']);
        Role::findOrCreate('otro', 'web')->syncPermissions(['view-dashboard', 'view-customers', 'create-customers', 'edit-customers']);

        $this->post('/customers', $this->payload())->assertForbidden();
        $this->put('/customers/'.$this->newCustomer(), $this->payload())->assertForbidden();
    }

    public function test_changing_the_role_of_a_member_changes_what_they_can_do_on_the_next_request(): void
    {
        Role::findOrCreate('creador', 'web')->syncPermissions(['view-dashboard', 'view-customers', 'create-customers']);
        $this->roleHolds(['view-dashboard', 'view-customers']);

        $this->post('/customers', $this->payload())->assertForbidden();

        $this->workspace->users()->updateExistingPivot($this->member->id, ['role' => 'creador']);
        $this->assertNotSame(403, $this->post('/customers', $this->payload())->getStatusCode());

        $this->workspace->users()->updateExistingPivot($this->member->id, ['role' => 'matrix']);
        $this->post('/customers', $this->payload())->assertForbidden();
    }

    public function test_the_plan_and_the_workspace_still_limit_a_role_that_holds_the_permission(): void
    {
        $this->roleHolds($this->catalog());
        $other = $this->bookingWorkspace('AJENO', Module::pluck('key')->all());
        $foreign = $other->customers()->create(['first_name' => 'Ajena', 'phone' => '+573009999999', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        // A record of another Workspace does not exist for this member, whatever the role says.
        $this->put("/customers/{$foreign->id}", $this->payload())->assertNotFound();
        $this->post("/customers/{$foreign->id}/anonymize")->assertNotFound();
        $this->assertSame('Ajena', $foreign->fresh()->first_name);

        // And a plan without the module closes the page for the same role.
        $this->contractModules($this->workspace, Module::where('key', '!=', 'customers')->pluck('key')->all());
        $this->post('/customers', $this->payload())->assertForbidden();
    }
}
