<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user to the Workspace chosen at login and
 * re-validates that binding on every request. The Workspace is read only
 * from the server-side session, never from the request input.
 */
class EnsureWorkspaceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = $request->session()->get('workspace_id');

        $workspace = $workspaceId
            ? Workspace::available()->with('organization')->find($workspaceId)
            : null;

        $role = ($workspace && $user->canAccessWorkspace($workspace))
            ? $user->roleInWorkspace($workspace)
            : null;

        if (! $role || ! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('pre-login')
                ->withErrors(['workspace_code' => __('Tu sesión de Workspace no es válida. Ingresa nuevamente.')]);
        }

        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_role', $role);

        return $next($request);
    }
}
