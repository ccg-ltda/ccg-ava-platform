<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\ModuleAccess;
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
    public function __construct(private readonly RememberedAccess $remembered, private readonly ModuleAccess $modules) {}

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

        $user->actingIn($workspace);
        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_role', $role);
        $permissions = $catalogRole->permissions->pluck('name')->all();

        // Superusers working inside the administrative Workspace administer the platform (plans and catalog), whatever their role:
        // otherwise the only account able to grant the role could never open Planes. Never in a client Workspace.
        if ($user->isPlatformSuperuser()) {
            $permissions = array_values(array_unique([...$permissions, ...EnsurePlatformAdministration::PERMISSIONS]));
        }

        $request->attributes->set('workspace_permissions', $permissions);
        // What the plan of the Workspace includes (the permissions above still decide what this user may do in it).
        $this->modules->forRequest($request);

        return $next($request);
    }
}
