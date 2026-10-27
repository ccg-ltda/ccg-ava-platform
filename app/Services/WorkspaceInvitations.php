<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Privacy\Mask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brings a person into a Workspace from the platform administration (a customer contracted outside the public sign-up). The ONLY place that issues, re-sends,
 * revokes and accepts an invitation:
 *  - an address that already has an account is ASSOCIATED to the Workspace (no second account, credentials and other memberships untouched, see WorkspaceMemberships);
 *  - a new address gets an invitation: a 64-character random token that is e-mailed (only its SHA-256 is stored), valid for `workspace.invitations.expire_hours`,
 *    usable once, bound to ONE Workspace and ONE role. Nothing is created for the person until they accept; accepting creates the account with the password THEY
 *    choose (no password is ever generated, shown or sent) and the membership with the invited role, and nothing else (never a global privilege).
 * The plan's user limit counts members plus the invitations still pending, and is checked again on acceptance. Every step is audited in the target Workspace.
 */
class WorkspaceInvitations
{
    public const INVALID = 'Esta invitación no es válida o ya caducó. Pide una nueva a quien te invitó.';

    public function __construct(
        private readonly WorkspaceAdministration $administration,
        private readonly WorkspaceMemberships $memberships,
        private readonly WorkspaceLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    public static function ready(): bool
    {
        return Schema::hasTable('workspace_invitations');
    }

    public static function hours(): int
    {
        return max(1, (int) config('workspace.invitations.expire_hours'));
    }

    /**
     * @return array{outcome: 'invited'|'associated', invitation: ?WorkspaceInvitation}
     *
     * @throws ValidationException
     */
    public function invite(User $actor, Workspace $workspace, string $email, ?string $name, string $role): array
    {
        $this->assertTarget($actor, $workspace, $role);
        $email = mb_strtolower(trim($email));
        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($existing) {
            $this->memberships->add($workspace, $existing, $role, 'email');

            return ['outcome' => 'associated', 'invitation' => null];
        }

        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($actor, $workspace, $email, $name, $role, $token) {
            // Two simultaneous invitations can not both take the last free seat.
            $this->limits->lockWorkspace($workspace);
            $this->revokePending($workspace, $email);
            $this->limits->assertCanAddMembers($workspace, $this->pendingCount($workspace) + 1, 'email');

            $invitation = $workspace->invitations()->create([
                'email' => $email, 'name' => filled($name) ? trim($name) : null, 'role' => $role, 'token_hash' => WorkspaceInvitation::hash($token),
                'invited_by' => $actor->id, 'expires_at' => now()->addHours(self::hours()),
            ]);

            $this->audit->record('created', 'invitation', $invitation->id, Mask::email($email), [
                AuditLogger::change('Workspace', null, $workspace->name), AuditLogger::change('Rol', null, $role), AuditLogger::change('Caduca', null, $invitation->expires_at->toIso8601String()),
            ], $workspace);

            return $invitation;
        });

        $this->send($invitation, $workspace, $token);

        return ['outcome' => 'invited', 'invitation' => $invitation];
    }

    /** Issues a NEW token (the previous link stops working) and sends it again. */
    public function resend(User $actor, Workspace $workspace, WorkspaceInvitation $invitation): void
    {
        abort_unless($invitation->workspace_id === $workspace->id, 404);
        $this->assertTarget($actor, $workspace, $invitation->role);

        if (in_array($invitation->status(), [WorkspaceInvitation::ACCEPTED, WorkspaceInvitation::REVOKED], true)) {
            throw ValidationException::withMessages(['invitation' => 'Esta invitación ya se usó o se canceló: crea una nueva.']);
        }

        $token = Str::random(64);

        DB::transaction(function () use ($workspace, $invitation, $token) {
            $this->limits->lockWorkspace($workspace);
            $invitation->update(['token_hash' => WorkspaceInvitation::hash($token), 'expires_at' => now()->addHours(self::hours())]);
            $this->audit->record('updated', 'invitation', $invitation->id, Mask::email($invitation->email), [AuditLogger::change('Reenviada', null, 'Sí')], $workspace);
        });

        $this->send($invitation, $workspace, $token);
    }

    public function revoke(Workspace $workspace, WorkspaceInvitation $invitation): void
    {
        abort_unless($invitation->workspace_id === $workspace->id, 404);

        if ($invitation->status() === WorkspaceInvitation::ACCEPTED) {
            throw ValidationException::withMessages(['invitation' => 'Esta invitación ya fue aceptada.']);
        }

        if ($invitation->revoked_at === null) {
            $invitation->update(['revoked_at' => now()]);
            $this->audit->record('deleted', 'invitation', $invitation->id, Mask::email($invitation->email), [AuditLogger::change('Estado', 'Pendiente', 'Cancelada')], $workspace);
        }
    }

    /** The invitation behind a token if it can still be accepted, or null. Reads only. */
    public function find(string $token): ?WorkspaceInvitation
    {
        if (! self::ready() || strlen($token) !== 64) {
            return null;
        }

        $invitation = WorkspaceInvitation::with('workspace.organization')->where('token_hash', WorkspaceInvitation::hash($token))->first();

        return $invitation && $invitation->isPending() && $invitation->workspace->is_active && $invitation->workspace->organization->is_active ? $invitation : null;
    }

    /**
     * Creates the account with the password the person chose and joins them to the invited Workspace with the invited role. One transaction, token locked:
     * a second use, an expired or cancelled invitation, a Workspace that was closed meanwhile, an address that got an account in between or a full plan fail
     * with the same neutral message and change nothing.
     *
     * @throws ValidationException
     */
    public function accept(string $token, string $name, string $password): User
    {
        $invalid = fn () => ValidationException::withMessages(['token' => self::INVALID]);

        if (! self::ready() || strlen($token) !== 64) {
            throw $invalid();
        }

        return DB::transaction(function () use ($token, $name, $password, $invalid) {
            $invitation = WorkspaceInvitation::where('token_hash', WorkspaceInvitation::hash($token))->lockForUpdate()->first();
            $workspace = $invitation ? Workspace::with('organization')->find($invitation->workspace_id) : null;

            if (! $invitation || ! $invitation->isPending() || ! $workspace || ! $workspace->is_active || ! $workspace->organization->is_active || $workspace->isAdministrative()) {
                throw $invalid();
            }

            if (User::whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->exists()) {
                throw $invalid(); // an account appeared meanwhile: it is never overwritten; the administration associates it instead
            }

            $this->limits->lockWorkspace($workspace);

            try {
                $this->limits->assertCanAddMembers($workspace, 1, 'token');
            } catch (ValidationException) {
                throw $invalid();
            }

            $user = User::create(['name' => trim($name), 'email' => $invitation->email, 'password' => Hash::make($password), 'email_verified_at' => now()]);
            $workspace->users()->attach($user->id, ['role' => $invitation->role]);
            $invitation->update(['accepted_at' => now(), 'accepted_user_id' => $user->id]);

            $this->audit->system('created', 'membership', $user->id, $user->name, [
                AuditLogger::change('Workspace', null, $workspace->name), AuditLogger::change('Rol', null, $invitation->role),
            ], $workspace, 'Invitación aceptada');

            return $user;
        });
    }

    /** The invitations of a Workspace for the administration, newest first. @return list<array<string, mixed>> */
    public function overview(Workspace $workspace, bool $maskEmails): array
    {
        if (! self::ready()) {
            return [];
        }

        return $workspace->invitations()->orderByDesc('id')->limit(25)->get()->map(fn (WorkspaceInvitation $invitation) => [
            'id' => $invitation->id,
            'email' => $maskEmails ? Mask::email($invitation->email) : $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status(),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'acceptedAt' => $invitation->accepted_at?->toIso8601String(),
        ])->all();
    }

    /** Whether the actor may issue invitations for this Workspace and the role: client Workspace, enabled, administered by the actor, a role they may hand out. */
    private function assertTarget(User $actor, Workspace $workspace, string $role): void
    {
        abort_unless($this->administration->canAdminister($actor, $workspace), 404);

        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['email' => 'Las cuentas del Workspace administrativo se crean desde Usuarios y roles.']);
        }

        if (! $workspace->is_active || ! $workspace->organization->is_active) {
            throw ValidationException::withMessages(['email' => 'El Workspace está desactivado: actívalo antes de invitar a alguien.']);
        }

        if (! $this->administration->assignableRoles($actor, $workspace)->contains('name', $role)) {
            throw ValidationException::withMessages(['role' => 'No puedes asignar ese rol en este Workspace.']);
        }
    }

    private function pendingCount(Workspace $workspace): int
    {
        return $workspace->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
    }

    private function revokePending(Workspace $workspace, string $email): void
    {
        $workspace->invitations()->whereRaw('lower(email) = ?', [$email])->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function send(WorkspaceInvitation $invitation, Workspace $workspace, string $token): void
    {
        Notification::route('mail', $invitation->email)->notify(new WorkspaceInvitationNotification(route('invitations.show', ['token' => $token]), $workspace->name, self::hours(), $invitation->id));
    }
}
