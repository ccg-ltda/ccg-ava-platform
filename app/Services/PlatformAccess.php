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

    /** @return list<string> the commercial administration (plans and modules): never given to a role by a seeder, only to the internal administration */
    public function commercial(): array
    {
        return array_keys(array_filter(config('permissions.permissions'), fn (array $permission) => in_array($permission['group'], ['plans', 'modules'], true)));
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
