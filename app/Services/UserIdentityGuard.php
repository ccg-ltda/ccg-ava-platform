<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * name/email belong to the global account, not to a Workspace membership.
 * A Workspace admin may change them only for accounts that exist solely in the Workspace and are
 * not superusers; a superuser may always. Single source of the rule: it is enforced on update and
 * reported to the UI (so the user is warned before saving).
 */
class UserIdentityGuard
{
    public function canEdit(User $actor, User $target, Workspace $workspace): bool
    {
        if ($actor->is_superuser) {
            return true;
        }

        return ! $target->is_superuser
            && ! $target->workspaces()->whereKeyNot($workspace->getKey())->exists();
    }

    /**
     * IDs (from the given members) whose name/email the actor cannot change. One query for all of them.
     *
     * @param  Collection<int, User>  $members
     * @return list<int>
     */
    public function lockedIds(User $actor, Collection $members, Workspace $workspace): array
    {
        if ($actor->is_superuser) {
            return [];
        }

        $shared = DB::table('workspace_user')
            ->whereIn('user_id', $members->pluck('id'))
            ->where('workspace_id', '!=', $workspace->getKey())
            ->pluck('user_id');

        return $members
            ->filter(fn (User $member) => $member->is_superuser || $shared->contains($member->id))
            ->pluck('id')
            ->all();
    }
}
