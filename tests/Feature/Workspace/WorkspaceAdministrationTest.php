<?php

namespace Tests\Feature\Workspace;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkspaceAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $org = Organization::create(['name' => 'Org']);
        $this->a = Workspace::create(['organization_id' => $org->id, 'code' => 'WS_A', 'name' => 'Alpha']);
        $this->b = Workspace::create(['organization_id' => $org->id, 'code' => 'WS_B', 'name' => 'Beta']);
    }

    /** Acts as a member of Workspace A (the active one). */
    private function actAs(string $role, bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->a->users()->attach($user->id, ['role' => $role]);

        return tap($user, fn () => $this->actingAs($user)->withSession(['workspace_id' => $this->a->id]));
    }

    private function member(Workspace $workspace, string $role = 'cliente'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_superuser_creates_and_edits_a_workspace(): void
    {
        $this->actAs('admin', superuser: true);

        $this->post('/workspaces', ['organization_id' => Organization::first()->id, 'code' => ' nuevo_ws ', 'name' => 'Nuevo'])
            ->assertSessionHasNoErrors();
        $workspace = Workspace::where('code', 'NUEVO_WS')->firstOrFail();

        $this->put('/workspaces/'.$workspace->id, ['name' => 'Renombrado', 'code' => 'nuevo_ws_2'])->assertSessionHasNoErrors();

        $this->assertSame('Renombrado', $workspace->fresh()->name);
        $this->assertSame('NUEVO_WS_2', $workspace->fresh()->code);
    }

    public function test_workspace_code_must_be_valid_and_unique(): void
    {
        $this->actAs('admin', superuser: true);
        $org = Organization::first()->id;

        $this->post('/workspaces', ['organization_id' => $org, 'code' => 'ws_a', 'name' => 'Dup'])->assertSessionHasErrors('code');
        $this->post('/workspaces', ['organization_id' => $org, 'code' => 'bad code!', 'name' => 'X'])->assertSessionHasErrors('code');
        $this->post('/workspaces', ['organization_id' => 999, 'code' => 'OK_CODE', 'name' => 'X'])->assertSessionHasErrors('organization_id');
    }

    public function test_the_active_workspace_cannot_be_deactivated(): void
    {
        $this->actAs('admin', superuser: true);

        $this->post('/workspaces/'.$this->a->id.'/deactivate')->assertSessionHasErrors('status');
        $this->assertTrue($this->a->fresh()->is_active);
    }

    public function test_workspace_admins_cannot_create_or_edit_workspaces(): void
    {
        $this->actAs('admin');

        $this->post('/workspaces', ['organization_id' => Organization::first()->id, 'code' => 'NEW_WS', 'name' => 'N'])->assertForbidden();
        $this->put('/workspaces/'.$this->a->id, ['name' => 'Hacked', 'code' => 'HACKED'])->assertForbidden();
        $this->post('/workspaces/'.$this->b->id.'/deactivate')->assertForbidden();
        $this->put('/organizations/'.Organization::first()->id, ['name' => 'Hacked'])->assertForbidden();
        $this->post('/workspaces/'.$this->a->id.'/members', ['email' => 'x@example.com', 'role' => 'cliente'])->assertForbidden();
        $this->assertSame('Alpha', $this->a->fresh()->name);
    }

    public function test_users_without_manage_users_cannot_reach_workspace_routes(): void
    {
        $this->actAs('cliente');
        $other = $this->member($this->a);

        $this->delete("/workspaces/{$this->a->id}/members/{$other->id}")->assertForbidden();
    }

    public function test_listing_shows_only_the_workspaces_the_actor_administers(): void
    {
        $this->actAs('admin');
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaces.list.data.0.code', 'WS_A')->has('workspaces.list.data', 1)->where('workspaces.canManage', false));

        $this->actAs('admin', superuser: true);
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('workspaces.list.data', 2)->where('workspaces.canManage', true)->has('workspaces.organizationOptions', 1)->has('organizations.data', 1));
    }

    public function test_an_admin_cannot_read_or_manage_another_workspace(): void
    {
        $this->actAs('admin');
        $outsider = $this->member($this->b);

        $this->get('/users?workspace='.$this->b->id)->assertInertia(fn (AssertableInertia $page) => $page->where('workspaces.selected', null));
        $this->put("/workspaces/{$this->b->id}/members/{$outsider->id}", ['role' => 'supervisor'])->assertNotFound();
        $this->delete("/workspaces/{$this->b->id}/members/{$outsider->id}")->assertNotFound();

        $this->assertSame('cliente', $this->b->users()->find($outsider->id)->pivot->role);
    }

    public function test_superuser_assigns_an_existing_user_and_changes_the_role(): void
    {
        $this->actAs('admin', superuser: true);
        $user = $this->member($this->a);

        $this->post("/workspaces/{$this->b->id}/members", ['email' => $user->email, 'role' => 'cliente'])->assertSessionHasNoErrors();
        $this->put("/workspaces/{$this->b->id}/members/{$user->id}", ['role' => 'supervisor'])->assertSessionHasNoErrors();

        $this->assertSame('supervisor', $this->b->users()->find($user->id)->pivot->role);
        $this->assertSame('cliente', $this->a->users()->find($user->id)->pivot->role, 'Other memberships are untouched.');
        $this->post("/workspaces/{$this->b->id}/members", ['email' => $user->email, 'role' => 'cliente'])->assertSessionHasErrors('email');
        $this->post("/workspaces/{$this->b->id}/members", ['email' => 'nobody@example.com', 'role' => 'cliente'])->assertSessionHasErrors('email');
    }

    public function test_removing_a_member_keeps_the_user_and_the_other_memberships(): void
    {
        $this->actAs('admin');
        $user = $this->member($this->a);
        $this->b->users()->attach($user->id, ['role' => 'cliente']);

        $this->delete("/workspaces/{$this->a->id}/members/{$user->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'is_active' => true]);
        $this->assertSame(['WS_B'], $user->workspaces()->pluck('code')->all());
    }

    public function test_admin_changes_roles_inside_their_workspace_without_escalation(): void
    {
        $this->actAs('admin');
        $user = $this->member($this->a);
        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'view-dashboard']);

        $this->put("/workspaces/{$this->a->id}/members/{$user->id}", ['role' => 'manager'])->assertSessionHasNoErrors();
        $this->assertSame('manager', $this->a->users()->find($user->id)->pivot->role);

        // A manager cannot hand out or touch the admin role (more permissions than theirs).
        $manager = $this->actAs('manager');
        $victim = $this->member($this->a, 'admin');
        $this->put("/workspaces/{$this->a->id}/members/{$user->id}", ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->delete("/workspaces/{$this->a->id}/members/{$victim->id}")->assertSessionHasErrors('member');
        $this->assertTrue($this->a->users()->whereKey($victim->id)->exists());
        $this->assertTrue($this->a->users()->whereKey($manager->id)->exists());
    }

    public function test_actors_cannot_remove_or_change_their_own_membership(): void
    {
        $admin = $this->actAs('admin');

        $this->delete("/workspaces/{$this->a->id}/members/{$admin->id}")->assertSessionHasErrors('member');
        $this->put("/workspaces/{$this->a->id}/members/{$admin->id}", ['role' => 'cliente'])->assertSessionHasErrors('role');
        $this->assertSame('admin', $this->a->users()->find($admin->id)->pivot->role);
    }
}
