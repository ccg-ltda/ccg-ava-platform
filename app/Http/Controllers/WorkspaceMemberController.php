<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Memberships (`workspace_user`) of a Workspace. Removing a member deletes only the membership,
 * never the user account. Every action requires administering that specific Workspace.
 */
class WorkspaceMemberController extends Controller
{
    public function __construct(
        private readonly WorkspaceAdministration $administration,
        private readonly AuditLogger $audit,
    ) {}

    /** Adds an existing account (superusers only, see the routes). */
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', Rule::exists('users', 'email')],
            'role' => ['required', 'string', Rule::in($this->assignable($request, $workspace))],
        ], ['email.exists' => 'No existe un usuario con ese correo.']);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($workspace->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Este usuario ya pertenece al Workspace.']);
        }

        $workspace->users()->attach($user->id, ['role' => $data['role']]);
        $this->audit->record('created', 'membership', $user->id, $user->name, [
            AuditLogger::change('Workspace', null, $workspace->name),
            AuditLogger::change('Rol', null, $data['role']),
        ], $workspace);

        return $this->back('Miembro añadido correctamente', $workspace);
    }

    public function update(Request $request, Workspace $workspace, User $user): RedirectResponse
    {
        $this->authorizeMember($request, $workspace, $user);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in($this->assignable($request, $workspace))],
        ]);

        if ($request->user()->is($user) && ! $user->is_superuser) {
            throw ValidationException::withMessages(['role' => 'No puedes cambiar tu propio rol.']);
        }

        $previous = $workspace->users()->whereKey($user->id)->first()->pivot->role;
        $workspace->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $this->audit->record('updated', 'membership', $user->id, $user->name, $previous === $data['role'] ? [] : [
            AuditLogger::change('Rol', $previous, $data['role']),
        ], $workspace);

        return $this->back('Rol del miembro actualizado correctamente', $workspace);
    }

    public function destroy(Request $request, Workspace $workspace, User $user): RedirectResponse
    {
        $this->authorizeMember($request, $workspace, $user);

        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['member' => 'No puedes quitarte a ti mismo del Workspace.']);
        }

        $role = $workspace->users()->whereKey($user->id)->first()->pivot->role;
        $workspace->users()->detach($user->id);
        $this->audit->record('deleted', 'membership', $user->id, $user->name, [
            AuditLogger::change('Workspace', $workspace->name, null),
            AuditLogger::change('Rol', $role, null),
        ], $workspace);

        return $this->back('Miembro quitado correctamente', $workspace);
    }

    private function authorizeWorkspace(Request $request, Workspace $workspace): void
    {
        abort_unless($this->administration->canAdminister($request->user(), $workspace), 404);
    }

    private function authorizeMember(Request $request, Workspace $workspace, User $user): void
    {
        $this->authorizeWorkspace($request, $workspace);

        $member = $workspace->users()->whereKey($user->id)->first();
        abort_unless($member, 404);

        if (! $this->administration->canManageMember($request->user(), $workspace, $member->pivot->role, $member)) {
            throw ValidationException::withMessages(['member' => 'Este usuario tiene un rol con más permisos que el tuyo.']);
        }
    }

    /** @return list<string> */
    private function assignable(Request $request, Workspace $workspace): array
    {
        return $this->administration->assignableRoles($request->user(), $workspace)->pluck('name')->all();
    }

    private function back(string $message, Workspace $workspace): RedirectResponse
    {
        // Back to the same page (modal open, list page and size kept); the fallback is only for a missing referer.
        return redirect()->back(fallback: route('users.index', ['tab' => 'workspaces', 'workspace' => $workspace->id]))->with('success', $message);
    }
}
