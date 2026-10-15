<?php

namespace App\Http\Controllers;

use App\Models\SupportAccess as Grant;
use App\Models\Workspace;
use App\Privacy\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Opens and ends the temporary, justified access of a platform administrator to the private data of another Workspace. Needs the
 * `access-private-data` permission (route middleware) AND to work inside the administrative Workspace (SupportAccess::grant).
 * Being a superuser does neither.
 */
class SupportAccessController extends Controller
{
    public function __construct(private readonly SupportAccess $access) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'data_type' => ['required', Rule::in(array_keys($this->access->types()))],
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'el motivo']);

        $this->access->grant($request, Workspace::findOrFail($data['workspace_id']), $data['data_type'], $data['reason']);

        return redirect()->route('conversations.index', ['workspace' => $data['workspace_id']])
            ->with('success', 'Acceso temporal abierto. Cada consulta queda registrada en la auditoría del Workspace.');
    }

    public function destroy(Request $request, int $grant): RedirectResponse
    {
        $model = Grant::with('workspace')->findOrFail($grant);
        $this->access->revoke($request, $model);

        return redirect()->route('conversations.index', ['workspace' => $model->workspace_id])->with('success', 'Acceso temporal cerrado');
    }
}
