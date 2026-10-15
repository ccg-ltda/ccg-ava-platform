<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsSections;
use App\Http\Requests\ReportFilterRequest;
use App\Services\DashboardMetrics;
use App\Services\ModuleAccess;
use App\Services\WorkspaceScope;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ReadsSections;

    /**
     * Dashboard: the executive and operational summary of the Workspace and the home page after login (the detailed
     * analytics are Reportes). Requires `view-dashboard`. It summarizes the Workspace of the session; only a superuser working
     * inside the administrative Workspace may ask for another one or for all of them (WorkspaceScope decides, the
     * client's value is never trusted).
     *
     * Aggregated figures (counts and charts) belong to `view-dashboard`, like the team figures of Reportes. Anything that
     * identifies a conversation or an administrative event needs the permission of its own module (`view-conversations`,
     * `manage-settings`), and the assistants' executions and the connections follow the permission of their pages. Each
     * section is computed on its own: one that fails reaches the page as an error, never as a figure of zero.
     *
     * A figure also needs the MODULE it comes from to be in the plan of the Workspace being summarized: what is not
     * contracted is neither computed nor sent (it is `null`/absent, not a zero). The plan and the role permission are
     * independent: the role decides what a user may see, the plan decides what exists for the Workspace.
     */
    public function index(ReportFilterRequest $request, DashboardMetrics $metrics, WorkspaceScope $scope, ModuleAccess $access): Response
    {
        $active = $request->attributes->get('workspace');
        $permissions = $request->attributes->get('workspace_permissions', []);
        $chosen = $scope->resolve($request->user(), $active, $request->workspaceScope());
        $target = $chosen['workspace'];
        $period = $request->period($active->settingsOrDefault()->timezone);
        $settings = $active->settingsOrDefault();
        $modules = $access->forScope($target, $active);
        $has = fn (string $module) => in_array($module, $modules, true);
        $can = [
            'conversations' => $has('conversations') && in_array('view-conversations', $permissions, true),
            'assistants' => $has('assistants') && in_array('view-chatbots', $permissions, true),
            'settings' => in_array('manage-settings', $permissions, true),
        ];

        return Inertia::render('Dashboard/Index', [
            'filters' => ['period' => $period->key],
            'range' => ['from' => $settings->formatDate($period->from), 'to' => $settings->formatDate($period->to), 'days' => config("reports.periods.{$period->key}.days"), 'granularity' => $period->granularity],
            'options' => ['periods' => collect(config('reports.periods'))->map(fn ($p, $key) => ['value' => $key, 'label' => $p['label']])->values()->all()],
            'scope' => [
                'mode' => $chosen['mode'],
                'canChoose' => $scope->canChoose($request->user(), $active),
                'workspace' => $target ? ['id' => $target->id, 'name' => $target->name, 'code' => $target->code] : null,
            ],
            'can' => $can,
            // Which sources the Workspace has; the page draws nothing for the ones it does not.
            'sources' => ['conversations' => $has('conversations'), 'assistants' => $has('assistants'), 'users' => $has('users_roles')],
            'kpis' => $this->section('kpis', fn () => $metrics->kpis($target, $period, $modules)),
            'activity' => $has('conversations') ? $this->section('activity', fn () => $metrics->series($target, $period, $settings)) : null,
            'states' => $has('conversations') ? $this->section('states', fn () => $metrics->states($target, $period)) : null,
            'channels' => $has('conversations') ? $this->section('channels', fn () => $metrics->channels($target, $period)) : null,
            'attention' => $can['conversations'] || $can['assistants']
                ? $this->section('attention', fn () => $metrics->attention($target, $period, $can['conversations'], $can['assistants']))
                : null,
            // The state of the connections of ONE Workspace (the "all" view has no single list to show).
            'integrations' => $can['settings'] && $has('integrations') && $target ? $this->section('integrations', fn () => $metrics->integrations($target, $settings)) : null,
            'recentConversations' => $can['conversations'] ? $this->section('recentConversations', fn () => $metrics->recentConversations($target, $settings, mask: ! $target?->is($active))) : null,
            'recentActivity' => $can['settings'] && $has('audit') ? $this->section('recentActivity', fn () => $metrics->recentActivity($target, $settings)) : null,
            'demo' => $has('conversations') ? $this->section('demo', fn () => $metrics->demoConversations($target)) : null,
        ]);
    }
}
