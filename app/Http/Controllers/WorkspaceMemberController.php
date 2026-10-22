<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAdministration;
use App\Services\WorkspaceLimits;
use Illuminate\Http\JsonResponse;
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
    /** How many accounts the selector lists at once; more are reached by typing. */
    public const ELIGIBLE_LIMIT = 30;

    public function __construct(
        private readonly WorkspaceAdministration $administration,
        private readonly AuditLogger $audit,
    ) {}

    /** Adds an existing account (superusers only, see the routes). */
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        // The account comes from the selector (`user_id`); a correo is still accepted. Either way the server decides again who may be added.
        $data = $request->validate([
            'user_id' => ['required_without:email', 'nullable', 'integer', Rule::exists('users', 'id')],
            'email' => ['required_without:user_id', 'nullable', 'string', 'email', Rule::exists('users', 'email')],
            'role' => ['required', 'string', Rule::in($this->assignable($request, $workspace))],
        ], ['email.exists' => 'No existe un usuario con ese correo.', 'user_id.exists' => 'Ese usuario no existe.']);

        $user = isset($data['user_id']) ? User::findOrFail($data['user_id']) : User::where('email', $data['email'])->firstOrFail();
        $field = isset($data['user_id']) ? 'user_id' : 'email';

        if ($workspace->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages([$field => 'Este usuario ya pertenece al Workspace.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([$field => 'Esta cuenta está desactivada: actívala antes de añadirla a un Workspace.']);
        }

        $this->administration->assertMayReceive($workspace, $user, $field);
        app(WorkspaceLimits::class)->assertCanAddMembers($workspace, 1, $field);

        $workspace->users()->attach($user->id, ['role' => $data['role']]);
        $this->audit->record('created', 'membership', $user->id, $user->name, [
            AuditLogger::change('Workspace', null, $workspace->name),
            AuditLogger::change('Rol', null, $data['role']),
        ], $workspace);

        return $this->back('Miembro añadido correctamente', $workspace);
    }

    /**
     * Feeds the selector of the add-member form: the accounts that may be added to THIS Workspace (WorkspaceAdministration::eligibleUsers), found by
     * name or email, a few at a time. It exists only for who may add existing accounts (superusers who administer the Workspace), and it never names an
     * account outside the rule: a member, an inactive account or an internal account of the platform are simply not there.
     */
    public function eligible(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) $request->query('q', ''));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        $found = $this->administration->eligibleUsers($workspace)
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw("lower(users.name) like ? escape '!'", [$like])
                ->orWhereRaw("lower(users.email) like ? escape '!'", [$like])))
            ->orderBy('users.name')->orderBy('users.id')
            ->limit(self::ELIGIBLE_LIMIT + 1)->get(['users.id', 'users.name', 'users.email']);

        return response()->json([
            'data' => $found->take(self::ELIGIBLE_LIMIT)->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values(),
            'hasMore' => $found->count() > self::ELIGIBLE_LIMIT,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, Workspace $workspace, User $user): RedirectResponse
    {
        $this->authorizeMember($request, $workspace, $user);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in($this->assignable($request, $workspace))],
        ]);

        if ($request->user()->is($user) && ! $request->user()->isPlatformSuperuser()) {
            throw ValidationException::withMessages(['role' => 'No puedes cambiar tu propio rol.']);
        }

        $this->administration->assertMayReceive($workspace, $user, 'role');

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
