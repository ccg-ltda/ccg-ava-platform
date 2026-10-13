<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignWorkspacePlanRequest;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ModuleCatalog;
use Illuminate\Http\RedirectResponse;

/** Assigns the commercial plan of a client Workspace. Only the platform administration reaches it (`workspace.platform`): a client never changes its own plan. */
class WorkspacePlanController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function update(AssignWorkspacePlanRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->catalog->assignPlan($workspace, Plan::findOrFail($request->validated('plan_id')));

        return back()->with('success', 'Plan asignado correctamente');
    }
}
