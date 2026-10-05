<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\WorkspaceAdministration;
use App\Services\WorkspaceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Feeds the Workspace selector (Components/WorkspaceCombobox): a text search over Workspaces, a few results at a
 * time, so the list can grow without ever being loaded whole. What may be found depends on the purpose:
 *
 * - `view`: any Workspace, for choosing what Reportes or Auditoría look at. Only a superuser working inside the
 *   administrative Workspace (WorkspaceScope::canChoose).
 * - `assign`: the enabled Workspaces the user may place people in (WorkspaceAdministration::assignable), with the
 *   roles they may hand out in each. Requires `manage-users`.
 */
class WorkspaceSearchController extends Controller
{
    public const LIMIT = 10;

    public function __construct(
        private readonly WorkspaceScope $scope,
        private readonly WorkspaceAdministration $administration,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'purpose' => ['required', Rule::in(['view', 'assign'])],
        ]);

        $actor = $request->user();
        $active = $request->attributes->get('workspace');
        $term = trim($data['q'] ?? '');
        $assign = $data['purpose'] === 'assign';

        abort_unless(
            $assign
                ? in_array(WorkspaceAdministration::MANAGE_PERMISSION, $request->attributes->get('workspace_permissions', []), true)
                : $this->scope->canChoose($actor, $active),
            403,
        );

        $query = $assign
            ? $this->administration->assignable($actor)->with('organization:id,name')->search($term)->orderBy('name')->orderBy('code')
            : $this->scope->searchable($term);

        // One more than the limit tells the selector that there are more results to narrow down.
        $found = $query->limit(self::LIMIT + 1)->get();

        return response()->json([
            'data' => $found->take(self::LIMIT)->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'code' => $workspace->code,
                'name' => $workspace->name,
                'organization' => $workspace->organization->name,
                'isActive' => $workspace->is_active,
                'isAdministrative' => $workspace->isAdministrative(),
                'roles' => $assign ? $this->administration->assignableRoles($actor, $workspace)->pluck('name')->values() : null,
            ])->values(),
            'hasMore' => $found->count() > self::LIMIT,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
