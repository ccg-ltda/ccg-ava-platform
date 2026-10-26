<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlatformAdministration;
use App\Http\Requests\SaveWorkspaceModulesRequest;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ModuleCatalog;
use App\Services\WorkspaceLimits;
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
            // Standard (plan) and particular limits of THIS Workspace, with the usage that is really counted.
            'limits' => $this->limits($workspace),
            // The subscription and limit events of THIS Workspace, newest first (who, what, when); the same audit every administrator action goes to.
            'history' => AuditLog::where('workspace_id', $workspace->id)->whereIn('resource_type', ['subscription', 'workspace_limits', 'workspace_modules', 'ai_execution'])
                ->orderByDesc('id')->limit(15)->get(['description', 'user_name', 'actor', 'outcome', 'changes', 'created_at'])
                ->map(fn (AuditLog $log) => ['text' => $log->description, 'who' => $log->actor === 'system' ? 'Automático' : $log->user_name, 'outcome' => $log->outcome, 'at' => $log->created_at->toIso8601String(), 'changes' => $log->changes])->all(),
            // The plans that can be assigned (active ones); only offered to who may assign. The route enforces the permission again.
            'plans' => ! EnsurePlatformAdministration::allows($request, 'assign-plans') ? [] : Plan::when(Plan::solutionsReady(), fn ($query) => $query->with('solutions:module_categories.id,module_categories.name'))->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Plan $plan) => ['value' => $plan->id, 'label' => $plan->name.($plan->solutionList()->isNotEmpty() ? ' · '.$plan->solutionList()->pluck('name')->implode(', ') : '')])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function limits(Workspace $workspace): array
    {
        $limits = app(WorkspaceLimits::class);

        return [
            'ready' => $limits->available(),
            'metrics' => collect(WorkspaceLimits::METRICS)->map(fn (array $meta, string $key) => ['key' => $key, 'usage' => $limits->usage($workspace, $key)] + $meta + $limits->effective($workspace, $key))->values()->all(),
            'period' => ['renews' => $limits->period()['renews']->toIso8601String()],
        ];
    }

    public function update(SaveWorkspaceModulesRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->catalog->setWorkspaceOverrides($workspace, $request->ids('granted'), $request->ids('revoked'), $request->validated('reason'), $request->user()->id);

        return back()->with('success', 'Módulos del Workspace actualizados correctamente');
    }
}
