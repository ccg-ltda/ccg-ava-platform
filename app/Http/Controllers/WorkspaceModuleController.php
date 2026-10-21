<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlatformAdministration;
use App\Http\Requests\SaveWorkspaceModulesRequest;
use App\Models\Workspace;
use App\Services\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The modules of ONE client Workspace: what its plan gives, the exceptions CCG authorized for it and what is available now. Only the platform
 * administration reaches it (`workspace.platform`); reading needs `view-plans` and changing exceptions `assign-plans`. A client never sees or edits
 * this, and an exception touches neither the plan nor any other Workspace.
 */
class WorkspaceModuleController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function show(Request $request, Workspace $workspace): Response
    {
        abort_if($workspace->isAdministrative(), 404);

        return Inertia::render('Plans/WorkspaceModules', [
            'workspace' => ['id' => $workspace->id, 'code' => $workspace->code, 'name' => $workspace->name, 'organization' => $workspace->organization->name],
            'data' => $this->catalog->workspaceModules($workspace),
            'canEdit' => EnsurePlatformAdministration::allows($request, 'assign-plans'),
        ]);
    }

    public function update(SaveWorkspaceModulesRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->catalog->setWorkspaceOverrides($workspace, $request->ids('granted'), $request->ids('revoked'), $request->validated('reason'), $request->user()->id);

        return back()->with('success', 'Módulos del Workspace actualizados correctamente');
    }
}
