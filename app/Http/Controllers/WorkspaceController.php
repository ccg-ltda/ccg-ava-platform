<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits and enables/disables Workspaces (superusers only, see the routes). Workspaces are never
 * deleted: they are disabled with `is_active`. The code is what users type at pre-login, so changing it
 * invalidates the old one; the UI asks for confirmation first.
 */
class WorkspaceController extends Controller
{
    private const CODE_FORMAT_MESSAGE = 'Usa solo letras, números, guiones y guion bajo.';

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => Workspace::normalizeCode((string) $request->input('code'))]);

        $data = $request->validate([
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')],
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('workspaces', 'code')],
            'name' => ['required', 'string', 'max:255'],
        ], ['code.regex' => self::CODE_FORMAT_MESSAGE]);

        $workspace = Workspace::create($data);

        return $this->back('Workspace creado correctamente');
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->merge(['code' => Workspace::normalizeCode((string) $request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('workspaces', 'code')->ignore($workspace->id)],
            'name' => ['required', 'string', 'max:255'],
        ], ['code.regex' => self::CODE_FORMAT_MESSAGE]);

        // Members, roles and history hang from the ID, so changing the code touches nothing else.
        $workspace->update($data);

        return $this->back('Workspace actualizado correctamente');
    }

    public function deactivate(Request $request, Workspace $workspace): RedirectResponse
    {
        if ($workspace->is($request->attributes->get('workspace'))) {
            throw ValidationException::withMessages(['status' => 'No puedes desactivar el Workspace en el que estás trabajando.']);
        }

        $workspace->update(['is_active' => false]);

        return $this->back('Workspace desactivado correctamente');
    }

    public function activate(Workspace $workspace): RedirectResponse
    {
        $workspace->update(['is_active' => true]);

        return $this->back('Workspace activado correctamente');
    }

    private function back(string $message): RedirectResponse
    {
        // Back to the page the user was on, so the page size and page of the list survive the change.
        return redirect()->back(fallback: route('users.index', ['tab' => 'workspaces']))->with('success', $message);
    }
}
