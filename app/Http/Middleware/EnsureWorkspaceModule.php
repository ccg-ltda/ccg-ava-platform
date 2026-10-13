<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires that the plan of the request's Workspace includes a module (and that the module is implemented). It works
 * on web and API routes alike, as long as `workspace` is in the request attributes (EnsureWorkspaceContext, or
 * AuthenticateAgent for the agent API). It only checks availability: the permission middleware still decides what the
 * user may do, and the two never replace each other.
 */
class EnsureWorkspaceModule
{
    public function __construct(private readonly ModuleAccess $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(in_array($module, $this->modules->forRequest($request), true), 403, 'Este módulo no está incluido en el plan de tu Workspace.');

        return $next($request);
    }
}
