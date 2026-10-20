<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlatformAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Where platform privileges end and where a client's privileges begin: a superuser carries them only inside the administrative
 * Workspace; every user action has its own permission; nobody escalates; the permission catalog matches what the routes enforce.
 */
class PlatformBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_UNO', 'name' => 'Cliente uno']);
        $this->other = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_DOS', 'name' => 'Cliente dos']);
    }

    private function actIn(Workspace $workspace, string $role, bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function member(Workspace $workspace, string $role = 'cliente'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    /** A role that opens Usuarios y roles and holds exactly the given extra permissions. */
    private function roleWith(string $name, array $permissions): void
    {
        Role::findOrCreate($name, 'web')->syncPermissions(['manage-users', 'view-dashboard', ...$permissions]);
    }

    // --- a superuser is a platform administrator only inside the administrative Workspace --------------------

    public function test_a_superuser_inside_a_client_workspace_has_no_platform_privileges(): void
    {
        $plan = Plan::create(['name' => 'Otro', 'is_active' => true]);
        $this->actIn($this->client, 'admin', superuser: true);

        $this->get('/plans')->assertForbidden();
        $this->post('/plans', ['name' => 'Mío'])->assertForbidden();
        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $plan->id])->assertForbidden();
        $this->post('/roles', ['name' => 'poderoso', 'permissions' => ['manage-plans']])->assertForbidden();
        $this->put('/roles/'.Role::findByName('cliente', 'web')->id, ['permissions' => ['manage-users']])->assertForbidden();
        $this->post('/workspaces', ['organization_id' => $this->client->organization_id, 'code' => 'NUEVO', 'name' => 'Nuevo'])->assertForbidden();
        $this->put("/workspaces/{$this->other->id}", ['name' => 'Cambiado', 'code' => 'CLIENTE_DOS'])->assertForbidden();
        $this->post('/organizations', ['name' => 'Otra'])->assertForbidden();
        $this->post("/workspaces/{$this->other->id}/members", ['email' => 'x@example.com', 'role' => 'cliente'])->assertForbidden();
        $this->post('/support-access', ['workspace_id' => $this->other->id, 'type' => 'conversations', 'reason' => 'Quiero ver las conversaciones'])->assertForbidden();

        $this->assertNull(Role::findByName('cliente', 'web')->permissions->firstWhere('name', 'manage-users'));
        $this->assertDatabaseMissing('workspaces', ['code' => 'NUEVO']);
        $this->assertSame('Cliente dos', $this->other->fresh()->name);
    }

    public function test_the_users_page_of_a_superuser_in_a_client_workspace_offers_no_platform_administration(): void
    {
        $this->actIn($this->client, 'admin', superuser: true);
        $this->member($this->other);

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManageRoles', false)->where('workspaces.canManage', false)->where('organizations', null)
            ->has('workspaces.list.data', 1)->where('workspaces.list.data.0.code', 'CLIENTE_UNO'));
        $this->get('/users?workspace='.$this->other->id)->assertInertia(fn (AssertableInertia $page) => $page->where('workspaces.selected', null));
        $this->assertNotContains('manage-plans', $this->get('/users')->inertiaProps('auth.user.permissions'));
    }

    public function test_a_superuser_in_a_client_workspace_cannot_hand_out_platform_roles(): void
    {
        $this->actIn($this->client, 'admin', superuser: true);
        $victim = $this->member($this->client);

        // `plataforma` and `soporte` carry permissions only the administrative Workspace may use: nobody assigns them from a client Workspace.
        foreach (['plataforma', 'soporte'] as $role) {
            $this->put("/workspaces/{$this->client->id}/members/{$victim->id}", ['role' => $role])->assertSessionHasErrors('role');
            $this->put("/users/{$victim->id}", ['name' => $victim->name, 'email' => $victim->email, 'role' => $role])->assertSessionHasErrors('role');
        }

        $this->assertSame('cliente', $this->client->users()->find($victim->id)->pivot->role);
    }

    public function test_a_superuser_still_administers_the_platform_inside_the_administrative_workspace(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);

        $this->get('/plans')->assertOk();
        $this->post('/roles', ['name' => 'nuevo-rol', 'permissions' => ['view-dashboard']])->assertSessionHasNoErrors();
        $this->post('/workspaces', ['organization_id' => $this->dev->organization_id, 'code' => 'ALTA_NUEVA', 'name' => 'Alta'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workspaces', ['code' => 'ALTA_NUEVA']);
    }

    public function test_the_platform_view_of_another_workspace_team_hides_the_addresses(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);
        $client = User::factory()->create(['name' => 'Persona Cliente', 'email' => 'persona@cliente.example']);
        $this->client->users()->attach($client->id, ['role' => 'cliente']);

        $members = $this->get('/users?workspace='.$this->client->id)->inertiaProps('workspaces.selected.members.data');

        $this->assertSame('Persona Cliente', $members[0]['name']);
        $this->assertSame('p•••@cliente.example', $members[0]['email']);

        // The team of the Workspace the administrator works in is theirs to manage: addresses visible.
        $own = $this->get('/users?workspace='.$this->dev->id)->inertiaProps('workspaces.selected.members.data');
        $this->assertStringContainsString('@', $own[0]['email']);
        $this->assertStringNotContainsString('•', $own[0]['email']);
    }

    // --- the indirect way in: memberships of internal accounts in a client Workspace ----------------------------

    public function test_a_platform_administrator_cannot_add_themselves_to_a_client_workspace(): void
    {
        $me = $this->actIn($this->dev, 'admin', superuser: true);

        $this->post("/workspaces/{$this->client->id}/members", ['email' => $me->email, 'role' => 'admin'])->assertSessionHasErrors('email');

        $this->assertFalse($this->client->users()->whereKey($me->id)->exists());
        $this->assertDatabaseMissing('audit_logs', ['resource_type' => 'membership', 'workspace_id' => $this->client->id]);
    }

    public function test_internal_colleagues_are_not_added_to_a_client_workspace_either(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);
        $colleague = $this->member($this->dev, 'admin');           // belongs to the administrative Workspace
        $other = User::factory()->create();
        $other->forceFill(['is_superuser' => true])->save();       // a superuser with no membership at all

        foreach ([$colleague, $other] as $internal) {
            $this->post("/workspaces/{$this->client->id}/members", ['email' => $internal->email, 'role' => 'cliente'])->assertSessionHasErrors('email');
            $this->assertFalse($this->client->users()->whereKey($internal->id)->exists());
        }
    }

    public function test_an_internal_account_cannot_be_moved_into_a_client_workspace_or_get_a_higher_role_there(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);
        $colleague = $this->member($this->dev, 'admin');

        $this->put("/users/{$colleague->id}", ['name' => $colleague->name, 'email' => $colleague->email, 'role' => 'cliente', 'workspace_id' => $this->client->id])->assertSessionHasErrors('workspace_id');
        $this->assertFalse($this->client->users()->whereKey($colleague->id)->exists());
        $this->assertSame('admin', $this->dev->users()->find($colleague->id)->pivot->role, 'The move did not half-happen.');

        // A membership that already exists (older data) cannot be raised from the screens either.
        $this->client->users()->attach($colleague->id, ['role' => 'cliente']);
        $this->put("/workspaces/{$this->client->id}/members/{$colleague->id}", ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->assertSame('cliente', $this->client->users()->find($colleague->id)->pivot->role);
        // ...but it can be taken away.
        $this->delete("/workspaces/{$this->client->id}/members/{$colleague->id}")->assertSessionHasNoErrors();
        $this->assertFalse($this->client->users()->whereKey($colleague->id)->exists());
    }

    public function test_client_staff_can_still_be_placed_and_managed_by_platform_and_by_their_own_admins(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);
        $person = User::factory()->create();

        $this->post("/workspaces/{$this->client->id}/members", ['email' => $person->email, 'role' => 'cliente'])->assertSessionHasNoErrors();
        $this->assertTrue($this->client->users()->whereKey($person->id)->exists());

        $this->actIn($this->client, 'admin');
        $this->post('/users', ['name' => 'Nueva', 'email' => 'nueva@example.com', 'role' => 'cliente', 'workspace_id' => $this->client->id, 'password' => 'password123', 'password_confirmation' => 'password123'])->assertSessionHasNoErrors();
        $this->put("/workspaces/{$this->client->id}/members/{$person->id}", ['role' => 'supervisor'])->assertSessionHasNoErrors();
        $this->assertSame('supervisor', $this->client->users()->find($person->id)->pivot->role);
    }

    public function test_a_client_admin_cannot_add_existing_accounts_by_email(): void
    {
        $this->actIn($this->client, 'admin');
        $stranger = User::factory()->create();

        $this->post("/workspaces/{$this->client->id}/members", ['email' => $stranger->email, 'role' => 'cliente'])->assertForbidden();
        $this->assertFalse($this->client->users()->whereKey($stranger->id)->exists());
    }

    // --- least privilege of the seeded roles ------------------------------------------------------------------------

    public function test_the_seeded_roles_hold_exactly_the_minimum_each_needs(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        app(RolesAndPermissionsSeeder::class)->run();

        $of = fn (string $role) => Role::findByName($role, 'web')->permissions->pluck('name')->sort()->values()->all();
        $platform = RolesAndPermissionsSeeder::reserved();
        $commercial = app(PlatformAccess::class)->commercial();
        $granular = ['create-users', 'edit-users', 'assign-roles', 'deactivate-users', 'export-audit'];

        $this->assertSame([], array_values(array_intersect($of('admin'), $platform)), 'Not even admin receives platform or support permissions.');
        $this->assertSame(array_values(array_diff(Permission::pluck('name')->sort()->values()->all(), $platform)), $of('admin'));
        $withDashboard = function (array $names) {
            $names = ['view-dashboard', ...$names];
            sort($names);

            return $names;
        };
        $this->assertSame($withDashboard($commercial), $of('plataforma'));
        $this->assertSame($withDashboard(['access-private-data', 'view-conversations']), $of('soporte'));
        $this->assertSame(['view-chatbots', 'view-dashboard', 'view-users'], $of('supervisor'));
        $this->assertSame($withDashboard(['view-conversations', 'take-conversations', 'reply-conversations', 'release-conversations', 'resolve-conversations']), $of('agente'));
        $this->assertSame(['view-dashboard'], $of('cliente'));
        foreach (['supervisor', 'agente', 'cliente', 'plataforma', 'soporte'] as $role) {
            $this->assertSame([], array_values(array_intersect($of($role), $granular)), "[{$role}] must not receive user administration by default.");
        }
        $this->assertSame([], Role::findByName('soporte', 'web')->users()->get()->all());
    }

    // --- every user action has its own permission -------------------------------------------------------------

    public function test_opening_the_module_does_not_allow_any_action(): void
    {
        $this->roleWith('lector', []);
        $this->actIn($this->client, 'lector');
        $target = $this->member($this->client);

        $this->get('/users')->assertOk();
        $this->post('/users', ['name' => 'N', 'email' => 'n@example.com', 'role' => 'cliente', 'workspace_id' => $this->client->id, 'password' => 'password123', 'password_confirmation' => 'password123'])->assertForbidden();
        $this->put("/users/{$target->id}", ['name' => 'Otro', 'email' => $target->email, 'role' => 'cliente'])->assertForbidden();
        $this->post("/users/{$target->id}/deactivate")->assertForbidden();
        $this->put("/workspaces/{$this->client->id}/members/{$target->id}", ['role' => 'supervisor'])->assertForbidden();
        $this->delete("/workspaces/{$this->client->id}/members/{$target->id}")->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
        $this->assertSame('cliente', $this->client->users()->find($target->id)->pivot->role);
    }

    public function test_each_user_action_needs_only_its_own_permission(): void
    {
        $this->roleWith('creador', ['create-users']);
        $this->actIn($this->client, 'creador');
        $this->post('/users', ['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'role' => 'cliente', 'workspace_id' => $this->client->id, 'password' => 'password123', 'password_confirmation' => 'password123'])->assertSessionHasNoErrors();
        $created = User::where('email', 'nuevo@example.com')->firstOrFail();
        $this->put("/users/{$created->id}", ['name' => 'Cambiado', 'email' => $created->email, 'role' => 'cliente'])->assertForbidden();
        $this->post("/users/{$created->id}/deactivate")->assertForbidden();

        $this->roleWith('editor', ['edit-users', 'view-users', 'view-chatbots']); // holds what `supervisor` holds, so only the permission decides
        $this->actIn($this->client, 'editor');
        $this->put("/users/{$created->id}", ['name' => 'Cambiado', 'email' => $created->email, 'role' => 'cliente'])->assertSessionHasNoErrors();
        $this->assertSame('Cambiado', $created->fresh()->name);
        // Editing is not assigning: the role cannot change with this permission alone.
        $this->put("/users/{$created->id}", ['name' => 'Cambiado', 'email' => $created->email, 'role' => 'supervisor'])->assertForbidden();
        $this->assertSame('cliente', $this->client->users()->find($created->id)->pivot->role);

        $this->roleWith('asignador', ['assign-roles', 'view-users', 'view-chatbots']);
        $this->actIn($this->client, 'asignador');
        $this->put("/users/{$created->id}", ['name' => 'Cambiado', 'email' => $created->email, 'role' => 'supervisor'])->assertSessionHasNoErrors();
        $this->assertSame('supervisor', $this->client->users()->find($created->id)->pivot->role);
        // Assigning is not editing the account.
        $this->put("/users/{$created->id}", ['name' => 'Renombrado', 'email' => $created->email, 'role' => 'supervisor'])->assertForbidden();
        $this->assertSame('Cambiado', $created->fresh()->name);

        $this->roleWith('bloqueador', ['deactivate-users']);
        $this->actIn($this->client, 'bloqueador');
        $this->post("/users/{$created->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($created->fresh()->is_active);
        $this->post("/users/{$created->id}/activate")->assertSessionHasNoErrors();
        $this->assertTrue($created->fresh()->is_active);
        $this->put("/users/{$created->id}", ['name' => 'Cambiado', 'email' => $created->email, 'role' => 'supervisor'])->assertForbidden();
    }

    public function test_the_users_page_tells_the_interface_what_each_person_may_do(): void
    {
        $this->roleWith('asignador', ['assign-roles']);
        $this->actIn($this->client, 'asignador');

        $props = $this->get('/users')->inertiaProps('auth.user.permissions');
        $this->assertContains('assign-roles', $props);
        $this->assertNotContains('create-users', $props);
        $this->assertNotContains('edit-users', $props);
        $this->assertNotContains('deactivate-users', $props);
    }

    public function test_a_users_effective_permissions_are_listed_for_each_person(): void
    {
        $this->actIn($this->client, 'admin');
        $agent = $this->member($this->client, 'agente');

        $row = collect($this->get('/users')->inertiaProps('users.data'))->firstWhere('id', $agent->id);

        $this->assertSame(['release-conversations', 'reply-conversations', 'resolve-conversations', 'take-conversations', 'view-conversations', 'view-dashboard'], $row['grants']);
    }

    // --- nobody escalates ---------------------------------------------------------------------------------------

    public function test_a_user_cannot_hand_out_a_role_with_permissions_they_lack_or_change_their_own(): void
    {
        $this->roleWith('asignador', ['assign-roles', 'edit-users']);
        $me = $this->actIn($this->client, 'asignador');
        $victim = $this->member($this->client);

        $this->put("/users/{$victim->id}", ['name' => $victim->name, 'email' => $victim->email, 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->put("/workspaces/{$this->client->id}/members/{$victim->id}", ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->put("/users/{$me->id}", ['name' => $me->name, 'email' => $me->email, 'role' => 'cliente'])->assertSessionHasErrors('role');
        $this->put("/workspaces/{$this->client->id}/members/{$me->id}", ['role' => 'cliente'])->assertSessionHasErrors('role');

        $this->assertSame('cliente', $this->client->users()->find($victim->id)->pivot->role);
        $this->assertSame('asignador', $this->client->users()->find($me->id)->pivot->role);
    }

    public function test_permissions_do_not_cross_workspaces(): void
    {
        $this->roleWith('asignador', ['assign-roles', 'create-users', 'deactivate-users', 'edit-users']);
        $this->actIn($this->client, 'asignador');
        $foreign = $this->member($this->other);

        $this->put("/users/{$foreign->id}", ['name' => 'X', 'email' => $foreign->email, 'role' => 'cliente'])->assertNotFound();
        $this->post("/users/{$foreign->id}/deactivate")->assertNotFound();
        $this->put("/workspaces/{$this->other->id}/members/{$foreign->id}", ['role' => 'supervisor'])->assertNotFound();
        $this->post('/users', ['name' => 'N', 'email' => 'n@example.com', 'role' => 'cliente', 'workspace_id' => $this->other->id, 'password' => 'password123', 'password_confirmation' => 'password123'])->assertSessionHasErrors('workspace_id');

        $this->assertTrue($foreign->fresh()->is_active);
        $this->assertSame('cliente', $this->other->users()->find($foreign->id)->pivot->role);
    }

    public function test_permission_and_membership_changes_are_audited(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);
        $this->put('/roles/'.Role::findByName('cliente', 'web')->id, ['permissions' => ['view-dashboard', 'view-bookings']])->assertSessionHasNoErrors();
        $victim = $this->member($this->dev);
        $this->put("/workspaces/{$this->dev->id}/members/{$victim->id}", ['role' => 'supervisor'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'role', 'action' => 'updated']);
        $membership = AuditLog::where('resource_type', 'membership')->latest('id')->firstOrFail();
        $this->assertContains(['field' => 'Rol', 'before' => 'cliente', 'after' => 'supervisor'], $membership->changes);
    }

    // --- exporting the audit is its own permission ---------------------------------------------------------------

    public function test_exporting_the_audit_needs_its_own_permission(): void
    {
        Role::findOrCreate('auditor', 'web')->syncPermissions(['view-audit', 'view-dashboard']);
        $this->actIn($this->client, 'auditor');

        $this->get('/audit')->assertOk();
        $this->get('/audit/export')->assertForbidden();

        Role::findByName('auditor', 'web')->givePermissionTo('export-audit');
        $this->get('/audit/export')->assertOk();
    }

    // --- the catalog says what the system enforces ------------------------------------------------------------------

    public function test_the_permission_catalog_describes_exactly_the_permissions_the_seeder_creates(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        app(RolesAndPermissionsSeeder::class)->run();

        $this->assertEqualsCanonicalizing(array_keys(config('permissions.permissions')), Permission::pluck('name')->all());
        foreach (config('permissions.permissions') as $name => $entry) {
            $this->assertArrayHasKey($entry['group'], config('permissions.groups'), "[{$name}] belongs to an unknown group.");
            $this->assertNotEmpty($entry['label']);
            $this->assertNotEmpty($entry['description']);
        }
    }

    public function test_every_permission_a_route_requires_exists_in_the_catalog(): void
    {
        $required = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'workspace.permission:'))
            ->flatMap(fn (string $middleware) => explode(',', substr($middleware, strlen('workspace.permission:'))))
            ->unique();

        $this->assertNotEmpty($required);
        foreach ($required as $permission) {
            $this->assertArrayHasKey($permission, config('permissions.permissions'), "A route requires [{$permission}] but the catalog does not describe it.");
        }
    }

    public function test_commercial_and_support_permissions_are_marked_as_platform_only(): void
    {
        foreach ([...app(PlatformAccess::class)->commercial(), 'access-private-data', 'use-conversation-demo'] as $name) {
            $this->assertTrue(config("permissions.permissions.{$name}.platform"), "[{$name}] only operates inside the administrative Workspace.");
        }
        $this->assertSame(['access-private-data'], app(PlatformAccess::class)->privateData());
    }

    public function test_splitting_a_broad_permission_keeps_what_existing_roles_could_do_and_never_re_grants(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        // A database from before the split: the broad permissions exist and the fine ones do not.
        $fine = collect(config('permissions.legacy'))->flatMap(fn ($split) => $split['grants'])->unique()->all();
        Permission::whereIn('name', $fine)->delete();
        foreach (collect(config('permissions.legacy'))->filter(fn ($split) => $split['retire'])->keys() as $broad) {
            Permission::findOrCreate($broad, 'web');
        }
        Role::findOrCreate('legado', 'web')->syncPermissions(['manage-users', 'manage-settings', 'manage-customers', 'view-customers', 'manage-services', 'view-bookings']);
        Role::findOrCreate('lector-de-agenda', 'web')->syncPermissions(['view-bookings']);

        app(RolesAndPermissionsSeeder::class)->run();

        $legacy = Role::findByName('legado', 'web')->fresh()->permissions->pluck('name');
        // Every ability the broad permissions gave is still there, in its fine form...
        foreach (['create-users', 'assign-roles', 'export-audit', 'view-settings', 'edit-settings', 'view-integrations', 'manage-integrations', 'view-audit',
            'create-customers', 'edit-customers', 'anonymize-customers', 'view-customer-details', 'create-services', 'manage-availability', 'create-locations', 'manage-booking-settings',
            'view-services', 'view-professionals', 'view-locations'] as $permission) {
            $this->assertTrue($legacy->contains($permission), "legado keeps [{$permission}]");
        }
        // ...but a power that did not exist before (removing a connection) is not handed out by the migration, and nothing else leaks.
        $this->assertFalse($legacy->contains('delete-integrations'));
        $this->assertFalse($legacy->contains('create-orders'));
        $this->assertSame(['view-bookings', 'view-locations', 'view-professionals', 'view-services'], Role::findByName('lector-de-agenda', 'web')->fresh()->permissions->pluck('name')->sort()->values()->all());
        $this->assertFalse(Role::findByName('cliente', 'web')->permissions->pluck('name')->contains('create-users'));

        // The broad permissions that no route uses any more are gone, and the database is exactly the catalog.
        foreach (['manage-settings', 'manage-customers', 'manage-services', 'manage-chatbots', 'manage-bookings', 'manage-campaigns', 'manage-catalog', 'manage-orders'] as $retired) {
            $this->assertFalse(Permission::where('name', $retired)->exists(), "[{$retired}] is retired");
        }
        $this->assertEqualsCanonicalizing(array_keys(config('permissions.permissions')), Permission::pluck('name')->all());

        // A superuser takes one away: running the seeder again does not give it back.
        Role::findByName('legado', 'web')->revokePermissionTo(['deactivate-users', 'edit-customers']);
        app(RolesAndPermissionsSeeder::class)->run();
        $after = Role::findByName('legado', 'web')->fresh()->permissions->pluck('name');
        $this->assertFalse($after->contains('deactivate-users') || $after->contains('edit-customers'));
    }

    public function test_the_seeder_on_a_fresh_database_creates_exactly_the_catalog_and_gives_nobody_a_broad_permission(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Permission::query()->delete();
        Role::query()->delete();

        app(RolesAndPermissionsSeeder::class)->run();

        $this->assertEqualsCanonicalizing(array_keys(config('permissions.permissions')), Permission::pluck('name')->all());
        $this->assertFalse(Permission::where('name', 'manage-settings')->exists());
    }
    // --- the consolidated schema -------------------------------------------------------------------------------------

    public function test_the_consolidated_create_migrations_contain_what_the_removed_add_migrations_added(): void
    {
        $this->assertTrue(Schema::hasColumns('conversations', ['handling', 'handling_version', 'assigned_user_id', 'handoff_reason', 'handoff_requested_at', 'resolved_at', 'demo_key']));
        $this->assertTrue(Schema::hasColumns('messages', ['sender', 'sender_user_id', 'failure_reason', 'simulated']));
        $this->assertTrue(Schema::hasColumns('chatbots', ['is_demo', 'workflow_key']));
        $this->assertTrue(Schema::hasColumns('audit_logs', ['actor', 'outcome']));
        $this->assertTrue(Schema::hasColumns('customers', ['anonymized_at']));
        $this->assertTrue(Schema::hasTable('chatbot_executions'));

        $indexed = fn (string $table, array $columns) => collect(Schema::getIndexes($table))->contains(fn ($index) => $index['columns'] === $columns);
        $this->assertTrue($indexed('conversations', ['id', 'chatbot_id', 'workspace_id']), 'Target of the composite keys of an execution.');
        $this->assertTrue($indexed('messages', ['id', 'conversation_id', 'workspace_id']));
        $this->assertTrue($indexed('audit_logs', ['workspace_id', 'actor']));
        $this->assertTrue($indexed('audit_logs', ['workspace_id', 'outcome']));
        $this->assertTrue($indexed('conversations', ['workspace_id', 'handling', 'last_message_at']));
    }

    public function test_the_only_add_migration_left_creates_a_table_that_already_ran_in_development(): void
    {
        $left = collect(glob(database_path('migrations/*.php')))->map(fn ($path) => basename($path))->filter(fn (string $name) => str_contains($name, '_add_'))->values()->all();

        // Kept by name only because the development database has it in its history; it just creates `chatbot_executions`.
        $this->assertSame(['2026_10_09_140000_add_chatbot_executions.php'], $left);
    }
}
