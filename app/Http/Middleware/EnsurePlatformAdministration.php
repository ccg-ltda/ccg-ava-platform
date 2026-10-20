<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the commercial administration of Ava Platform (plans and the module catalog) to users working INSIDE the administrative
 * Workspace whose permissions include the one the action needs. Every operation has its own (config/permissions.php, groups `plans` and `modules`):
 * `view-plans` / `view-modules` open each tab and the rest create, edit, activate or assign. The permission comes from the role (nothing grants it
 * to a role automatically, not even `admin`) or from being the internal administration of the platform (PlatformAccess, resolved in
 * EnsureWorkspaceContext). In a client Workspace the permission does nothing, whatever the role. Must run after EnsureWorkspaceContext.
 * `allows()` is the single rule: the middleware enforces it and the navigation only mirrors it. Several permissions mean "any of them".
 */
class EnsurePlatformAdministration
{
    /** The permission that opens each part of the page (Planes / Módulos). */
    public const PLANS = 'view-plans';

    public const MODULES = 'view-modules';

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
