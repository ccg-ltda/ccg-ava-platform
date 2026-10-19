<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsSections;
use App\Http\Requests\ReportFilterRequest;
use App\Services\DashboardMetrics;
use App\Services\ModuleAccess;
use App\Services\OperationsMetrics;
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
     *
     * What the Workspace's plan does not include is neither computed nor sent: the conversation and message figures need
     * `conversations`, the team figures `users_roles` (the page hides the sections that have no source).
     */
    public function index(ReportFilterRequest $request, WorkspaceReport $report, DashboardMetrics $metrics, OperationsMetrics $operations, WorkspaceScope $scope, ModuleAccess $access): Response
    {
        $active = $request->attributes->get('workspace');
        $role = $request->attributes->get('workspace_role');
        $permissions = $request->attributes->get('workspace_permissions', []);
        $chosen = $scope->resolve($request->user(), $active, $request->workspaceScope());
        $target = $chosen['workspace'];
        $global = $chosen['mode'] === 'all';
        $settings = $active->settingsOrDefault();
        $period = $request->period($settings->timezone);
        $modules = $access->forScope($target, $active);
        $conversations = in_array('conversations', $modules, true);
        $team = in_array('users_roles', $modules, true);
        $analytics = $report->analytics($active, $period, $target, $global, $team);
        // Agenda, Pedidos and Clientes: only for the ACTIVE Workspace (never for "all" nor another one), only when its plan includes the module AND the role may read
        // that module (the figures include money and customers, so `view-dashboard` alone is not enough).
        $own = $target !== null && $target->is($active);
        $has = fn (string $module, string $permission) => $own && in_array($module, $modules, true) && in_array($permission, $permissions, true);
        $ops = ['bookings' => $has('bookings', 'view-bookings'), 'orders' => $has('orders', 'view-orders'), 'customers' => $has('customers', 'view-customers')];

        return Inertia::render('Reports/Index', ($team ? $report->for($active, $role, $target, $global, in_array('view-users', $permissions, true)) : ['stats' => null, 'roleBreakdown' => null, 'recentUsers' => null])
            + array_merge($analytics, ['metrics' => $conversations ? $analytics['metrics'] : []])
            + [
                'sources' => ['conversations' => $conversations, 'team' => $team] + $ops,
                'operations' => [
                    'bookings' => $ops['bookings'] ? $this->section('bookings', fn () => $operations->bookings($active, $period)) : null,
                    'orders' => $ops['orders'] ? $this->section('orders', fn () => $operations->orders($active, $period)) : null,
                    'customers' => $ops['customers'] ? $this->section('customers', fn () => $operations->customers($active, $period)) : null,
                ],
                'figures' => $conversations ? $this->section('figures', fn () => $metrics->comparison($target, $period)) : null,
                'activity' => $conversations ? $this->section('activity', fn () => $metrics->series($target, $period, $settings)) : null,
                'channels' => $conversations ? $this->section('channels', fn () => $metrics->channels($target, $period)) : null,
                'states' => $conversations ? $this->section('states', fn () => $metrics->states($target, $period)) : null,
                'chatHours' => $conversations ? $this->section('chatHours', fn () => $metrics->hours($target, $period, 'conversations')) : null,
                'messageHours' => $conversations ? $this->section('messageHours', fn () => $metrics->hours($target, $period, 'messages')) : null,
                'scope' => [
                    'mode' => $chosen['mode'],
                    'canChoose' => $scope->canChoose($request->user(), $active),
                    'workspace' => $target ? ['id' => $target->id, 'name' => $target->name, 'code' => $target->code] : null,
                ],
            ]);
    }
}
