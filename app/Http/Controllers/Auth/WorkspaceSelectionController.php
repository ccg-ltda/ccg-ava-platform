<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSelectionController extends Controller
{
    /**
     * Pre-login: ask for the Workspace code.
     */
    public function create(): Response
    {
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
     * Forget the selected Workspace and go back to the pre-login step.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('pre_login_workspace_id');

        return redirect()->route('pre-login');
    }
}
