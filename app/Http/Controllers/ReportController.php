<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsSections;
use App\Http\Requests\ReportFilterRequest;
use App\Services\DashboardMetrics;
use App\Services\WorkspaceReport;
use App\Services\WorkspaceScope;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    use ReadsSections;

    /**
     * Reportes: the Workspace analytics center (the detailed view; the executive summary is the Dashboard). Requires
     * `view-dashboard`. It reports on the Workspace of the session; only a superuser working inside the administrative
     * Workspace may ask for another one or for all of them (WorkspaceScope decides, the client's value is never trusted).
     *
     * The conversation and message figures come from the same `DashboardMetrics` calculations as the Dashboard, so both
     * pages agree. They are aggregates (`view-dashboard`); who the people are (names, emails) needs `view-users`. Each
     * section is computed on its own: one that fails reaches the page as an error, never as a figure of zero.
     */
    public function index(ReportFilterRequest $request, WorkspaceReport $report, DashboardMetrics $metrics, WorkspaceScope $scope): Response
    {
        $active = $request->attributes->get('workspace');
        $role = $request->attributes->get('workspace_role');
        $permissions = $request->attributes->get('workspace_permissions', []);
        $chosen = $scope->resolve($request->user(), $active, $request->workspaceScope());
        $target = $chosen['workspace'];
        $global = $chosen['mode'] === 'all';
        $settings = $active->settingsOrDefault();
        $period = $request->period($settings->timezone);

        return Inertia::render('Reports/Index', $report->for($active, $role, $target, $global, in_array('view-users', $permissions, true))
            + $report->analytics($active, $period, $target, $global)
            + [
                'figures' => $this->section('figures', fn () => $metrics->comparison($target, $period)),
                'activity' => $this->section('activity', fn () => $metrics->series($target, $period, $settings)),
                'channels' => $this->section('channels', fn () => $metrics->channels($target, $period)),
                'states' => $this->section('states', fn () => $metrics->states($target, $period)),
                'chatHours' => $this->section('chatHours', fn () => $metrics->hours($target, $period, 'conversations')),
                'messageHours' => $this->section('messageHours', fn () => $metrics->hours($target, $period, 'messages')),
                'scope' => [
                    'mode' => $chosen['mode'],
                    'canChoose' => $scope->canChoose($request->user(), $active),
                    'workspace' => $target ? ['id' => $target->id, 'name' => $target->name, 'code' => $target->code] : null,
                ],
            ]);
    }
}
