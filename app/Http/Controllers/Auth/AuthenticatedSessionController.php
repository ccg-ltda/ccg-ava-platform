<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
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

        return Inertia::render('Auth/Login', ['workspace' => ['code' => $workspace->code]]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Server-side Workspace context, re-validated by EnsureWorkspaceContext on every request.
        $request->session()->put('workspace_id', $request->session()->pull('pre_login_workspace_id'));

        return redirect()->intended(route('dashboard', absolute: false));
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
