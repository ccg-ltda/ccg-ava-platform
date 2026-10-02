<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a permission granted by the user's role inside the active Workspace
 * (workspace_user.role -> Spatie role -> permissions). Must run after EnsureWorkspaceContext.
 */
class EnsureWorkspacePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(in_array($permission, $request->attributes->get('workspace_permissions', []), true), 403);

        return $next($request);
    }
}
