<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Workspace(s) a request may look at. Everyone works inside the Workspace of their session. The one exception
 * is a superuser working inside the administrative Workspace of Ava Platform (config `workspace.admin_code`): they may
 * look at ALL Workspaces or pick ONE, to administer the platform. The rule lives here and nowhere else, and the
 * server is the authority: a `workspace` parameter from the client is checked, never trusted.
 */
class WorkspaceScope
{
    public const ALL = 'all';

    public function canChoose(User $user, Workspace $active): bool
    {
        return $user->is_superuser && $active->isAdministrative();
    }

    /**
     * The Workspace(s) the request asks for, or null when it asks for none (the active Workspace applies).
     * A request that asks for a scope its user may not choose is refused with 403; an unknown Workspace is a 404.
     *
     * @return array{mode: 'active'|'all'|'workspace', workspace: ?Workspace}
     */
    public function resolve(User $user, Workspace $active, ?string $requested): array
    {
        if ($requested === null || $requested === '') {
            return ['mode' => 'active', 'workspace' => $active];
        }

        abort_unless($this->canChoose($user, $active), 403);

        if ($requested === self::ALL) {
            return ['mode' => 'all', 'workspace' => null];
        }

        abort_unless(ctype_digit($requested), 404);

        $chosen = Workspace::with('organization')->findOrFail((int) $requested);

        return ['mode' => $chosen->is($active) ? 'active' : 'workspace', 'workspace' => $chosen];
    }

    /**
     * Workspaces offered by the Workspace selector, searched by text and capped (the list can be large).
     *
     * @return Builder<Workspace>
     */
    public function searchable(string $term): Builder
    {
        return Workspace::with('organization:id,name')->search($term)->orderBy('name')->orderBy('code');
    }
}
