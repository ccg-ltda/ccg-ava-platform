<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Services\WorkspaceReport;
use App\Services\WorkspaceScope;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Reportes: the Workspace analytics center (the detailed view; the executive summary is the Dashboard). Requires
     * `view-dashboard`. It reports on
     * the Workspace of the session; only a superuser working inside the administrative Workspace may ask for another
     * one or for all of them (WorkspaceScope decides, the client's value is never trusted).
     */
    public function index(ReportFilterRequest $request, WorkspaceReport $report, WorkspaceScope $scope): Response
    {
        $active = $request->attributes->get('workspace');
        $role = $request->attributes->get('workspace_role');
        $chosen = $scope->resolve($request->user(), $active, $request->workspaceScope());
        $global = $chosen['mode'] === 'all';
        $period = $request->period($active->settingsOrDefault()->timezone);

        return Inertia::render('Reports/Index', $report->for($active, $role, $chosen['workspace'], $global)
            + $report->analytics($active, $period, $chosen['workspace'], $global)
            + ['scope' => [
                'mode' => $chosen['mode'],
                'canChoose' => $scope->canChoose($request->user(), $active),
                'workspace' => $chosen['workspace'] ? ['id' => $chosen['workspace']->id, 'name' => $chosen['workspace']->name, 'code' => $chosen['workspace']->code] : null,
            ]]);
    }
}
