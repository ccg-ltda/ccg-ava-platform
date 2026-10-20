<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
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

    /**
     * The permissions of the catalog (config/permissions.php, in its order) this person may see and hand out. The permissions that only operate in the
     * administrative Workspace (plans, modules, support access, the DEMO bench) belong to the platform administration alone: anyone else never sees
     * them, in a list, a role or the editor, and the server refuses them when a role is saved.
     *
     * @return list<string>
     */
    public function visiblePermissions(User $actor): array
    {
        $names = array_keys(config('permissions.permissions'));

        return $actor->isPlatformSuperuser()
            ? $names
            : array_values(array_filter($names, fn (string $name) => ! config("permissions.permissions.{$name}.platform", false)));
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
     * escalation). Superusers acting inside the administrative Workspace may assign any role.
     *
     * The DEMO permission is inert inside a client Workspace (PlatformAccess::inertInClients), so it is not counted on the role when it is
     * handed out there: the shared role `admin` carries it in the catalog and must stay assignable by a client's administrator.
     *
     * @param  list<string>  $actorPermissions
     * @return Collection<int, Role>
     */
    public function assignableBy(User $actor, array $actorPermissions, ?Workspace $in = null): Collection
    {
        $roles = $this->all();

        if ($actor->isPlatformSuperuser()) {
            return $roles;
        }

        return $roles
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->diff($in?->isAdministrative() === false ? app(PlatformAccess::class)->inertInClients() : [])->diff($actorPermissions)->isEmpty())
            ->values();
    }
}
