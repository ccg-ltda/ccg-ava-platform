<?php

namespace App\Http\Middleware;

use App\Services\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires that the modules of the request's Workspace include a module (and that the module is implemented). It works
 * on web and API routes alike, as long as `workspace` is in the request attributes (EnsureWorkspaceContext, or
 * AuthenticateAgent for the agent API). It only checks availability: the permission middleware still decides what the
 * user may do, and the two never replace each other.
 *
 * "Available" is whatever ModuleAccess says: the plan, the subscription and the exceptions of that Workspace. When the home page (the Dashboard) is
 * not available, a person is taken to the page that explains what the Workspace has instead of meeting an error.
 */
class EnsureWorkspaceModule
{
    public function __construct(private readonly ModuleAccess $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! in_array($module, $this->modules->forRequest($request), true)) {
            if ($module === 'dashboard' && $request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->route('workspace.empty');
            }

            abort(403, $this->modules->subscriptionAllows($request->attributes->get('workspace'))
                ? 'Este módulo no está incluido en el plan de tu Workspace.'
                : 'La suscripción de tu Workspace no permite acceder a este módulo. Contacta con soporte.');
        }

        return $next($request);
    }
}
