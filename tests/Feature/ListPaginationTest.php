<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Roles, permissions, Workspaces and Organizations page on the server with the same sizes as users. */
class ListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function actAsSuperuser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        $this->actingAsWorkspaceMember($user, 'admin');

        return $user;
    }

    public function test_every_list_offers_the_same_sizes_and_falls_back_to_ten(): void
    {
        $this->actAsSuperuser();
        $org = Organization::first();
        foreach (range(1, 60) as $n) {
            Role::findOrCreate("role-{$n}", 'web');
            Permission::findOrCreate("perm-{$n}", 'web');
            Workspace::create(['organization_id' => $org->id, 'code' => "WS_{$n}", 'name' => "Workspace {$n}"]);
            Organization::create(['name' => "Org {$n}"]);
        }

        foreach (['roles' => 'roles', 'permissions' => 'permissions', 'workspaces' => 'workspaces.list', 'organizations' => 'organizations'] as $section => $prop) {
            foreach ([10, 20, 30, 50] as $size) {
                $this->get("/users?{$section}_per_page={$size}")->assertInertia(fn (AssertableInertia $page) => $page
                    ->has("{$prop}.data", $size)->where("{$prop}.meta.per_page", $size));
            }

            $this->get("/users?{$section}_per_page=7")->assertInertia(fn (AssertableInertia $page) => $page->has("{$prop}.data", 10));
            $this->get("/users?{$section}_per_page=50&{$section}_page=2")->assertInertia(fn (AssertableInertia $page) => $page
                ->where("{$prop}.meta.current_page", 2)->where("{$prop}.meta.per_page", 50)->where("{$prop}.meta.from", 51));
        }
    }

    public function test_one_hundred_per_page_and_independent_lists(): void
    {
        $this->actAsSuperuser();
        foreach (range(1, 110) as $n) {
            Role::findOrCreate("role-{$n}", 'web');
        }

        $this->get('/users?roles_per_page=100')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('roles.data', 100)->where('roles.meta.total', 113)
            // Other lists keep their own size: it is not shared.
            ->where('users.meta.per_page', 10)->where('permissions.meta.per_page', 10));
    }

    public function test_permissions_matrix_rows_know_which_roles_grant_them(): void
    {
        $this->actAsSuperuser();

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('permissions.data', fn ($rows) => collect($rows)->firstWhere('name', 'manage-users')['roles'] === ['admin'])
            ->has('permissionRoles', 3)->has('permissionNames', 4));
    }

    public function test_partial_reloads_only_resolve_the_requested_list(): void
    {
        $this->actAsSuperuser();

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'Users/Index', 'X-Inertia-Partial-Data' => 'roles'])
            ->get('/users?roles_per_page=20')
            ->assertOk()
            ->assertJsonPath('props.roles.meta.per_page', 20)
            ->assertJsonMissingPath('props.users')
            ->assertJsonMissingPath('props.workspaces');
    }

    public function test_the_organizations_list_is_only_for_superusers(): void
    {
        $admin = User::factory()->create();
        $this->actingAsWorkspaceMember($admin, 'admin');

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page->where('organizations', null));
    }

    public function test_changes_return_to_the_page_the_user_was_on(): void
    {
        $this->actAsSuperuser();
        $org = Organization::first();

        $this->from('/users?tab=organizaciones&organizations_per_page=50')
            ->put('/organizations/'.$org->id, ['name' => 'Renombrada'])
            ->assertRedirect('/users?tab=organizaciones&organizations_per_page=50');
    }

    // --- members of a Workspace and the users status filter ------------------------------------------------

    public function test_workspace_members_page_on_the_server_with_the_same_sizes(): void
    {
        $this->actAsSuperuser();
        $workspace = Workspace::first();
        $workspace->users()->attach(User::factory()->count(35)->create()->pluck('id')->all(), ['role' => 'cliente']);

        $this->get("/users?workspace={$workspace->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces.selected.members.data', 10)->where('workspaces.selected.members.meta.total', 36));
        $this->get("/users?workspace={$workspace->id}&members_per_page=30&members_page=2")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces.selected.members.data', 6)->where('workspaces.selected.members.meta.per_page', 30)
            ->where('workspaces.selected.members.meta.current_page', 2)
            // The Workspaces list keeps its own size.
            ->where('workspaces.list.meta.per_page', 10));
        $this->get("/users?workspace={$workspace->id}&members_per_page=7")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces.selected.members.data', 10));
    }

    public function test_the_status_filter_combines_with_search_and_page_size(): void
    {
        $this->actAsSuperuser();
        $workspace = Workspace::first();
        foreach (range(1, 12) as $n) {
            $user = User::factory()->create(['name' => "Persona {$n}"]);
            $user->forceFill(['is_active' => $n % 3 !== 0])->save();
            $workspace->users()->attach($user->id, ['role' => 'cliente']);
        }

        $this->get('/users?status=inactive')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 4)->where('filters.status', 'inactive')
            ->where('users.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['isActive'] === false)));
        $this->get('/users?status=active&per_page=20')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 9)->where('users.meta.total', 9)->where('filters.status', 'active'));
        $this->get('/users?status=active&search=persona 1')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['isActive'] && str_contains($row['name'], 'Persona 1'))));
        $this->get('/users?status=bogus')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'all')->where('users.meta.total', 13));
    }

    public function test_status_messages_use_one_vocabulary(): void
    {
        $this->actAsSuperuser();
        $user = User::factory()->create();
        Workspace::first()->users()->attach($user->id, ['role' => 'cliente']);
        $other = Organization::create(['name' => 'Otra']);
        $workspace = Workspace::create(['organization_id' => $other->id, 'code' => 'OTRO', 'name' => 'Otro']);

        $this->post("/users/{$user->id}/deactivate")->assertSessionHas('success', 'Usuario desactivado correctamente');
        $this->post("/users/{$user->id}/activate")->assertSessionHas('success', 'Usuario activado correctamente');
        $this->post("/workspaces/{$workspace->id}/deactivate")->assertSessionHas('success', 'Workspace desactivado correctamente');
        $this->post("/workspaces/{$workspace->id}/activate")->assertSessionHas('success', 'Workspace activado correctamente');
        $this->post("/organizations/{$other->id}/deactivate")->assertSessionHas('success', 'Organización desactivada correctamente');
        $this->post("/organizations/{$other->id}/activate")->assertSessionHas('success', 'Organización activada correctamente');
        $this->put("/workspaces/{$workspace->id}", ['name' => 'Otro 2', 'code' => 'OTRO'])->assertSessionHas('success', 'Workspace actualizado correctamente');
        $this->put("/organizations/{$other->id}", ['name' => 'Otra 2'])->assertSessionHas('success', 'Organización actualizada correctamente');
        $this->post('/organizations', ['name' => 'Nueva'])->assertSessionHas('success', 'Organización creada correctamente');
    }

    // --- Organization enable / disable ---------------------------------------------------------------------

    public function test_superuser_can_deactivate_and_reactivate_an_organization_without_losing_anything(): void
    {
        $this->actAsSuperuser();
        $other = Organization::create(['name' => 'Cliente']);
        $workspace = Workspace::create(['organization_id' => $other->id, 'code' => 'CLI_WS', 'name' => 'Cliente WS']);
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => 'cliente']);

        $this->post('/organizations/'.$other->id.'/deactivate')->assertSessionHasNoErrors();

        $this->assertFalse($other->fresh()->is_active);
        $this->assertSame(1, $workspace->users()->count());
        $this->assertFalse(Workspace::available()->whereKey($workspace->id)->exists(), 'Its Workspaces cannot be used while disabled.');
        $this->post('/logout');
        $this->post('/pre-login', ['workspace_code' => 'CLI_WS'])->assertSessionHasErrors('workspace_code');

        $this->actAsSuperuser();
        $this->post('/organizations/'.$other->id.'/activate')->assertSessionHasNoErrors();

        $this->assertTrue($other->fresh()->is_active);
        $this->assertSame($other->id, $other->fresh()->id);
        $this->assertTrue(Workspace::available()->whereKey($workspace->id)->exists());
    }

    public function test_the_organization_of_the_active_workspace_cannot_be_deactivated(): void
    {
        $this->actAsSuperuser();

        $this->post('/organizations/'.Organization::first()->id.'/deactivate')->assertSessionHasErrors('status');
        $this->assertTrue(Organization::first()->is_active);
    }

    public function test_workspace_admins_cannot_toggle_organizations(): void
    {
        $admin = User::factory()->create();
        $this->actingAsWorkspaceMember($admin, 'admin');
        $org = Organization::first();

        $this->post('/organizations/'.$org->id.'/deactivate')->assertForbidden();
        $this->post('/organizations/'.$org->id.'/activate')->assertForbidden();
        $this->assertTrue($org->fresh()->is_active);
    }
}
