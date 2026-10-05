<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
    }

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        if ($superuser) {
            $user->forceFill(['is_superuser' => true])->save();
        }
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function member(string $role = 'cliente', ?Workspace $workspace = null): User
    {
        $user = User::factory()->create();
        ($workspace ?? Workspace::first())->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function otherWorkspace(): Workspace
    {
        return Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => 'OTHER_WS', 'name' => 'Other']);
    }

    public function test_admin_can_create_and_edit_a_user(): void
    {
        $this->actAs();

        $this->post('/users', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'cliente', 'workspace_id' => Workspace::first()->id,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHasNoErrors();
        $ana = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertTrue($ana->is_active);

        $this->put('/users/'.$ana->id, ['name' => 'Ana María', 'email' => 'ana@example.com', 'role' => 'supervisor'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ana María', $ana->fresh()->name);
        $this->assertSame('supervisor', Workspace::first()->users()->find($ana->id)->pivot->role);
    }

    public function test_deactivating_keeps_the_user_and_memberships_and_flags_it_in_the_list(): void
    {
        $this->actAs();
        $member = $this->member();
        $other = $this->otherWorkspace();
        $other->users()->attach($member->id, ['role' => 'cliente']);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $member->id, 'payload' => 'x', 'last_activity' => time()]);
        $createdAt = $member->created_at;

        // Shared account: a Workspace admin cannot lock it out globally.
        $this->post('/users/'.$member->id.'/deactivate')->assertSessionHasErrors('status');
        $this->assertTrue($member->fresh()->is_active);

        $other->users()->detach($member->id);
        $this->post('/users/'.$member->id.'/deactivate')->assertRedirect('/users')->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame($member->id, $fresh->id);
        $this->assertEquals($createdAt, $fresh->created_at);
        $this->assertSame(1, $fresh->workspaces()->count());
        $this->assertDatabaseHas('sessions', ['id' => 'abc']);

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data', fn ($users) => collect($users)->firstWhere('id', $member->id)['isActive'] === false));
    }

    public function test_reactivating_restores_access_with_the_same_id_and_history(): void
    {
        $this->actAs();
        $member = $this->member('supervisor');
        $this->post('/users/'.$member->id.'/deactivate');

        $this->post('/users/'.$member->id.'/activate')->assertSessionHasNoErrors();

        $fresh = $member->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame($member->id, $fresh->id);
        $this->assertSame('supervisor', $fresh->workspaces()->first()->pivot->role);
    }

    public function test_deactivated_users_cannot_log_in_and_can_after_reactivation(): void
    {
        $workspace = Workspace::create(['organization_id' => Organization::create(['name' => 'Org'])->id, 'code' => 'LOGIN_WS', 'name' => 'Login']);
        $user = $this->member('cliente', $workspace);
        $user->forceFill(['is_active' => false])->save();

        $this->post('/pre-login', ['workspace_code' => 'LOGIN_WS']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $user->forceFill(['is_active' => true])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_deactivated_superusers_cannot_log_in_either(): void
    {
        $workspace = Workspace::create(['organization_id' => Organization::create(['name' => 'Org'])->id, 'code' => 'LOGIN_WS', 'name' => 'Login']);
        $super = User::factory()->create(['is_active' => false]);
        $super->forceFill(['is_superuser' => true])->save();

        $this->post('/pre-login', ['workspace_code' => $workspace->code]);
        $this->post('/login', ['email' => $super->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_open_session_is_closed_when_the_user_is_deactivated(): void
    {
        $user = $this->actAs('cliente');
        $this->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/pre-login');
        $this->assertGuest();
    }

    public function test_status_rules_for_self_superusers_and_other_workspaces(): void
    {
        $admin = $this->actAs();
        $super = $this->member();
        $super->forceFill(['is_superuser' => true])->save();

        $this->post('/users/'.$admin->id.'/deactivate')->assertSessionHasErrors('status');
        $this->post('/users/'.$super->id.'/deactivate')->assertSessionHasErrors('status');
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_superuser_can_deactivate_shared_accounts(): void
    {
        $this->actAs('admin', superuser: true);
        $shared = $this->member();
        $this->otherWorkspace()->users()->attach($shared->id, ['role' => 'cliente']);

        $this->post('/users/'.$shared->id.'/deactivate')->assertSessionHasNoErrors();

        $this->assertFalse($shared->fresh()->is_active);
        $this->assertSame(2, $shared->workspaces()->count());
    }

    public function test_status_changes_require_manage_users(): void
    {
        $this->actAs('cliente');
        $other = $this->member();

        $this->post('/users/'.$other->id.'/deactivate')->assertForbidden();
        $this->post('/users/'.$other->id.'/activate')->assertForbidden();
    }

    public function test_identity_restrictions_still_apply(): void
    {
        $this->actAs();
        $shared = $this->member();
        $this->otherWorkspace()->users()->attach($shared->id, ['role' => 'cliente']);

        $this->put('/users/'.$shared->id, ['name' => 'Hacked', 'email' => $shared->email, 'role' => 'cliente'])
            ->assertSessionHasErrors('email');
        $this->post('/users', [
            'name' => 'Dup', 'email' => $shared->email, 'role' => 'cliente', 'workspace_id' => Workspace::first()->id,
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('email');
    }
}
