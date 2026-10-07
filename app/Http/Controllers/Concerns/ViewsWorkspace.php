<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Workspace;
use App\Services\WorkspaceScope;
use Illuminate\Http\Request;

/**
 * "Which Workspace does this page show?" for pages that belong to the active Workspace but that an administrator of
 * the platform may LOOK at (read only) for exactly one other Workspace with `?workspace=`. The controller must have a
 * `$scope` (WorkspaceScope); the server decides, the parameter is only a request.
 */
trait ViewsWorkspace
{
    protected WorkspaceScope $scope;

    /** @return array{0: Workspace, 1: bool} the Workspace being looked at and whether it is not the active one */
    protected function viewing(Request $request): array
    {
        $active = $request->attributes->get('workspace');
        $viewing = $this->scope->resolveOne($request->user(), $active, $request->query('workspace'));

        return [$viewing, ! $viewing->is($active)];
    }

    /**
     * The `scope` prop every such page sends. `canManage` is the permission to change the thing the page is about
     * (never true while looking at another Workspace).
     *
     * @return array<string, mixed>
     */
    protected function scopeProps(Request $request, Workspace $viewing, bool $foreign, string $managePermission): array
    {
        return [
            'workspace' => ['id' => $viewing->id, 'name' => $viewing->name, 'code' => $viewing->code],
            'readOnly' => $foreign,
            'canChoose' => $this->scope->canChoose($request->user(), $request->attributes->get('workspace')),
            'canManage' => ! $foreign && in_array($managePermission, $request->attributes->get('workspace_permissions', []), true),
        ];
    }
}
