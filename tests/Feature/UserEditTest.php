<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Editing a user: identity, optional password, and moving the membership to another Workspace. */
class UserEditTest extends TestCase
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

    private function member(string $role = 'cliente'): User
    {
        $user = User::factory()->create();
        $this->a->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'role' => 'cliente'], $overrides);
    }

    // --- name, email, password -----------------------------------------------------------------------------

    public function test_name_and_email_are_edited_and_the_password_is_kept_when_left_empty(): void
    {
        $this->actAs('admin');
        $user = $this->member();
        $hash = $user->password;

        $this->put('/users/'.$user->id, $this->payload($user, ['name' => 'Nuevo Nombre', 'email' => 'nuevo@example.com', 'password' => '', 'password_confirmation' => '']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Usuario actualizado correctamente');

        $fresh = $user->fresh();
        $this->assertSame('Nuevo Nombre', $fresh->name);
        $this->assertSame('nuevo@example.com', $fresh->email);
        $this->assertSame($hash, $fresh->password);
    }

    public function test_a_new_password_is_hashed_and_works_for_login(): void
    {
        $this->actAs('admin');
        $user = $this->member();

        $this->put('/users/'.$user->id, $this->payload($user, ['password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123']))
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertNotSame('Nueva-clave-123', $fresh->password);
        $this->assertTrue(Hash::check('Nueva-clave-123', $fresh->password));

        $this->post('/logout');
        $this->post('/pre-login', ['workspace_code' => 'WS_A']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Nueva-clave-123'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_follows_the_creation_rules(): void
    {
        $this->actAs('admin');
        $user = $this->member();
        $hash = $user->password;

        $this->put('/users/'.$user->id, $this->payload($user, ['password' => 'corta', 'password_confirmation' => 'corta']))->assertSessionHasErrors('password');
        $this->put('/users/'.$user->id, $this->payload($user, ['password' => 'Nueva-clave-123', 'password_confirmation' => 'otra']))->assertSessionHasErrors('password');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_identity_and_password_of_shared_accounts_and_superusers_are_protected(): void
    {
        $this->actAs('admin');
        $shared = $this->member();
        $this->b->users()->attach($shared->id, ['role' => 'cliente']);
        $super = $this->member();
        $super->forceFill(['is_superuser' => true])->save();

        foreach ([$shared, $super] as $target) {
            $hash = $target->password;
            $this->put('/users/'.$target->id, $this->payload($target, ['password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123']))
                ->assertSessionHasErrors($target->is($super) ? 'role' : 'email'); // a superuser's membership is refused before the identity check
            $this->assertSame($hash, $target->fresh()->password);
        }

        // Only the role may change for such accounts.
        $this->put('/users/'.$shared->id, $this->payload($shared, ['role' => 'supervisor']))->assertSessionHasNoErrors();
    }

    public function test_a_superuser_can_set_the_password_of_a_shared_account(): void
    {
        $this->actAs('admin', superuser: true);
        $shared = $this->member();
        $this->b->users()->attach($shared->id, ['role' => 'cliente']);

        $this->put('/users/'.$shared->id, $this->payload($shared, ['password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Nueva-clave-123', $shared->fresh()->password));
    }

    public function test_the_password_of_a_user_with_a_higher_role_cannot_be_set(): void
    {
        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'view-dashboard']);
        $this->actAs('manager');
        $boss = $this->member('admin');
        $hash = $boss->password;

        $this->put('/users/'.$boss->id, $this->payload($boss, ['role' => 'admin', 'password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123']))
            ->assertSessionHasErrors('role');

        $this->assertSame($hash, $boss->fresh()->password);
    }

    // --- Workspace -----------------------------------------------------------------------------------------

    public function test_moving_a_user_replaces_the_membership_and_keeps_the_account(): void
    {
        $this->actAs('admin', superuser: true);
        $user = $this->member();
        $other = Workspace::create(['organization_id' => Organization::first()->id, 'code' => 'WS_C', 'name' => 'Gamma']);
        $other->users()->attach($user->id, ['role' => 'supervisor']);

        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => $this->b->id, 'role' => 'supervisor']))
            ->assertSessionHasNoErrors();

        $this->assertSame($user->id, $user->fresh()->id);
        $this->assertEqualsCanonicalizing(['WS_B', 'WS_C'], $user->workspaces()->pluck('code')->all());
        $this->assertSame('supervisor', $this->b->users()->find($user->id)->pivot->role);
        $this->assertSame(1, $this->b->users()->whereKey($user->id)->count(), 'No duplicate membership.');
        $this->assertSame(1, User::where('email', $user->email)->count());
    }

    public function test_the_role_must_be_valid_for_the_chosen_workspace(): void
    {
        $manager = $this->actAs('admin');
        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'view-dashboard']);
        // The actor is admin in A but only a manager in B: in B they cannot hand out "admin".
        $this->b->users()->attach($manager->id, ['role' => 'manager']);
        $user = $this->member();

        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => $this->b->id, 'role' => 'admin']))->assertSessionHasErrors('role');
        $this->assertTrue($this->a->users()->whereKey($user->id)->exists());

        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => $this->b->id, 'role' => 'cliente']))->assertSessionHasNoErrors();
        $this->assertFalse($this->a->users()->whereKey($user->id)->exists());
        $this->assertTrue($this->b->users()->whereKey($user->id)->exists());
    }

    public function test_admins_cannot_move_users_to_workspaces_they_do_not_administer_or_that_are_disabled(): void
    {
        $this->actAs('admin');
        $user = $this->member();

        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => $this->b->id]))->assertSessionHasErrors('workspace_id');
        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => 9999]))->assertSessionHasErrors('workspace_id');

        $this->actAs('admin', superuser: true);
        $this->b->update(['is_active' => false]);
        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => $this->b->id]))->assertSessionHasErrors('workspace_id');

        $this->assertSame(['WS_A'], $user->workspaces()->pluck('code')->all());
    }

    public function test_a_user_who_already_belongs_to_the_target_cannot_be_moved_there(): void
    {
        $this->actAs('admin', superuser: true);
        $user = $this->member();
        $this->b->users()->attach($user->id, ['role' => 'cliente']);

        $this->put('/users/'.$user->id, $this->payload($user, ['workspace_id' => $this->b->id]))->assertSessionHasErrors('workspace_id');

        $this->assertSame(2, $user->workspaces()->count());
    }

    public function test_actors_cannot_move_themselves_or_users_with_a_higher_role(): void
    {
        $admin = $this->actAs('admin');
        $this->b->users()->attach($admin->id, ['role' => 'admin']);

        $this->put('/users/'.$admin->id, $this->payload($admin, ['workspace_id' => $this->b->id, 'role' => 'admin']))->assertSessionHasErrors('workspace_id');

        Role::findOrCreate('manager', 'web')->syncPermissions(['manage-users', 'view-dashboard']);
        $manager = $this->actAs('manager');
        $this->b->users()->attach($manager->id, ['role' => 'manager']);
        $boss = $this->member('admin');
        $this->put('/users/'.$boss->id, $this->payload($boss, ['workspace_id' => $this->b->id, 'role' => 'cliente']))->assertSessionHasErrors('role');

        $this->assertTrue($this->a->users()->whereKey($admin->id)->exists());
        $this->assertTrue($this->a->users()->whereKey($boss->id)->exists());
    }

    public function test_users_outside_the_active_workspace_cannot_be_edited(): void
    {
        $this->actAs('admin');
        $outsider = User::factory()->create();
        $this->b->users()->attach($outsider->id, ['role' => 'cliente']);

        $this->put('/users/'.$outsider->id, $this->payload($outsider, ['password' => 'Nueva-clave-123', 'password_confirmation' => 'Nueva-clave-123']))->assertNotFound();
    }
}
