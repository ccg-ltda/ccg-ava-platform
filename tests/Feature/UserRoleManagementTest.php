<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
    }

    private function actAs(string $role, bool $superuser = false): User
    {
        $user = User::factory()->create();
        if ($superuser) {
            $user->forceFill(['is_superuser' => true])->save();
        }
        $this->actingAsWorkspaceMember($user, $role, administrative: (bool) $user->is_superuser);

        return $user;
    }

    private function otherWorkspace(): Workspace
    {
        return Workspace::create([
            'organization_id' => Organization::firstOrFail()->id,
            'code' => 'OTHER_WS',
            'name' => 'Other',
        ]);
    }

    private function newUserPayload(string $role, string $email = 'new@example.com'): array
    {
        return [
            'name' => 'New User', 'email' => $email, 'role' => $role, 'workspace_id' => Workspace::orderBy('id')->first()->id,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ];
    }

    // --- user creation: always inside the active Workspace -------------------------------------------------

    public function test_created_users_belong_to_the_active_workspace_with_the_chosen_role_only(): void
    {
        $this->actAs('admin');

        $this->post('/users', $this->newUserPayload('supervisor'))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame(['TEST_WS'], $user->workspaces()->pluck('code')->all());
        $this->assertSame('supervisor', $user->workspaces()->first()->pivot->role);
        $this->assertFalse($user->is_superuser);
        $this->assertSame(0, $user->roles()->count(), 'No global role is written.');
    }

    public function test_unknown_roles_are_rejected(): void
    {
        $this->actAs('admin');

        $this->post('/users', $this->newUserPayload('does-not-exist'))->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_a_role_with_permissions_the_actor_lacks_cannot_be_assigned(): void
    {
        // "manager" can manage users but not settings; "admin" has settings, so it is above it.
        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'create-users', 'view-dashboard']);
        $this->actAs('manager');

        $this->post('/users', $this->newUserPayload('admin'))->assertSessionHasErrors('role');
        $this->post('/users', $this->newUserPayload('cliente'))->assertSessionHasNoErrors();

        $this->get('/users')->assertInertia(function (AssertableInertia $page) {
            $names = $page->toArray()['props']['createTargets'][0]['roles'];

            $this->assertSame(['cliente', 'manager'], $names);
        });
    }

    public function test_a_user_cannot_change_their_own_role(): void
    {
        $admin = $this->actAs('admin');

        $this->put('/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'cliente'])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', Workspace::orderBy('id')->first()->users()->find($admin->id)->pivot->role);
    }

    public function test_roles_are_per_workspace_and_not_written_globally_on_update(): void
    {
        $this->actAs('admin');
        $other = $this->otherWorkspace();
        $shared = User::factory()->create();
        Workspace::orderBy('id')->first()->users()->attach($shared->id, ['role' => 'cliente']);
        $other->users()->attach($shared->id, ['role' => 'admin']);

        $this->put('/users/'.$shared->id, ['name' => $shared->name, 'email' => $shared->email, 'role' => 'supervisor'])
            ->assertSessionHasNoErrors();

        $this->assertSame('supervisor', Workspace::orderBy('id')->first()->users()->find($shared->id)->pivot->role);
        $this->assertSame('admin', $other->users()->find($shared->id)->pivot->role);
        $this->assertSame(0, $shared->roles()->count());
    }

    // --- identity warning flag -----------------------------------------------------------------------------

    public function test_users_page_flags_accounts_whose_identity_cannot_be_edited(): void
    {
        $this->actAs('admin');
        $workspace = Workspace::orderBy('id')->first();
        $solo = User::factory()->create(['name' => 'Solo']);
        $shared = User::factory()->create(['name' => 'Shared']);
        $super = User::factory()->create(['name' => 'Super']);
        $super->forceFill(['is_superuser' => true])->save();
        $other = $this->otherWorkspace();
        $workspace->users()->attach([$solo->id => ['role' => 'cliente'], $shared->id => ['role' => 'cliente'], $super->id => ['role' => 'cliente']]);
        $other->users()->attach($shared->id, ['role' => 'cliente']);

        $this->get('/users')->assertInertia(function (AssertableInertia $page) {
            $locked = collect($page->toArray()['props']['users']['data'])->pluck('identityLocked', 'name');

            $this->assertFalse($locked['Solo']);
            $this->assertTrue($locked['Shared']);
            $this->assertTrue($locked['Super']);
        });
    }

    public function test_superuser_sees_no_locked_accounts_and_can_edit_them(): void
    {
        $this->actAs('admin', superuser: true);
        $shared = User::factory()->create();
        Workspace::orderBy('id')->first()->users()->attach($shared->id, ['role' => 'cliente']);
        $this->otherWorkspace()->users()->attach($shared->id, ['role' => 'cliente']);

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data.0.identityLocked', false));

        $this->put('/users/'.$shared->id, ['name' => 'Renamed', 'email' => $shared->email, 'role' => 'cliente'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $shared->fresh()->name);
    }

    public function test_backend_still_rejects_identity_changes_of_shared_accounts(): void
    {
        $this->actAs('admin');
        $shared = User::factory()->create();
        Workspace::orderBy('id')->first()->users()->attach($shared->id, ['role' => 'cliente']);
        $this->otherWorkspace()->users()->attach($shared->id, ['role' => 'cliente']);

        $this->put('/users/'.$shared->id, ['name' => 'Hacked', 'email' => 'h@example.com', 'role' => 'cliente'])
            ->assertSessionHasErrors('email');
        $this->assertNotSame('Hacked', $shared->fresh()->name);
    }

    // --- permissions authorize routes (not role names) ----------------------------------------------------

    public function test_routes_are_authorized_by_the_permissions_of_the_workspace_role(): void
    {
        Role::findOrCreate('settings-only', 'web')->syncPermissions(['view-settings', 'view-integrations', 'view-dashboard']);
        $this->actAs('settings-only');

        $this->get('/settings')->assertOk();
        $this->get('/integrations')->assertOk();
        $this->get('/users')->assertForbidden();
    }

    public function test_changing_a_role_permissions_changes_what_its_users_can_do(): void
    {
        Role::findOrCreate('manager', 'web')->syncPermissions(['view-dashboard']);
        $this->actAs('manager');
        $this->get('/users')->assertForbidden();

        Role::findByName('manager', 'web')->givePermissionTo('manage-users');

        $this->get('/users')->assertOk();
    }

    // --- role catalog: superusers only ---------------------------------------------------------------------

    public function test_workspace_admins_cannot_manage_the_role_catalog(): void
    {
        $this->actAs('admin');
        $cliente = Role::findByName('cliente', 'web');

        $this->post('/roles', ['name' => 'intruder', 'permissions' => ['manage-users']])->assertForbidden();
        $this->put('/roles/'.$cliente->id, ['permissions' => ['manage-users']])->assertForbidden();

        $this->assertFalse(Role::where('name', 'intruder')->exists());
        $this->assertSame(['view-dashboard'], $cliente->fresh()->permissions->pluck('name')->all());
    }

    public function test_non_admin_roles_cannot_reach_role_routes_either(): void
    {
        $this->actAs('supervisor', superuser: true);

        // A superuser acting as supervisor still lacks manage-users in this Workspace.
        $this->post('/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
    }

    public function test_superuser_can_create_a_role_with_permissions(): void
    {
        $this->actAs('admin', superuser: true);

        $this->post('/roles', ['name' => 'coordinador', 'permissions' => ['view-dashboard', 'view-users']])
            ->assertSessionHasNoErrors();

        $role = Role::findByName('coordinador', 'web');
        $this->assertEqualsCanonicalizing(['view-dashboard', 'view-users'], $role->permissions->pluck('name')->all());
    }

    public function test_role_names_are_validated_and_unique(): void
    {
        $this->actAs('admin', superuser: true);

        $this->post('/roles', ['name' => 'Bad Name!', 'permissions' => []])->assertSessionHasErrors('name');
        $this->post('/roles', ['name' => 'cliente', 'permissions' => []])->assertSessionHasErrors('name');
        $this->post('/roles', ['name' => 'ok', 'permissions' => ['not-a-permission']])->assertSessionHasErrors('permissions.0');
    }

    private function clientPermissionCount(): int
    {
        return count(array_filter(config('permissions.permissions'), fn (array $p) => ! ($p['platform'] ?? false)));
    }

    public function test_superuser_can_edit_permissions_but_not_the_protected_admin_role(): void
    {
        $this->actAs('admin', superuser: true);

        $this->put('/roles/'.Role::findByName('cliente', 'web')->id, ['permissions' => ['view-dashboard', 'view-users']])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['view-dashboard', 'view-users'], Role::findByName('cliente', 'web')->permissions->pluck('name')->all());

        $admin = Role::findByName('admin', 'web');
        $this->put('/roles/'.$admin->id, ['permissions' => []])->assertSessionHasErrors('permissions');
        $this->assertCount(count(config('permissions.permissions')) - count(RolesAndPermissionsSeeder::reserved()), $admin->fresh()->permissions);
    }

    public function test_roles_cannot_be_deleted_even_by_superusers(): void
    {
        $this->actAs('admin', superuser: true);
        $unused = Role::findOrCreate('unused', 'web');

        $this->delete('/roles/'.$unused->id)->assertStatus(405);
        $this->delete('/roles/'.Role::findByName('admin', 'web')->id)->assertStatus(405);

        $this->assertTrue(Role::where('name', 'unused')->exists());
        $this->assertTrue(Role::where('name', 'admin')->exists());
    }

    public function test_only_superusers_receive_the_catalog_management_data(): void
    {
        // A Workspace administrator is offered the permissions of a Workspace; the platform ones are for the platform administration alone.
        $this->actAs('admin');
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManageRoles', false)->has('permissionNames', $this->clientPermissionCount()));

        $this->actAs('admin', superuser: true);
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManageRoles', true)->has('permissionNames', count(config('permissions.permissions'))));
    }

    // --- isolation ----------------------------------------------------------------------------------------

    public function test_users_of_another_workspace_cannot_be_read_or_changed(): void
    {
        $this->actAs('admin');
        $outsider = User::factory()->create();
        $this->otherWorkspace()->users()->attach($outsider->id, ['role' => 'cliente']);

        $this->put('/users/'.$outsider->id, ['name' => 'X', 'email' => 'x@example.com', 'role' => 'cliente'])->assertNotFound();
        $this->post('/users/'.$outsider->id.'/deactivate')->assertNotFound();
        $this->get('/users')->assertInertia(function (AssertableInertia $page) use ($outsider) {
            $emails = collect($page->toArray()['props']['users']['data'])->pluck('email');

            $this->assertFalse($emails->contains($outsider->email));
        });
    }

    public function test_users_cannot_be_deleted_from_the_admin_routes(): void
    {
        $this->actAs('admin', superuser: true);
        $member = User::factory()->create();
        Workspace::orderBy('id')->first()->users()->attach($member->id, ['role' => 'cliente']);

        $this->delete('/users/'.$member->id)->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_permission_catalog_is_not_editable_through_the_roles_endpoint(): void
    {
        $this->actAs('admin', superuser: true);
        $before = Permission::count();

        $this->post('/roles', ['name' => 'extra', 'permissions' => ['invented-permission']])->assertSessionHasErrors('permissions.0');

        $this->assertSame($before, Permission::count());
    }

    // --- listing: search and pagination (inside the active Workspace) --------------------------------------

    public function test_users_are_paginated_and_report_totals(): void
    {
        $this->actAs('admin');
        $workspace = Workspace::orderBy('id')->first();
        $workspace->users()->attach(User::factory()->count(20)->create()->pluck('id')->all(), ['role' => 'cliente']);

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 10)
            ->where('users.meta.total', 21)
            ->where('users.meta.last_page', 3)
            ->where('users.meta.from', 1)
            ->where('users.meta.to', 10));

        $this->get('/users?page=3')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.meta.current_page', 3));
    }

    public function test_search_filters_by_name_or_email_only_inside_the_workspace(): void
    {
        $this->actAs('admin');
        $workspace = Workspace::orderBy('id')->first();
        $match = User::factory()->create(['name' => 'Zulema Quiroga', 'email' => 'zq@example.com']);
        $workspace->users()->attach($match->id, ['role' => 'cliente']);
        $workspace->users()->attach(User::factory()->create(['name' => 'Otro Nombre'])->id, ['role' => 'cliente']);
        $outsider = User::factory()->create(['name' => 'Zulema Forastera']);
        $this->otherWorkspace()->users()->attach($outsider->id, ['role' => 'cliente']);

        $this->get('/users?search=zulema')->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['users']['data'])->pluck('name')->all();

            $this->assertSame(['Zulema Quiroga'], $names);
        });
        $this->get('/users?search=ZQ@example')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)->where('filters.search', 'ZQ@example'));
    }

    public function test_search_treats_wildcards_as_plain_text(): void
    {
        $this->actAs('admin');

        $this->get('/users?search=%25')->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));
        $this->get('/users?search=_')->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));
    }

    public function test_role_usage_counts_only_the_active_workspace(): void
    {
        $this->actAs('admin');
        $this->otherWorkspace()->users()->attach(User::factory()->count(3)->create()->pluck('id')->all(), ['role' => 'cliente']);
        Workspace::orderBy('id')->first()->users()->attach(User::factory()->create()->id, ['role' => 'cliente']);

        $this->get('/users')->assertInertia(function (AssertableInertia $page) {
            $counts = collect($page->toArray()['props']['roles']['data'])->pluck('usersInWorkspace', 'name');

            $this->assertSame(1, $counts['cliente']);
            $this->assertSame(1, $counts['admin']);
        });
    }

    public function test_permission_catalog_is_visible_to_workspace_admins_but_read_only(): void
    {
        $this->actAs('admin');

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('permissionNames', $this->clientPermissionCount())->where('canManageRoles', false));
        $this->post('/roles', ['name' => 'x', 'permissions' => ['manage-users']])->assertForbidden();
    }

    public function test_actions_flash_a_success_message(): void
    {
        $this->actAs('admin');

        $this->post('/users', $this->newUserPayload('cliente'))->assertSessionHas('success');
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.success', fn ($message) => str_contains((string) $message, 'creado')));
    }

    // --- the permission editor and the permission list read the catalog by resource ---------------------------------------

    public function test_the_catalog_sent_to_the_editor_groups_by_resource_and_says_what_each_one_needs(): void
    {
        $this->actAs('admin', superuser: true);

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page->where('permissionCatalog', function ($catalog) {
            $groups = collect($catalog['groups']);
            $items = collect($catalog['items'])->keyBy('name');

            $this->assertSame('Clientes', $groups['customers']['label']);
            $this->assertSame('view-customers', $groups['customers']['list']);
            $this->assertSame('Clientes', $groups['customers']['moduleName']);
            $this->assertSame('view-customers', $items['edit-customers']['requires']);
            $this->assertNull($items['view-customers']['requires'], 'the permission that opens a page needs nothing else');
            $this->assertNull($items['view-plans']['requires']);
            $this->assertSame('view-plans', $items['edit-plans']['requires']);
            $this->assertTrue($items['view-plans']['platform']);
            // The platform administration sees the whole catalog, and only what the catalog describes (never a bare technical name).
            $this->assertCount(count(config('permissions.permissions')), $items);
            foreach ($items as $item) {
                $this->assertNotSame($item['name'], $item['label'], "[{$item['name']}] has a readable label");
                $this->assertNotEmpty($item['description'], "[{$item['name']}] says what it allows");
            }

            return true;
        }));
    }

    public function test_the_permission_list_is_filtered_by_resource_and_by_text_on_the_server(): void
    {
        $this->actAs('admin', superuser: true);
        $total = Permission::count();

        $this->get('/users?permissions_per_page=100&permission_group=customers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === collect(config('permissions.permissions'))->filter(fn ($p) => $p['group'] === 'customers')->keys()->sort()->values()->all())
            ->where('permissions.total', $total));

        // The text looks in the name, the label, the description and the resource, ignoring case and accents.
        $this->get('/users?permissions_per_page=100&permission_q=ANONIMIZAR')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['anonymize-customers'])
            ->where('permissions.meta.total', 1)->where('permissions.total', $total));

        $this->get('/users?permissions_per_page=100&permission_group=customers&permission_q=detalle')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['view-customer-details']));

        $this->get('/users?permission_group=nope')->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.data', []));
    }

    public function test_the_role_scope_is_readable_without_the_permission_names_being_truncated(): void
    {
        $this->actAs('admin');

        $this->get('/users?roles_per_page=100')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles.data', function ($roles) {
                $admin = collect($roles)->firstWhere('name', 'admin');
                // The page gets the complete list of the role: the summary by resource is built from it on the client.
                $this->assertGreaterThan(60, count($admin['permissions']));
                $this->assertContains('edit-customers', $admin['permissions']);

                return true;
            }));
    }

    public function test_a_role_keeps_exactly_the_combination_it_was_given(): void
    {
        $this->actAs('admin', superuser: true);
        $role = Role::findOrCreate('combinado', 'web');

        $this->put('/roles/'.$role->id, ['permissions' => ['view-customers', 'create-customers']])->assertSessionHasNoErrors();

        // Selecting one never grants another: no view of the detail, no edit, no remove.
        $this->assertEqualsCanonicalizing(['view-customers', 'create-customers'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'role', 'resource_id' => $role->id]);
    }
}
