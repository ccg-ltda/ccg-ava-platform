<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignWorkspacePlanRequest;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ModuleCatalog;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;

/** Assigns the commercial plan of a client Workspace. Only the platform administration reaches it (`workspace.platform`): a client never changes its own plan. */
class WorkspacePlanController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog, private readonly SubscriptionService $subscriptions) {}

    public function update(AssignWorkspacePlanRequest $request, Workspace $workspace): RedirectResponse
    {
        $plan = Plan::findOrFail($request->validated('plan_id'));
        $this->catalog->assignPlan($workspace, $plan);
        // A Workspace with a subscription follows its new plan and its conditions are frozen again from it (a new agreement).
        $this->subscriptions->planChanged($workspace, $plan);

        return back()->with('success', 'Plan asignado correctamente');
    }
}
