<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Services\DashboardMetrics;
use App\Services\WorkspaceScope;
use Closure;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
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
     */
    public function index(ReportFilterRequest $request, DashboardMetrics $metrics, WorkspaceScope $scope): Response
    {
        $active = $request->attributes->get('workspace');
        $permissions = $request->attributes->get('workspace_permissions', []);
        $chosen = $scope->resolve($request->user(), $active, $request->workspaceScope());
        $target = $chosen['workspace'];
        $period = $request->period($active->settingsOrDefault()->timezone);
        $settings = $active->settingsOrDefault();
        $can = [
            'conversations' => in_array('view-conversations', $permissions, true),
            'assistants' => in_array('view-chatbots', $permissions, true),
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
            'kpis' => $this->section('kpis', fn () => $metrics->kpis($target, $period)),
            'activity' => $this->section('activity', fn () => $metrics->series($target, $period, $settings)),
            'states' => $this->section('states', fn () => $metrics->states($target, $period)),
            'channels' => $this->section('channels', fn () => $metrics->channels($target, $period)),
            'attention' => $can['conversations'] || $can['assistants']
                ? $this->section('attention', fn () => $metrics->attention($target, $period, $can['conversations'], $can['assistants']))
                : null,
            // The state of the connections of ONE Workspace (the "all" view has no single list to show).
            'integrations' => $can['settings'] && $target ? $this->section('integrations', fn () => $metrics->integrations($target, $settings)) : null,
            'recentConversations' => $can['conversations'] ? $this->section('recentConversations', fn () => $metrics->recentConversations($target, $settings)) : null,
            'recentActivity' => $can['settings'] ? $this->section('recentActivity', fn () => $metrics->recentActivity($target, $settings)) : null,
            'demo' => $this->section('demo', fn () => $metrics->demoConversations($target)),
        ]);
    }

    /**
     * Runs one section. A failure is logged with its class only (an exception can carry data) and the page receives an
     * error for that section, so "nothing to show" and "could not be read" are never confused.
     *
     * @return array{data: mixed, error: ?string}
     */
    private function section(string $name, Closure $compute): array
    {
        try {
            return ['data' => $compute(), 'error' => null];
        } catch (Throwable $e) {
            Log::error('dashboard_section_failed', ['section' => $name, 'exception' => $e::class]);

            return ['data' => null, 'error' => 'No se pudieron leer estos datos. Intenta de nuevo en unos minutos.'];
        }
    }
}
