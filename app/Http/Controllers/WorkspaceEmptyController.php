<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where a person lands when the Workspace has no home page to show. If some module IS available and the role may open it, go there; otherwise explain
 * why there is nothing (no modules in the plan, or a subscription that does not grant access). It reads availability only: it grants nothing.
 */
class WorkspaceEmptyController extends Controller
{
    public function __invoke(Request $request, ModuleAccess $modules): Response|RedirectResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('workspace');
        $permissions = $request->attributes->get('workspace_permissions', []);
        $available = $modules->forRequest($request);

        foreach ($available as $key) {
            $entry = config("modules.implemented.{$key}");

            if ($entry && $key !== 'calendar_providers' && in_array($entry['permission'], $permissions, true)) {
                return redirect()->route($entry['route']);
            }
        }

        $subscription = $modules->subscription($workspace);
        $trialEnded = $subscription?->trialExpired() ? $subscription->trial_ends_at : null;

        return Inertia::render('Workspace/NoModules', [
            'reason' => $trialEnded ? 'trial_expired' : (! $modules->subscriptionAllows($workspace) ? 'subscription' : ($available === [] ? 'empty' : 'permissions')),
            // Only the date: it tells the person what happened; nothing about the plan, price or other Workspaces.
            'trialEndedAt' => $trialEnded?->toIso8601String(),
        ]);
    }
}
