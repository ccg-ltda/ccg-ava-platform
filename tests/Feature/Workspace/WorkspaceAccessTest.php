<?php

namespace Tests\Feature\Workspace;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $prd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();

        $org = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $org->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->prd = Workspace::create(['organization_id' => $org->id, 'code' => 'OPERACION_PRD', 'name' => 'Operación']);
    }

    private function member(Workspace $workspace, string $role = 'cliente'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function selectWorkspace(string $code = 'DESARROLLO_DEV')
    {
        return $this->post('/pre-login', ['workspace_code' => $code]);
    }

    private function loginTo(User $user, string $code = 'DESARROLLO_DEV')
    {
        $this->selectWorkspace($code);

        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_pre_login_screen_is_rendered_and_root_redirects_to_it(): void
    {
        $this->get('/pre-login')->assertOk()->assertInertia(fn ($page) => $page->component('Auth/PreLogin'));
        $this->get('/')->assertRedirect('/pre-login');
    }

    public function test_valid_workspace_code_continues_to_login_case_insensitive(): void
    {
        $this->selectWorkspace(' desarrollo_dev ')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('workspace.code', 'DESARROLLO_DEV'));
    }

    public function test_invalid_inactive_or_disabled_organization_workspaces_share_one_generic_error(): void
    {
        $this->dev->update(['is_active' => false]);
        $this->prd->organization->update(['is_active' => false]);

        foreach (['NOPE', 'DESARROLLO_DEV', 'OPERACION_PRD'] as $code) {
            $response = $this->from('/pre-login')->post('/pre-login', ['workspace_code' => $code]);
            $response->assertRedirect('/pre-login');
            $response->assertSessionHasErrors(['workspace_code' => 'El Workspace no existe o no está disponible.']);
        }
    }

    public function test_login_requires_a_selected_workspace(): void
    {
        $this->get('/login')->assertRedirect('/pre-login');

        $user = $this->member($this->dev);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_member_can_log_in_and_reach_dashboard(): void
    {
        $user = $this->member($this->dev, 'supervisor');

        $this->loginTo($user)->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->assertSame($this->dev->id, session('workspace_id'));
    }

    public function test_wrong_password_does_not_log_in(): void
    {
        $user = $this->member($this->dev);
        $this->selectWorkspace();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guest_cannot_reach_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_without_membership_cannot_log_in_even_knowing_the_code(): void
    {
        $outsider = $this->member($this->prd);

        $this->loginTo($outsider, 'DESARROLLO_DEV')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(
            trans('auth.failed'),
            session('errors')->first('email'),
            'Same message as wrong credentials, no membership leak.'
        );
    }

    public function test_workspace_in_the_request_cannot_override_the_session_workspace(): void
    {
        $user = $this->member($this->dev);
        $this->loginTo($user);

        $this->get('/dashboard?workspace=OPERACION_PRD&workspace_id='.$this->prd->id)->assertOk();
        $this->assertSame($this->dev->id, session('workspace_id'));
    }

    public function test_tampered_session_workspace_is_rejected_and_logs_out(): void
    {
        $user = $this->member($this->dev);

        $this->actingAs($user)
            ->withSession(['workspace_id' => $this->prd->id])
            ->get('/dashboard')
            ->assertRedirect('/pre-login');

        $this->assertGuest();
    }

    public function test_membership_revoked_while_logged_in_is_enforced_on_next_request(): void
    {
        $user = $this->member($this->dev);
        $this->loginTo($user);
        $this->get('/dashboard')->assertOk();

        $this->dev->users()->detach($user->id);

        $this->get('/dashboard')->assertRedirect('/pre-login');
        $this->assertGuest();
    }

    public function test_disabling_the_workspace_blocks_active_sessions(): void
    {
        $user = $this->member($this->dev);
        $this->loginTo($user);

        $this->dev->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/pre-login');
        $this->assertGuest();
    }

    public function test_authenticated_user_without_workspace_session_is_logged_out_not_looped(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/pre-login');
        $this->assertGuest();
    }

    public function test_superuser_can_enter_multiple_workspaces(): void
    {
        $super = User::factory()->create();
        $super->forceFill(['is_superuser' => true])->save();
        $this->dev->users()->attach($super->id, ['role' => 'admin']);

        $this->loginTo($super, 'DESARROLLO_DEV')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->post('/logout');

        // No explicit membership in prd: superuser still enters, acting as admin.
        $this->loginTo($super, 'OPERACION_PRD')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->assertSame($this->prd->id, session('workspace_id'));
    }

    public function test_non_superuser_with_two_memberships_can_enter_both(): void
    {
        $user = $this->member($this->dev);
        $this->prd->users()->attach($user->id, ['role' => 'cliente']);

        $this->loginTo($user, 'DESARROLLO_DEV');
        $this->get('/dashboard')->assertOk();
        $this->post('/logout');

        $this->loginTo($user, 'OPERACION_PRD');
        $this->get('/dashboard')->assertOk();
    }

    public function test_user_management_uses_the_role_of_the_active_workspace_and_is_scoped(): void
    {
        // admin in dev, only cliente in prd
        $user = $this->member($this->dev, 'admin');
        $this->prd->users()->attach($user->id, ['role' => 'cliente']);
        $other = $this->member($this->prd, 'cliente');

        $this->loginTo($user, 'OPERACION_PRD');
        $this->get('/users')->assertForbidden();
        $this->post('/logout');

        $this->loginTo($user, 'DESARROLLO_DEV');
        $this->get('/users')->assertOk();
        // A user from another workspace is not addressable from this one.
        $this->put('/users/'.$other->id, [
            'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin',
        ])->assertNotFound();
        $this->delete('/users/'.$other->id)->assertNotFound();
        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $this->prd->id, 'user_id' => $other->id]);
    }

    public function test_admin_can_edit_identity_of_account_that_exists_only_in_their_workspace(): void
    {
        $admin = $this->member($this->dev, 'admin');
        $solo = $this->member($this->dev, 'cliente');

        $this->loginTo($admin);
        $this->put('/users/'.$solo->id, ['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'role' => 'supervisor'])
            ->assertSessionHasNoErrors();

        $this->assertSame('nuevo@example.com', $solo->fresh()->email);
        $this->assertSame('supervisor', $this->dev->users()->find($solo->id)->pivot->role);
    }

    public function test_admin_cannot_change_name_or_email_of_account_shared_with_another_workspace(): void
    {
        $admin = $this->member($this->dev, 'admin');
        $shared = $this->member($this->dev, 'cliente');
        $this->prd->users()->attach($shared->id, ['role' => 'admin']);
        $original = $shared->only(['name', 'email']);

        $this->loginTo($admin);
        $this->put('/users/'.$shared->id, ['name' => 'Hacked', 'email' => 'hacked@evil.test', 'role' => 'cliente'])
            ->assertSessionHasErrors('email');
        $this->put('/users/'.$shared->id, ['name' => $original['name'], 'email' => 'hacked@evil.test', 'role' => 'cliente'])
            ->assertSessionHasErrors('email');

        $this->assertSame($original, $shared->fresh()->only(['name', 'email']));
        $this->post('/logout');

        // Still the same account for the other workspace: same email, same role there.
        $this->loginTo($shared, 'OPERACION_PRD')->assertRedirect('/dashboard');
        $this->assertSame('admin', $this->prd->users()->find($shared->id)->pivot->role);
    }

    public function test_admin_can_still_manage_the_membership_role_of_a_shared_account(): void
    {
        $admin = $this->member($this->dev, 'admin');
        $shared = $this->member($this->dev, 'cliente');
        $this->prd->users()->attach($shared->id, ['role' => 'admin']);

        $this->loginTo($admin);
        $this->put('/users/'.$shared->id, ['name' => $shared->name, 'email' => $shared->email, 'role' => 'supervisor'])
            ->assertSessionHasNoErrors();

        $this->assertSame('supervisor', $this->dev->users()->find($shared->id)->pivot->role);
        $this->assertSame('admin', $this->prd->users()->find($shared->id)->pivot->role, 'Other workspace untouched.');
    }

    public function test_admin_cannot_edit_the_identity_of_a_superuser_even_with_a_single_membership(): void
    {
        $admin = $this->member($this->dev, 'admin');
        $super = $this->member($this->dev, 'cliente');
        $super->forceFill(['is_superuser' => true])->save();

        $this->loginTo($admin);
        $this->put('/users/'.$super->id, ['name' => 'X', 'email' => 'x@evil.test', 'role' => 'cliente'])
            ->assertSessionHasErrors('email');

        $this->assertNotSame('x@evil.test', $super->fresh()->email);
    }

    public function test_superuser_can_edit_identity_of_a_shared_account(): void
    {
        $super = $this->member($this->dev, 'admin');
        $super->forceFill(['is_superuser' => true])->save();
        $shared = $this->member($this->dev, 'cliente');
        $this->prd->users()->attach($shared->id, ['role' => 'cliente']);

        $this->loginTo($super);
        $this->put('/users/'.$shared->id, ['name' => 'Renombrado', 'email' => 'renombrado@example.com', 'role' => 'cliente'])
            ->assertSessionHasNoErrors();

        $this->assertSame('renombrado@example.com', $shared->fresh()->email);
    }

    public function test_login_throttle_cannot_be_reset_by_changing_workspace(): void
    {
        $user = $this->member($this->dev);
        $this->prd->users()->attach($user->id, ['role' => 'cliente']);

        $this->selectWorkspace('DESARROLLO_DEV');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        // Another workspace, correct credentials: still locked out.
        $this->selectWorkspace('OPERACION_PRD');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString('Too many', session('errors')->first('email'));
    }

    public function test_failed_attempts_count_the_same_with_or_without_membership(): void
    {
        $outsider = $this->member($this->prd);

        $this->selectWorkspace('DESARROLLO_DEV');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $outsider->email, 'password' => 'password'])
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $this->post('/login', ['email' => $outsider->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many', session('errors')->first('email'));
    }

    public function test_logout_clears_the_workspace_context(): void
    {
        $user = $this->member($this->dev);
        $this->loginTo($user);

        $this->post('/logout')->assertRedirect('/pre-login');

        $this->assertGuest();
        $this->assertNull(session('workspace_id'));
    }
}
