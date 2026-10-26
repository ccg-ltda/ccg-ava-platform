<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a permission granted by the user's role inside the active Workspace
 * (workspace_user.role -> Spatie role -> permissions). Several permissions mean "any of them". Must run after EnsureWorkspaceContext.
 */
class EnsureWorkspacePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless(array_intersect($permissions, $request->attributes->get('workspace_permissions', [])) !== [], 403, 'Tu rol no tiene permiso para esta acción. Pídele a un administrador de tu Workspace que te lo asigne.');

        return $next($request);
    }
}
