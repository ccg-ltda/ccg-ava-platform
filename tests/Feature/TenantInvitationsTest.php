<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** A Workspace administrator invites into THEIR Workspace only: the session decides the target, an existing account accepts with its own password. */
class TenantInvitationsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seedRoleCatalog();
        Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTEA', ['dashboard', 'users_roles']);
        $this->b = $this->bookingWorkspace('CLIENTEB', ['dashboard', 'users_roles']);
    }

    private function tokenSentTo(string $email): ?string
    {
        $token = null;

        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($notification, $channels, $notifiable) use ($email, &$token) {
            if (($notifiable->routes['mail'] ?? null) !== mb_strtolower($email)) {
                return false;
            }
            $token = basename(parse_url((fn () => $this->url)->call($notification), PHP_URL_PATH));

            return true;
        });

        return $token;
    }

    private function logoutEverywhere(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    public function test_a_client_admin_invites_into_their_own_workspace_and_the_person_accepts(): void
    {
        $this->actAsMember($this->a, 'admin');

        $this->post('/users/invitations', ['email' => 'nuevo@a.com', 'role' => 'agente', 'name' => 'Nuevo'])->assertSessionHasNoErrors();

        $invitation = WorkspaceInvitation::firstOrFail();
        $this->assertSame($this->a->id, $invitation->workspace_id);
        $token = $this->tokenSentTo('nuevo@a.com');

        $this->logoutEverywhere();
        $this->post("/invitacion/{$token}", ['name' => 'Ana', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'])->assertSessionHasNoErrors();

        $user = User::where('email', 'nuevo@a.com')->firstOrFail();
        $this->assertTrue($user->canAccessWorkspace($this->a));
        $this->assertFalse($user->canAccessWorkspace($this->b));
        $this->assertSame('agente', $this->a->users()->whereKey($user->id)->first()->pivot->role);
    }

    public function test_a_workspace_id_in_the_request_can_not_redirect_the_invitation(): void
    {
        $this->actAsMember($this->a, 'admin');

        $this->post('/users/invitations', ['email' => 'x@a.com', 'role' => 'agente', 'workspace_id' => $this->b->id, 'workspace' => $this->b->id])->assertSessionHasNoErrors();

        $this->assertSame([$this->a->id], WorkspaceInvitation::pluck('workspace_id')->all());
    }

    public function test_the_admin_of_a_can_not_touch_the_invitations_of_b(): void
    {
        $this->actAsMember($this->b, 'admin');
        $this->post('/users/invitations', ['email' => 'y@b.com', 'role' => 'agente']);
        $foreign = WorkspaceInvitation::firstOrFail();
        $this->logoutEverywhere();

        $this->actAsMember($this->a, 'admin');
        $this->post("/users/invitations/{$foreign->id}/resend")->assertNotFound();
        $this->delete("/users/invitations/{$foreign->id}")->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_a_user_without_the_invitation_permissions_is_refused(): void
    {
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-dashboard', 'manage-users']);
        $this->actAsMember($this->a, 'lector');

        $this->post('/users/invitations', ['email' => 'x@a.com', 'role' => 'agente'])->assertForbidden();
        $this->assertSame(0, WorkspaceInvitation::count());
    }

    public function test_the_role_must_be_assignable_by_the_actor(): void
    {
        $this->actAsMember($this->a, 'admin');

        $this->post('/users/invitations', ['email' => 'x@a.com', 'role' => 'plataforma'])->assertSessionHasErrors('role');
        $this->assertSame(0, WorkspaceInvitation::count());
    }

    public function test_an_account_that_belongs_to_another_workspace_accepts_with_its_password_without_a_second_account(): void
    {
        $existing = User::factory()->create(['email' => 'ya@b.com', 'password' => 'Clave-original-1']);
        $this->b->users()->attach($existing->id, ['role' => 'agente']);
        $hash = $existing->fresh()->password;
        $this->actAsMember($this->a, 'admin');

        $this->post('/users/invitations', ['email' => 'YA@b.com', 'role' => 'agente'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->a->users()->whereKey($existing->id)->count(), 'nothing is attached until the holder accepts');
        $token = $this->tokenSentTo('ya@b.com');

        $this->logoutEverywhere();
        $this->post("/invitacion/{$token}", ['password' => 'incorrecta'])->assertSessionHasErrors('password');
        $this->assertSame(0, $this->a->users()->whereKey($existing->id)->count());

        $this->post("/invitacion/{$token}", ['password' => 'Clave-original-1'])->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('email', 'ya@b.com')->count());
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertTrue($existing->fresh()->canAccessWorkspace($this->a));
        $this->assertTrue($existing->fresh()->canAccessWorkspace($this->b));
        $this->post("/invitacion/{$token}", ['password' => 'Clave-original-1'])->assertSessionHasErrors();
        $this->assertSame(1, $this->a->users()->whereKey($existing->id)->count(), 'a second use changes nothing');
    }

    public function test_an_internal_account_can_not_be_invited_into_a_customer_workspace(): void
    {
        User::factory()->create(['email' => 'colega@ccg.com', 'is_superuser' => true]);
        $this->actAsMember($this->a, 'admin');

        $this->post('/users/invitations', ['email' => 'colega@ccg.com', 'role' => 'agente'])->assertSessionHasErrors('email');
        $this->assertSame(0, WorkspaceInvitation::count());
    }

    public function test_resent_revoked_and_expired_invitations(): void
    {
        $this->actAsMember($this->a, 'admin');
        $this->post('/users/invitations', ['email' => 'z@a.com', 'role' => 'agente']);
        $old = $this->tokenSentTo('z@a.com');
        $invitation = WorkspaceInvitation::firstOrFail();

        $this->post("/users/invitations/{$invitation->id}/resend")->assertSessionHasNoErrors();
        $this->logoutEverywhere();
        $this->post("/invitacion/{$old}", ['name' => 'Z', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'])->assertSessionHasErrors('token');

        $this->actAsMember($this->a, 'admin');
        $this->delete("/users/invitations/{$invitation->id}")->assertSessionHasNoErrors();
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->post("/users/invitations/{$invitation->id}/resend")->assertSessionHasErrors('invitation');

        $invitation->fresh()->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->assertSame('expired', $invitation->fresh()->status());
    }
}
