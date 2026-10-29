<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;

/**
 * What the internal administration of Ava Platform always has, whatever the rows of the permission tables say.
 *
 * The role `admin` of the administrative Workspace (Desarrollo_DEV), held by a superuser, is the account that administers the platform: it must
 * never lose a module or an operation because the catalog of permissions was extended, a seeder has not run yet on that database, someone edited
 * another role or a cache was cleared. So for that one combination (superuser + administrative Workspace + role `admin`) the effective permissions are
 * the whole catalog, resolved from `config/permissions.php` on every request, not from `role_has_permissions`.
 *
 * Nothing else changes: a member of a client Workspace (superuser or not) is limited by their role and by the plan, a non-superuser in the administrative
 * Workspace is limited by their role, a superuser acting with another role (for example `supervisor`) keeps that role's limits, and the temporary
 * access to private data of another Workspace (`access-private-data`) is never part of it: it keeps its own justified, audited, time-limited flow.
 */
class PlatformAccess
{
    /** The role of the internal administration. */
    public const ROLE = 'admin';

    /** @return list<string> every permission of the catalog (config/permissions.php) */
    public function catalog(): array
    {
        return array_keys(config('permissions.permissions'));
    }

    /** @return list<string> the permissions that only operate in the administrative Workspace and are never a client's to see or hold */
    public function platformOnly(): array
    {
        return array_keys(array_filter(config('permissions.permissions'), fn (array $permission) => $permission['platform'] ?? false));
    }

    /**
     * The permissions that count inside a Workspace: those of the platform administration only operate in the administrative one, so in a client
     * Workspace they are dropped whatever the role holds. The same list decides what the request is allowed, what the interface shows and which roles
     * an administrator already covers (a client's administrator can never hand out, or use, what is not theirs to hold).
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function withinWorkspace(array $permissions, Workspace $workspace): array
    {
        return $workspace->isAdministrative() ? $permissions : array_values(array_diff($permissions, $this->platformOnly()));
    }

    /**
     * The platform permissions a shared role may carry without being the platform's: the DEMO bench is the only one that is neither commercial nor the
     * private-data access, and it is inert in a client Workspace. The role `admin` holds it in the catalog, so counting it would stop every client's
     * administrator from assigning `admin`; a role with a commercial or support permission stays above a client's administrator.
     *
     * @return list<string>
     */
    public function inertInClients(): array
    {
        return array_values(array_diff($this->platformOnly(), $this->commercial(), $this->privateData()));
    }

    /** @return list<string> the commercial administration (plans, modules, the global email template and payments): never given to a role by a seeder, only to the internal administration */
    public function commercial(): array
    {
        return array_keys(array_filter(config('permissions.permissions'), fn (array $permission) => in_array($permission['group'], ['plans', 'modules', 'email', 'payments'], true)));
    }

    /** @return list<string> reserved: the justified, temporary access to another Workspace's private data */
    public function privateData(): array
    {
        return array_keys(array_filter(config('permissions.permissions'), fn (array $permission) => $permission['private'] ?? false));
    }

    /** Whether this person, in this Workspace and with this role, is the internal administration. */
    public function isInternalAdministration(User $user, Workspace $workspace, ?string $role): bool
    {
        return $user->isSuperuserIn($workspace) && $role === self::ROLE;
    }

    /** @return list<string> */
    public function internalAdministration(): array
    {
        return array_values(array_diff($this->catalog(), $this->privateData()));
    }
}
