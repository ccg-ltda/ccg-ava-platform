<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Workspace;
use App\Services\LandingPage;
use App\Services\ModuleAccess;
use App\Services\RememberedAccess;
use App\Services\WorkspaceAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly RememberedAccess $remembered,
        private readonly LandingPage $landing,
        private readonly WorkspaceAdministration $administration,
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * Display the login view.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $workspace = Workspace::available()->find($request->session()->get('pre_login_workspace_id'));

        if (! $workspace) {
            $request->session()->forget('pre_login_workspace_id');

            return redirect()->route('pre-login');
        }

        return Inertia::render('Auth/Login', [
            'workspace' => ['code' => $workspace->code],
            'rememberedEmail' => $this->remembered->email($request),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Server-side Workspace context, re-validated by EnsureWorkspaceContext on every request.
        $workspaceId = $request->session()->pull('pre_login_workspace_id');
        $request->session()->put('workspace_id', $workspaceId);

        // "Recordar sesión" also decides whether the email and Workspace are remembered (never the password).
        if ($request->boolean('remember')) {
            $this->remembered->remember($request->user()->email, $workspaceId);
        } else {
            $this->remembered->forget();
        }

        // The Dashboard, or the first page the user's role and the Workspace's plan allow (a role without `view-dashboard`, a plan without it).
        $workspace = Workspace::findOrFail($workspaceId);
        $permissions = $this->administration->permissionsIn($request->user(), $workspace);

        // The page a guest asked for before signing in is honoured only when THIS account could open it (see LandingPage::afterLogin); otherwise the usual landing. Either way
        // the remembered target is consumed, so a stale one can never wait for the next login.
        [$path] = $this->landing->afterLogin($request->session()->pull('url.intended'), $request->getHost(), $permissions, $this->modules->keys($workspace));

        return redirect($path);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('pre-login');
    }
}
