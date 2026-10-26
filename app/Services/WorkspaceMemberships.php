<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place that adds an EXISTING account to a Workspace (the add-member form and an invitation to an address that already has an account both end here):
 * no duplicate membership, the account must be active, an internal account of the platform never joins a client Workspace and the plan limit is checked before
 * anything is written. It never touches the account's credentials or its other memberships. Audited in the target Workspace.
 */
class WorkspaceMemberships
{
    public function __construct(private readonly WorkspaceAdministration $administration, private readonly WorkspaceLimits $limits, private readonly AuditLogger $audit) {}

    /**
     * @param  string  $field  the form field an error is attached to
     *
     * @throws ValidationException
     */
    public function add(Workspace $workspace, User $user, string $role, string $field = 'email'): void
    {
        if ($workspace->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages([$field => 'Este usuario ya pertenece al Workspace.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([$field => 'Esta cuenta está desactivada: actívala antes de añadirla a un Workspace.']);
        }

        $this->administration->assertMayReceive($workspace, $user, $field);
        $this->limits->assertCanAddMembers($workspace, 1, $field);

        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->audit->record('created', 'membership', $user->id, $user->name, [
            AuditLogger::change('Workspace', null, $workspace->name),
            AuditLogger::change('Rol', null, $role),
        ], $workspace);
    }
}
