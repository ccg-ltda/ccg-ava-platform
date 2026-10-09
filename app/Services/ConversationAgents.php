<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Who can attend conversations: the users whose role in a Workspace grants `reply-conversations`. It is the single place
 * that decides it, so a later limit of agents per plan has exactly one place to be applied.
 */
class ConversationAgents
{
    public const PERMISSION = 'reply-conversations';

    /** Whether the user is an active agent of the Workspace right now. */
    public function isAgent(User $user, Workspace $workspace): bool
    {
        if (! $user->is_active || ! $user->canAccessWorkspace($workspace)) {
            return false;
        }

        $role = $user->roleInWorkspace($workspace);

        return $role !== null && in_array($role, $this->roles(), true);
    }

    /** @return Collection<int, User> the active members of the Workspace who can attend, by name */
    public function of(Workspace $workspace): Collection
    {
        return $workspace->users()->where('users.is_active', true)->wherePivotIn('role', $this->roles())->orderBy('users.name')->get(['users.id', 'users.name']);
    }

    /** @return list<string> names of the roles of the catalog that grant the permission */
    private function roles(): array
    {
        return Role::where('guard_name', 'web')->whereHas('permissions', fn ($q) => $q->where('name', self::PERMISSION))->pluck('name')->all();
    }
}
