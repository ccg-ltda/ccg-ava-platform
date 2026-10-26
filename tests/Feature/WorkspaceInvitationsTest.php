<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\WorkspaceInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** Invitations from Desarrollo_DEV: first user of a customer Workspace, new address, existing account, limits, roles, expiry, one-time token, audit, isolation. */
class WorkspaceInvitationsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seedRoleCatalog();
        $this->dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Desarrollo']);
        $this->platform = User::factory()->create(['is_superuser' => true]);
        $this->client = $this->bookingWorkspace('CLIENTE', ['dashboard', 'customers']);
        $this->actingAs($this->platform)->withSession(['workspace_id' => $this->dev->id]);
    }

    /** Runs the invitation and returns the token that was e-mailed (read from the notification, the only place it exists). */
    private function invite(string $email = 'nuevo@cliente.com', string $role = 'admin', ?Workspace $workspace = null): ?string
    {
        $workspace ??= $this->client;
        $this->post("/workspaces/{$workspace->id}/invitations", ['email' => $email, 'role' => $role, 'name' => 'Nuevo Cliente']);
        $token = null;

        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($notification, $channels, $notifiable) use ($email, &$token) {
            if (($notifiable->routes['mail'] ?? null) !== mb_strtolower($email)) {
                return false;
            }
            $url = (fn () => $this->url)->call($notification);
            $token = basename(parse_url($url, PHP_URL_PATH));

            return true;
        });

        return $token;
    }

    public function test_the_first_user_of_an_administratively_contracted_workspace_is_invited_and_accepts_with_their_own_password(): void
    {
        $this->assertSame(0, $this->client->users()->count());
        $token = $this->invite();

        $this->assertSame(64, strlen($token));
        $invitation = WorkspaceInvitation::firstOrFail();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash, 'only the hash is stored');
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(0, User::where('email', 'nuevo@cliente.com')->count(), 'no account exists before the invitation is accepted');
        $this->assertSame(0, $this->client->users()->count());

        auth()->logout();
        $this->flushSession();
        $this->get("/invitacion/{$token}")->assertOk()->assertInertia(fn ($page) => $page->where('valid', true)->where('email', 'nuevo@cliente.com')->where('workspace', $this->client->name));
        $this->post("/invitacion/{$token}", ['name' => 'Ana Cliente', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'])->assertSessionHasNoErrors();

        $user = User::where('email', 'nuevo@cliente.com')->firstOrFail();
        $this->assertTrue(Hash::check('Una-clave-larga-9', $user->password));
        $this->assertFalse($user->is_superuser);
        $this->assertSame([$this->client->id => 'admin'], $user->workspaces()->get()->mapWithKeys(fn ($w) => [$w->id => $w->pivot->role])->all(), 'only the invited Workspace and role');
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($user->id, $invitation->fresh()->accepted_user_id);
        $this->assertSame(1, AuditLog::where('workspace_id', $this->client->id)->where('resource_type', 'membership')->where('action', 'created')->count());
    }

    public function test_the_token_works_once(): void
    {
        $token = $this->invite();
        auth()->logout();
        $this->flushSession();
        $body = ['name' => 'Ana', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'];

        $this->post("/invitacion/{$token}", $body)->assertSessionHasNoErrors();
        $this->post("/invitacion/{$token}", $body)->assertSessionHasErrors('token');
        $this->get("/invitacion/{$token}")->assertInertia(fn ($page) => $page->where('valid', false));
        $this->assertSame(1, User::where('email', 'nuevo@cliente.com')->count());
    }

    public function test_an_expired_cancelled_or_unknown_token_gets_the_same_neutral_answer_and_creates_nothing(): void
    {
        $expired = $this->invite('vencida@cliente.com');
        WorkspaceInvitation::where('email', 'vencida@cliente.com')->update(['expires_at' => now()->subMinute()]);
        $cancelled = $this->invite('cancelada@cliente.com');
        $this->delete("/workspaces/{$this->client->id}/invitations/".WorkspaceInvitation::where('email', 'cancelada@cliente.com')->value('id'))->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();
        $body = ['name' => 'X', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'];

        foreach ([$expired, $cancelled, str_repeat('a', 64)] as $token) {
            $this->get("/invitacion/{$token}")->assertInertia(fn ($page) => $page->where('valid', false)->where('message', 'Esta invitación no es válida o ya caducó. Pide una nueva a quien te invitó.'));
            $this->post("/invitacion/{$token}", $body)->assertSessionHasErrors('token');
        }

        $this->assertSame(1, User::count(), 'only the platform account exists');
    }

    public function test_the_invitation_is_bound_to_its_workspace_and_role_and_a_closed_workspace_voids_it(): void
    {
        $token = $this->invite();
        $this->client->update(['is_active' => false]);
        auth()->logout();
        $this->flushSession();

        $this->post("/invitacion/{$token}", ['name' => 'Ana', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'])->assertSessionHasErrors('token');
        $this->assertSame(0, User::where('email', 'nuevo@cliente.com')->count());
    }

    public function test_an_existing_account_is_associated_without_touching_its_password_or_other_memberships(): void
    {
        $other = $this->bookingWorkspace('OTRO', ['dashboard']);
        $existing = User::factory()->create(['email' => 'ya@cliente.com', 'password' => 'Clave-original-1']);
        $other->users()->attach($existing->id, ['role' => 'cliente']);
        $hash = $existing->fresh()->password;

        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'YA@cliente.com', 'role' => 'agente'])->assertSessionHasNoErrors();

        Notification::assertNothingSentTo($existing);
        $this->assertSame(0, WorkspaceInvitation::count(), 'no invitation and no second account');
        $this->assertSame(1, User::where('email', 'ya@cliente.com')->count());
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(['agente'], $this->client->users()->get()->pluck('pivot.role')->all());
        $this->assertSame(['cliente'], $other->users()->get()->pluck('pivot.role')->all());

        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'ya@cliente.com', 'role' => 'agente'])->assertSessionHasErrors('email');
        $this->assertSame(1, DB::table('workspace_user')->where('workspace_id', $this->client->id)->where('user_id', $existing->id)->count(), 'no duplicate membership');
    }

    public function test_an_internal_account_is_never_associated_to_a_customer_workspace(): void
    {
        $colleague = User::factory()->create(['email' => 'colega@ccg.com', 'is_superuser' => true]);

        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => $colleague->email, 'role' => 'admin'])->assertSessionHasErrors('email');
        $this->assertSame(0, $this->client->users()->count());
    }

    public function test_the_role_must_be_one_the_actor_may_hand_out(): void
    {
        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'a@cliente.com', 'role' => 'no-existe'])->assertSessionHasErrors('role');
        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'b@cliente.com'])->assertSessionHasErrors('role');
        $this->assertSame(0, WorkspaceInvitation::count());
    }

    public function test_the_user_limit_counts_members_and_pending_invitations_and_is_checked_again_on_acceptance(): void
    {
        $plan = Plan::create(['name' => 'Chico']);
        $plan->modules()->sync($this->client->plan->modules()->pluck('modules.id'));
        $this->client->update(['plan_id' => $plan->id]);
        DB::table('plan_limits')->insert(['plan_id' => $plan->id, 'metric' => 'users', 'value' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->client->users()->attach(User::factory()->create()->id, ['role' => 'admin']);

        $first = $this->invite('uno@cliente.com');
        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'dos@cliente.com', 'role' => 'admin'])->assertSessionHasErrors('email');
        $this->assertSame(1, WorkspaceInvitation::count(), 'the second one does not take a seat that is already reserved');

        // A seat gets taken by another path before the invitation is accepted: the acceptance is refused and nothing is created.
        $this->client->users()->attach(User::factory()->create()->id, ['role' => 'agente']);
        auth()->logout();
        $this->flushSession();
        $this->post("/invitacion/{$first}", ['name' => 'Uno', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9'])->assertSessionHasErrors('token');
        $this->assertSame(0, User::where('email', 'uno@cliente.com')->count());
    }

    public function test_resending_kills_the_old_link_and_a_new_invitation_to_the_same_address_replaces_the_pending_one(): void
    {
        $old = $this->invite();
        $id = WorkspaceInvitation::firstOrFail()->id;

        $this->post("/workspaces/{$this->client->id}/invitations/{$id}/resend")->assertSessionHasNoErrors();
        Notification::assertSentOnDemandTimes(WorkspaceInvitationNotification::class, 2);
        $newest = null;
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($notification) use (&$newest) {
            $newest = basename(parse_url((fn () => $this->url)->call($notification), PHP_URL_PATH));

            return true;
        });
        $this->assertNotSame($old, $newest);
        $this->assertNull(app(WorkspaceInvitations::class)->find($old));
        $this->assertNotNull(app(WorkspaceInvitations::class)->find($newest));

        $this->invite();
        $this->assertSame(2, WorkspaceInvitation::count());
        $this->assertSame(1, WorkspaceInvitation::whereNull('revoked_at')->whereNull('accepted_at')->count(), 'only one pending invitation per address');
    }

    public function test_only_the_platform_administration_can_invite_and_a_client_admin_can_not_act_on_another_workspace(): void
    {
        $mine = $this->bookingWorkspace('MIO', ['dashboard', 'users_roles']);
        $this->actAsMember($mine, 'admin');

        // A customer administrator never reaches the invitation routes, neither for their own Workspace nor for another one.
        $this->post("/workspaces/{$mine->id}/invitations", ['email' => 'x@cliente.com', 'role' => 'agente'])->assertForbidden();
        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'x@cliente.com', 'role' => 'agente'])->assertForbidden();
        $this->assertSame(0, WorkspaceInvitation::count());

        // A platform user without create-users is refused too.
        Role::findOrCreate('solo_miembros', 'web')->syncPermissions(['view-dashboard', 'manage-users', 'assign-roles']);
        $limited = User::factory()->create(['is_superuser' => true]);
        $this->dev->users()->attach($limited->id, ['role' => 'solo_miembros']);
        $this->actingAs($limited)->withSession(['workspace_id' => $this->dev->id]);
        $this->post("/workspaces/{$this->client->id}/invitations", ['email' => 'x@cliente.com', 'role' => 'agente'])->assertForbidden();
    }

    public function test_the_administrative_workspace_takes_no_invitations(): void
    {
        $this->post("/workspaces/{$this->dev->id}/invitations", ['email' => 'x@ccg.com', 'role' => 'admin'])->assertSessionHasErrors('email');
        $this->assertSame(0, WorkspaceInvitation::count());
    }

    public function test_the_invited_user_reaches_only_the_invited_workspace(): void
    {
        $secret = $this->bookingWorkspace('SECRETO', ['dashboard', 'customers']);
        $secret->customers()->create(['first_name' => 'Ajeno', 'phone' => '+573001110000', 'source' => 'manual', 'marketing_consent' => 'unknown']);
        $token = $this->invite();
        auth()->logout();
        $this->flushSession();
        $this->post("/invitacion/{$token}", ['name' => 'Ana', 'password' => 'Una-clave-larga-9', 'password_confirmation' => 'Una-clave-larga-9']);
        $user = User::where('email', 'nuevo@cliente.com')->firstOrFail();

        $this->assertFalse($user->canAccessWorkspace($secret));
        $this->assertTrue($user->canAccessWorkspace($this->client));
        $this->actingAs($user)->withSession(['workspace_id' => $secret->id])->get('/customers')->assertStatus(302);
    }

    public function test_invitations_and_their_audit_never_show_the_full_address_of_another_workspace_member(): void
    {
        $this->invite('persona@cliente.com');
        $audit = AuditLog::where('resource_type', 'invitation')->get();

        $this->assertNotEmpty($audit);
        $this->assertStringNotContainsString('persona@cliente.com', json_encode($audit->map->only(['resource_label', 'changes', 'description'])));

        $this->get('/users?tab=workspaces&workspace='.$this->client->id)->assertInertia(fn ($page) => $page
            ->where('workspaces.selected.canInvite', true)
            ->where('workspaces.selected.invitations.0.status', 'pending')
            ->where('workspaces.selected.invitations.0.email', fn ($email) => $email !== 'persona@cliente.com' && str_contains($email, '@')));
    }
}
