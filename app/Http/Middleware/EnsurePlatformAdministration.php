<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the commercial administration of Ava Platform (plans and the module catalog) to users working INSIDE the
 * administrative Workspace whose role grants the permission of the area: `manage-plans` (plans and their assignment to
 * Workspaces) or `manage-modules` (modules and categories). Being a superuser is neither required nor enough: the permission
 * comes from the role, and nothing grants it automatically (not even `admin`). In a client Workspace the permission does
 * nothing, whatever the role. Must run after EnsureWorkspaceContext. `allows()` is the single rule: the
 * middleware enforces it and the navigation only mirrors it. Several permissions mean "any of them".
 */
class EnsurePlatformAdministration
{
    public const PLANS = 'manage-plans';

    public const MODULES = 'manage-modules';

    public static function allows(Request $request, string ...$permissions): bool
    {
        $permissions = $permissions ?: [self::PLANS, self::MODULES];

        return (bool) $request->user()
            && (bool) $request->attributes->get('workspace')?->isAdministrative()
            && array_intersect($permissions, $request->attributes->get('workspace_permissions', [])) !== [];
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless(static::allows($request, ...$permissions), 403);

        return $next($request);
    }
}
