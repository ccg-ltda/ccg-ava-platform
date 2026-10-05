<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Roles and permissions model.
 *
 * - Spatie `roles` + `permissions` are the GLOBAL catalog: what each role name is allowed to do.
 * - `workspace_user.role` assigns one of those roles to a user inside one Workspace.
 * - Authorization is derived from that role's permissions (see EnsureWorkspacePermission).
 *
 * Because the catalog is shared by every Workspace, only superusers may change it.
 */
class RoleCatalog
{
    /** Built-in roles that can not lose permissions (the app depends on them). */
    public const PROTECTED_ROLES = ['admin'];

    public function isProtected(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED_ROLES, true);
    }

    /** @return Builder<Role> the global catalog, with each role's permissions, by name */
    public function query(): Builder
    {
        return Role::with('permissions:id,name')->where('guard_name', 'web')->orderBy('name');
    }

    /** @return Collection<int, Role> */
    public function all(): Collection
    {
        return $this->query()->get();
    }

    /**
     * Roles the actor may assign: those whose permissions the actor already holds (no privilege
     * escalation). Superusers may assign any role.
     *
     * @param  list<string>  $actorPermissions
     * @return Collection<int, Role>
     */
    public function assignableBy(User $actor, array $actorPermissions): Collection
    {
        $roles = $this->all();

        if ($actor->is_superuser) {
            return $roles;
        }

        return $roles
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->diff($actorPermissions)->isEmpty())
            ->values();
    }
}
