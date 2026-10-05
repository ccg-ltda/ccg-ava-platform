<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\RememberedAccess;
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
    public function __construct(private readonly RememberedAccess $remembered) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // A user brought back by the "remember me" cookie has no session Workspace yet: use the remembered one,
        // which goes through exactly the same checks below.
        $fromMemory = ! $request->session()->has('workspace_id');
        $workspaceId = $request->session()->get('workspace_id') ?? $this->remembered->workspace($request)?->id;

        $workspace = $workspaceId
            ? Workspace::available()->with('organization')->find($workspaceId)
            : null;

        // A deactivated account loses its open sessions too, not only the ability to log in.
        $role = ($user->is_active && $workspace && $user->canAccessWorkspace($workspace))
            ? $user->roleInWorkspace($workspace)
            : null;

        // The role must exist in the Spatie catalog; its permissions are what authorizes the user here.
        $catalogRole = $role
            ? Role::with('permissions:id,name')->where('name', $role)->where('guard_name', 'web')->first()
            : null;

        if (! $catalogRole) {
            $this->remembered->forgetWorkspace();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('pre-login')
                ->withErrors(['workspace_code' => __('Tu sesión de Workspace no es válida. Ingresa nuevamente.')]);
        }

        if ($fromMemory) {
            $request->session()->put('workspace_id', $workspace->id);
        }

        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_role', $role);
        $request->attributes->set('workspace_permissions', $catalogRole->permissions->pluck('name')->all());

        return $next($request);
    }
}
