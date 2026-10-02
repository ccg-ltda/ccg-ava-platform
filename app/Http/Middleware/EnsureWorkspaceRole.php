<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the user's role inside the active Workspace (not a global role).
 * Must run after EnsureWorkspaceContext.
 */
class EnsureWorkspaceRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->attributes->get('workspace_role'), $roles, true), 403);

        return $next($request);
    }
}
