<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Services\LocationScope;
use App\Services\WorkspaceAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which locations (sedes) a member of the CURRENT Workspace works with: all of them, or exactly the ones chosen. Needs `assign-roles` (it decides what a member
 * may reach, like a role does). The Workspace comes from the session, never from the request; the member must belong to it, the locations must be its own, and
 * nobody changes the scope of a member they could not manage by role. A member who is themselves limited to selected locations can not change any scope
 * (they could otherwise widen their own or hand out locations they do not have). An empty selection is valid and means NO location.
 */
class UserLocationScopeController extends Controller
{
    public function __construct(private readonly LocationScope $scope, private readonly WorkspaceAdministration $workspaces, private readonly AuditLogger $audit) {}

    /** The member's scope and the Workspace's locations, for the editor. */
    public function show(Request $request, User $user): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->member($request, $user);

        $ready = $this->scope->ready();
        $allowed = $this->scope->allowedIds($workspace, $user);

        return response()->json([
            'ready' => $ready,
            'scope' => $allowed === null ? LocationScope::ALL : LocationScope::SELECTED,
            'locationIds' => $allowed ?? [],
            'locations' => $workspace->locations()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active'])->map(fn ($location) => ['id' => $location->id, 'name' => $location->name, 'isActive' => $location->is_active])->all(),
            'mayChange' => ! $this->scope->restricted($workspace, $request->user()),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $actor = $request->user();
        $role = $this->member($request, $user);

        abort_unless($this->scope->ready(), 409, 'Falta aplicar la migración del alcance por sede.');
        abort_if($this->scope->restricted($workspace, $actor), 403, 'Quien trabaja con sedes seleccionadas no puede cambiar el alcance de nadie.');

        if (! $this->workspaces->canManageMember($actor, $workspace, $role, $user)) {
            throw ValidationException::withMessages(['scope' => 'Este usuario tiene un rol con más permisos que el tuyo.']);
        }

        $data = $request->validate([
            'scope' => ['required', Rule::in([LocationScope::ALL, LocationScope::SELECTED])],
            'location_ids' => ['nullable', 'array', 'max:500'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)],
        ], ['location_ids.*.exists' => 'Una de las sedes no pertenece a este Workspace.']);

        $before = $this->describe($workspace, $user);
        $this->scope->save($workspace, $user, $data['scope'], $data['scope'] === LocationScope::SELECTED ? array_map('intval', $data['location_ids'] ?? []) : []);
        $after = $this->describe($workspace, $user);

        if ($before !== $after) {
            $this->audit->record('updated', 'membership', $user->id, $user->name, [AuditLogger::change('Alcance de sedes', $before, $after)], $workspace);
        }

        return back()->with('success', 'Alcance de sedes actualizado correctamente');
    }

    /** The member's role in the current Workspace; 404 when they do not belong to it. */
    private function member(Request $request, User $user): string
    {
        $member = $request->attributes->get('workspace')->users()->whereKey($user->id)->first();
        abort_if($member === null, 404);

        return (string) $member->pivot->role;
    }

    private function describe($workspace, User $user): string
    {
        $allowed = $this->scope->allowedIds($workspace, $user);

        if ($allowed === null) {
            return 'Todas las sedes';
        }

        $names = $workspace->locations()->whereIn('id', $allowed)->orderBy('name')->pluck('name')->implode(', ');

        return $names === '' ? 'Ninguna sede' : $names;
    }
}
