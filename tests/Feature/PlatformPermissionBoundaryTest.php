<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M2: saving a role from the editor never removes a permission the editor does not show (a broad permission that the seeder has not split yet, or
 * one the catalog no longer describes). M3: `use-conversation-demo` (and every `platform` permission) only counts in the administrative Workspace:
 * a client's administrator can neither use it nor hand it out, and no migration of a broad permission grants it.
 */
class PlatformPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
    }

    private function signIn(string $role, bool $superuser = false): User
    {
        $user = User::factory()->create();
        if ($superuser) {
            $user->forceFill(['is_superuser' => true])->save();
        }
        $this->actingAsWorkspaceMember($user, $role, administrative: $superuser);

        return $user;
    }

    /** @return list<string> */
    private function held(string $role): array
    {
        $names = Role::findByName($role, 'web')->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    // --- M2: the editor keeps what it does not show -----------------------------------------------------------------------------------------

    public function test_saving_a_role_keeps_the_permissions_the_editor_does_not_show_and_changes_the_ones_it_does(): void
    {
        Permission::findOrCreate('manage-chatbots', 'web');
        Permission::findOrCreate('permiso-antiguo', 'web');
        Role::findOrCreate('legado', 'web')->syncPermissions(['view-dashboard', 'view-users', 'manage-chatbots', 'permiso-antiguo']);
        $this->signIn('admin', superuser: true);

        $this->put('/roles/'.Role::findByName('legado', 'web')->id, ['permissions' => ['view-dashboard', 'view-chatbots']])->assertSessionHasNoErrors();

        $this->assertSame(['manage-chatbots', 'permiso-antiguo', 'view-chatbots', 'view-dashboard'], $this->held('legado'));
    }

    public function test_a_broad_permission_kept_by_an_edit_is_still_split_by_the_seeder_afterwards(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $fine = ['create-chatbots', 'edit-chatbots', 'activate-chatbots', 'manage-chatbot-channels', 'manage-agent-tokens'];
        $this->signIn('admin', superuser: true);
        Role::findOrCreate('legado', 'web')->syncPermissions(['view-dashboard', 'view-chatbots']);
        Permission::whereIn('name', $fine)->delete();
        Permission::findOrCreate('manage-chatbots', 'web');
        Role::findByName('legado', 'web')->givePermissionTo('manage-chatbots');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The role is saved from the editor BEFORE the seeder ran: the broad permission must survive so the seeder can still expand it.
        $this->put('/roles/'.Role::findByName('legado', 'web')->id, ['permissions' => ['view-dashboard', 'view-chatbots']])->assertSessionHasNoErrors();
        $this->assertContains('manage-chatbots', $this->held('legado'));

        app(RolesAndPermissionsSeeder::class)->run();

        $held = $this->held('legado');
        foreach ($fine as $name) {
            $this->assertContains($name, $held, "the role keeps [{$name}] through edit + seed");
        }
        $this->assertNotContains('manage-chatbots', $held, 'the broad permission is retired after being expanded');
    }

    public function test_the_editor_still_cannot_hand_out_a_permission_outside_the_catalog(): void
    {
        Permission::findOrCreate('permiso-antiguo', 'web');
        $this->signIn('admin', superuser: true);

        $this->put('/roles/'.Role::findByName('cliente', 'web')->id, ['permissions' => ['permiso-antiguo']])->assertSessionHasErrors('permissions.0');
        $this->assertSame(['view-dashboard'], $this->held('cliente'));
    }

    // --- M3: use-conversation-demo is the platform's alone ----------------------------------------------------------------------------------

    public function test_a_client_administrator_neither_holds_nor_uses_the_demo_permission_even_if_the_role_has_it(): void
    {
        $this->assertContains('use-conversation-demo', $this->held('admin'), 'the shared role `admin` carries it in the catalog');
        $this->signIn('admin');

        $this->get('/conversations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('demo.canManage', false)
            ->where('auth.user.permissions', fn ($permissions) => ! collect($permissions)->contains('use-conversation-demo')));

        $this->post('/conversations/demo/generate')->assertForbidden();
        $this->post('/conversations/demo/clean')->assertForbidden();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_a_client_administrator_can_not_grant_the_demo_permission_through_any_role(): void
    {
        // A role that carries it is assignable (the permission is inert in a client Workspace, as in the shared `admin` role), but nothing it
        // carries from the platform ever takes effect for the member.
        Role::findOrCreate('con-demo', 'web')->syncPermissions(['view-dashboard', 'use-conversation-demo']);
        $this->signIn('admin');
        $workspace = Workspace::orderBy('id')->first();

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('createTargets.0.roles', fn ($roles) => collect($roles)->contains('con-demo')));
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => 'con-demo']);

        $this->actingAs($member)->withSession(['workspace_id' => $workspace->id]);
        $this->post('/conversations/demo/generate')->assertForbidden();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_a_role_with_a_real_permission_the_client_administrator_lacks_is_still_not_assignable(): void
    {
        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'create-users', 'view-dashboard']);
        $this->signIn('manager');

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('createTargets.0.roles', fn ($roles) => ! collect($roles)->contains('admin') && collect($roles)->contains('cliente')));
    }

    public function test_a_client_administrator_can_not_put_the_demo_permission_into_a_role(): void
    {
        $this->signIn('admin');
        $cliente = Role::findByName('cliente', 'web');

        $this->put('/roles/'.$cliente->id, ['permissions' => ['view-dashboard', 'use-conversation-demo']])->assertForbidden();
        $this->post('/roles', ['name' => 'intruso', 'permissions' => ['use-conversation-demo']])->assertForbidden();

        $this->assertSame(['view-dashboard'], $this->held('cliente'));
        $this->assertFalse(Role::where('name', 'intruso')->exists());
    }

    public function test_the_platform_administration_keeps_the_demo_permission_in_the_administrative_workspace(): void
    {
        $this->signIn('admin', superuser: true);

        $this->get('/conversations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('use-conversation-demo')));
    }

    public function test_no_broad_permission_migration_grants_a_platform_permission(): void
    {
        $platform = array_keys(array_filter(config('permissions.permissions'), fn (array $permission) => $permission['platform'] ?? false));

        // The broad permissions of plans and modules are themselves the platform's: only a role that already held one receives its fine ones.
        foreach (collect(config('permissions.legacy'))->except(['manage-plans', 'manage-modules']) as $broad => $split) {
            $this->assertSame([], array_values(array_intersect($split['grants'], $platform)), "[{$broad}] must not hand a platform permission to a client's role");
        }
    }

    public function test_the_seeder_does_not_give_the_demo_permission_to_a_role_that_only_managed_conversations(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Permission::where('name', 'use-conversation-demo')->delete();
        Role::findOrCreate('supervisor-conv', 'web')->syncPermissions(['view-dashboard', 'view-conversations', 'manage-conversations']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(RolesAndPermissionsSeeder::class)->run();

        $this->assertNotContains('use-conversation-demo', $this->held('supervisor-conv'));
        $this->assertContains('use-conversation-demo', $this->held('admin'));
    }
}
