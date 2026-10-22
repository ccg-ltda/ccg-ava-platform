<?php

namespace App\Http\Controllers;

use App\Booking\Locations;
use App\Http\Controllers\Concerns\ReadsSections;
use App\Http\Requests\ReportFilterRequest;
use App\Models\Workspace;
use App\Orders\CommerceSchema;
use App\Services\DashboardMetrics;
use App\Services\LocationScope;
use App\Services\ModuleAccess;
use App\Services\OperationsMetrics;
use App\Services\WorkspaceReport;
use App\Services\WorkspaceScope;
use Illuminate\Validation\ValidationException;
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
        // The location filter of the operational figures (agenda, orders, inventory). A member limited to selected locations ALWAYS gets only theirs (without a
        // filter: all of theirs; with one: that one, and only if it is theirs); anybody else gets the whole Workspace unless they choose a location. A foreign,
        // unauthorized or unknown id is refused here, on the server, whatever the interface offered.
        $where = $this->locationFilter($request, $active, $own);
        $has = fn (string $module, string $permission) => $own && in_array($module, $modules, true) && in_array($permission, $permissions, true);
        // Inventory belongs to the product catalog module and to `view-inventory`, and only exists once its migration is applied: without all three nothing of it is read.
        $ops = ['bookings' => $has('bookings', 'view-bookings'), 'orders' => $has('orders', 'view-orders'), 'customers' => $has('customers', 'view-customers'), 'inventory' => $has('product_catalog', 'view-inventory') && app(CommerceSchema::class)->inventoryReady()];

        return Inertia::render('Reports/Index', ($team ? $report->for($active, $role, $target, $global, in_array('view-users', $permissions, true)) : ['stats' => null, 'roleBreakdown' => null, 'recentUsers' => null])
            + array_merge($analytics, ['metrics' => $conversations ? $analytics['metrics'] : []])
            + [
                'sources' => ['conversations' => $conversations, 'team' => $team] + $ops,
                'locations' => $where['view'],
                'operations' => [
                    'bookings' => $ops['bookings'] ? $this->section('bookings', fn () => $operations->bookings($active, $period, $where['ids'])) : null,
                    'orders' => $ops['orders'] ? $this->section('orders', fn () => $operations->orders($active, $period, $where['ids'])) : null,
                    'customers' => $ops['customers'] ? $this->section('customers', fn () => $operations->customers($active, $period)) : null,
                    'inventory' => $ops['inventory'] ? $this->section('inventory', fn () => $operations->inventory($active, $period, $where['ids'])) : null,
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

    /**
     * Which locations the operational reports are computed for, and what the page may offer.
     *
     * @return array{ids: ?list<int>, view: array{options: list<array{id: int, name: string, isActive: bool}>, selected: ?int, limited: bool, enabled: bool}}
     */
    private function locationFilter(ReportFilterRequest $request, Workspace $active, bool $own): array
    {
        $scope = app(LocationScope::class);
        $enabled = $own && app(Locations::class)->schemaReady() && app(ModuleAccess::class)->allows($active, 'locations');
        $none = ['ids' => null, 'view' => ['options' => [], 'selected' => null, 'limited' => false, 'enabled' => false]];

        // Not the active Workspace's own figures (an administrator looking at "all" or at another one), or the Workspace does not use locations: nothing to filter.
        if (! $enabled) {
            return $none;
        }

        $allowed = $scope->allowedIds($active, $request->user()); // null = every location
        $authorized = $active->locations()->orderBy('name')->get(['id', 'name', 'is_active'])->filter(fn ($location) => $allowed === null || in_array($location->id, $allowed, true))->values();
        $requested = $request->locationFilter();

        if ($requested !== null && ! $authorized->contains('id', $requested)) {
            abort_if($allowed !== null, 403, 'No tienes acceso a esa sede.');

            throw ValidationException::withMessages(['location' => 'Esa sede no existe en este Workspace.']);
        }

        return [
            'ids' => $requested !== null ? [$requested] : ($allowed === null ? null : $authorized->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'view' => [
                'options' => $authorized->map(fn ($location) => ['id' => (int) $location->id, 'name' => $location->name, 'isActive' => (bool) $location->is_active])->all(),
                'selected' => $requested,
                'limited' => $allowed !== null,
                'enabled' => true,
            ],
        ];
    }
}
