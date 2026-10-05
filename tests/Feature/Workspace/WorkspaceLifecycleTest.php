<?php

namespace Tests\Feature\Workspace;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Edit name/code, enable/disable Workspaces, rename Organizations, create users in a chosen Workspace, page size. */
class WorkspaceLifecycleTest extends TestCase
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
        $this->actingAs($user)->withSession(['workspace_id' => $this->a->id]);

        return $user;
    }

    private function newUser(Workspace $workspace, string $role = 'cliente', string $email = 'new@example.com'): array
    {
        return [
            'name' => 'New', 'email' => $email, 'role' => $role, 'workspace_id' => $workspace->id,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ];
    }

    // --- user list page size -------------------------------------------------------------------------------

    public function test_page_size_accepts_only_the_offered_options_and_keeps_search(): void
    {
        $this->actAs('admin');
        $this->a->users()->attach(User::factory()->count(120)->create()->pluck('id')->all(), ['role' => 'cliente']);
        $match = User::factory()->create(['name' => 'Zeta Buscada']);
        $this->a->users()->attach($match->id, ['role' => 'cliente']);

        foreach ([10, 20, 30, 50, 100] as $size) {
            $this->get('/users?per_page='.$size)->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users.data', $size)->where('filters.perPage', $size)->where('users.meta.per_page', $size)
                ->where('users.meta.total', 122)->where('perPageOptions', [10, 20, 30, 50, 100]));
        }

        $this->get('/users?per_page=100&page=2')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 22)->where('users.meta.current_page', 2));
        $this->get('/users?per_page=7')->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 10)->where('filters.perPage', 10));
        $this->get('/users?per_page=1000')->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 10));
        $this->get('/users?per_page=50&search=buscada')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)->where('users.meta.total', 1)->where('filters.perPage', 50)->where('filters.search', 'buscada'));
    }

    // --- create a user in a chosen Workspace ---------------------------------------------------------------

    public function test_superuser_creates_a_user_in_the_chosen_workspace_with_one_global_identity(): void
    {
        $this->actAs('admin', superuser: true);

        $this->post('/users', $this->newUser($this->b, 'supervisor'))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertSame(['WS_B'], $user->workspaces()->pluck('code')->all());
        $this->assertSame('supervisor', $user->workspaces()->first()->pivot->role);
        $this->assertFalse($user->is_superuser);
    }

    public function test_create_targets_list_only_enabled_workspaces_the_actor_administers(): void
    {
        $this->b->update(['is_active' => false]);
        $this->actAs('admin', superuser: true);
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('createTargets', 1)->where('createTargets.0.code', 'WS_A')->where('createTargets.0.isCurrent', true));

        $this->b->update(['is_active' => true]);
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page->has('createTargets', 2));
    }

    public function test_admin_cannot_create_users_in_other_inactive_or_unknown_workspaces(): void
    {
        $this->actAs('admin');

        $this->post('/users', $this->newUser($this->b))->assertSessionHasErrors('workspace_id');

        $this->actAs('admin', superuser: true);
        $this->b->update(['is_active' => false]);
        $this->post('/users', $this->newUser($this->b))->assertSessionHasErrors('workspace_id');
        $this->post('/users', array_merge($this->newUser($this->a), ['workspace_id' => 9999]))->assertSessionHasErrors('workspace_id');
        $this->post('/users', array_diff_key($this->newUser($this->a), ['workspace_id' => 1]))->assertSessionHasErrors('workspace_id');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_role_must_be_assignable_in_the_chosen_workspace(): void
    {
        $this->actAs('admin');
        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'view-dashboard']);
        $this->actAs('manager');

        $this->post('/users', $this->newUser($this->a, 'admin'))->assertSessionHasErrors('role');
        $this->post('/users', $this->newUser($this->a, 'cliente'))->assertSessionHasNoErrors();
    }

    // --- Workspace code and name ---------------------------------------------------------------------------

    public function test_changing_the_code_keeps_the_id_members_and_switches_the_prelogin_code(): void
    {
        $this->actAs('admin', superuser: true);
        $member = User::factory()->create();
        $this->b->users()->attach($member->id, ['role' => 'supervisor']);

        $this->put('/workspaces/'.$this->b->id, ['name' => 'Beta 2', 'code' => ' nuevo-codigo '])->assertSessionHasNoErrors();

        $fresh = $this->b->fresh();
        $this->assertSame($this->b->id, $fresh->id);
        $this->assertSame('NUEVO-CODIGO', $fresh->code);
        $this->assertSame('Beta 2', $fresh->name);
        $this->assertSame('supervisor', $fresh->users()->find($member->id)->pivot->role);
        $this->assertSame(2, Workspace::count());

        $this->post('/logout');
        $this->post('/pre-login', ['workspace_code' => 'WS_B'])->assertSessionHasErrors('workspace_code');
        $this->post('/pre-login', ['workspace_code' => 'nuevo-codigo'])->assertRedirect('/login');
    }

    public function test_code_is_required_valid_and_unique_when_editing(): void
    {
        $this->actAs('admin', superuser: true);

        $this->put('/workspaces/'.$this->b->id, ['name' => 'Beta', 'code' => ''])->assertSessionHasErrors('code');
        $this->put('/workspaces/'.$this->b->id, ['name' => 'Beta', 'code' => 'bad code!'])->assertSessionHasErrors('code');
        $this->put('/workspaces/'.$this->b->id, ['name' => 'Beta', 'code' => 'ws_a'])->assertSessionHasErrors('code');
        $this->put('/workspaces/'.$this->b->id, ['name' => '', 'code' => 'WS_B'])->assertSessionHasErrors('name');
        // Keeping its own code is not a duplicate.
        $this->put('/workspaces/'.$this->b->id, ['name' => 'Beta', 'code' => 'ws_b'])->assertSessionHasNoErrors();

        $this->assertSame('WS_B', $this->b->fresh()->code);
    }

    // --- enable / disable ----------------------------------------------------------------------------------

    public function test_a_disabled_workspace_blocks_access_but_keeps_everything_and_can_be_reactivated(): void
    {
        $this->actAs('admin', superuser: true);
        $member = User::factory()->create();
        $this->b->users()->attach($member->id, ['role' => 'supervisor']);

        $this->post('/workspaces/'.$this->b->id.'/deactivate')->assertSessionHasNoErrors();

        $this->assertFalse($this->b->fresh()->is_active);
        $this->assertSame('supervisor', $this->b->users()->find($member->id)->pivot->role);
        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaces.list.data', fn ($list) => collect($list)->firstWhere('code', 'WS_B')['isActive'] === false));
        $this->post('/logout');
        $this->post('/pre-login', ['workspace_code' => 'WS_B'])->assertSessionHasErrors('workspace_code');

        $this->actAs('admin', superuser: true);
        $this->post('/workspaces/'.$this->b->id.'/activate')->assertSessionHasNoErrors();
        $this->post('/logout');

        $this->assertTrue($this->b->fresh()->is_active);
        $this->assertSame($this->b->id, $this->b->fresh()->id);
        $this->assertSame(1, $this->b->users()->count());
        $this->post('/pre-login', ['workspace_code' => 'WS_B'])->assertRedirect('/login');
    }

    public function test_an_open_session_in_a_disabled_workspace_is_closed(): void
    {
        $user = User::factory()->create();
        $this->b->users()->attach($user->id, ['role' => 'cliente']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->b->id]);
        $this->get('/dashboard')->assertOk();

        $this->b->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/pre-login');
        $this->assertGuest();
    }

    public function test_only_superusers_can_toggle_workspaces(): void
    {
        $this->actAs('admin');

        $this->post('/workspaces/'.$this->a->id.'/activate')->assertForbidden();
        $this->post('/workspaces/'.$this->b->id.'/deactivate')->assertForbidden();
        $this->assertTrue($this->b->fresh()->is_active);
    }

    // --- Organization name ---------------------------------------------------------------------------------

    public function test_renaming_an_organization_keeps_id_and_relations(): void
    {
        $this->actAs('admin', superuser: true);
        $org = Organization::first();
        $members = $this->membersOf($this->a);

        $this->put('/organizations/'.$org->id, ['name' => '  Cliente Nuevo '])->assertSessionHasNoErrors();

        $fresh = $org->fresh();
        $this->assertSame($org->id, $fresh->id);
        $this->assertSame('Cliente Nuevo', $fresh->name);
        $this->assertSame(2, $fresh->workspaces()->count());
        $this->assertSame($members, $this->membersOf($this->a));
        $this->assertSame(1, Organization::count());
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('workspace.organization', 'Cliente Nuevo'));
    }

    public function test_organization_names_are_validated_and_organizations_can_be_created_but_not_deleted(): void
    {
        $this->actAs('admin', superuser: true);
        $other = Organization::create(['name' => 'Otra']);

        $this->put('/organizations/'.$other->id, ['name' => 'Org'])->assertSessionHasErrors('name');
        $this->put('/organizations/'.$other->id, ['name' => ''])->assertSessionHasErrors('name');
        $this->put('/organizations/'.$other->id, ['name' => 'Otra'])->assertSessionHasNoErrors();
        $this->post('/organizations', ['name' => 'Tercera'])->assertSessionHasNoErrors();
        $this->delete('/organizations/'.$other->id)->assertStatus(405);

        $this->assertSame(3, Organization::count());
    }

    /** @return list<array{user_id: int, role: string}> */
    private function membersOf(Workspace $workspace): array
    {
        return $workspace->users()->orderBy('users.id')->get()->map(fn ($u) => ['user_id' => $u->id, 'role' => $u->pivot->role])->all();
    }
}
