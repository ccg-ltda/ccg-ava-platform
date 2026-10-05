<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\RememberedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSelectionController extends Controller
{
    public function __construct(private readonly RememberedAccess $remembered) {}

    /**
     * Pre-login: ask for the Workspace code, unless a still-valid remembered Workspace lets the user go straight
     * to the login (the server re-validates it; membership is still checked when logging in).
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($workspace = $this->remembered->workspace($request)) {
            $request->session()->put('pre_login_workspace_id', $workspace->id);

            return redirect()->route('login');
        }

        return Inertia::render('Auth/PreLogin');
    }

    /**
     * Validate the Workspace code and remember it (server side) for the login step.
     * This only selects where to log in; it does not authorize anything.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_code' => ['required', 'string', 'max:100'],
        ]);

        $workspace = Workspace::available()
            ->where('code', Workspace::normalizeCode($data['workspace_code']))
            ->first();

        if (! $workspace) {
            // Same message for unknown, disabled or organization-disabled codes.
            throw ValidationException::withMessages([
                'workspace_code' => __('El Workspace no existe o no está disponible.'),
            ]);
        }

        $request->session()->put('pre_login_workspace_id', $workspace->id);

        return redirect()->route('login');
    }

    /**
     * Forget the selected (and remembered) Workspace and go back to the pre-login step ("Cambiar").
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('pre_login_workspace_id');
        $this->remembered->forgetWorkspace();

        return redirect()->route('pre-login');
    }
}
