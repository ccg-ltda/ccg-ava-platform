<?php

namespace App\Services;

use App\Models\Workspace;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Figures shown on the Reportes page. Everything starts from the given Workspace,
 * so nothing from other Workspaces can leak into the report.
 */
class WorkspaceReport
{
    private const RECENT_USERS = 5;

    /**
     * The team figures of the scope: one Workspace (`$target`) or, with `$global`, every Workspace. Dates use the
     * regional settings of the Workspace the viewer is working in (`$active`).
     *
     * @return array{stats: array<string, int>, roleBreakdown: list<array{role: string, count: int}>, recentUsers: list<array<string, mixed>>}
     */
    public function for(Workspace $active, string $role, ?Workspace $target = null, bool $global = false): array
    {
        $target ??= $active;
        $members = fn () => DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->when(! $global, fn ($query) => $query->where('workspace_user.workspace_id', $target->id));

        $byRole = $members()
            ->selectRaw('workspace_user.role as role, count(distinct workspace_user.user_id) as total')
            ->groupBy('workspace_user.role')
            ->orderByDesc('total')->orderBy('role')
            ->pluck('total', 'role');

        $settings = $active->settingsOrDefault();

        return [
            'stats' => [
                'users' => $this->memberCount($global ? null : $target),
                'admins' => (int) ($byRole['admin'] ?? 0),
                'rolesInUse' => $byRole->count(),
                'permissions' => Role::findByName($role, 'web')->permissions()->count(),
            ],
            'roleBreakdown' => $byRole->map(fn ($count, $name) => ['role' => $name, 'count' => (int) $count])->values()->all(),
            'recentUsers' => $members()
                ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
                ->orderByDesc('users.created_at')->orderByDesc('users.id')
                ->limit(self::RECENT_USERS)
                ->get(['users.id', 'users.name', 'users.email', 'users.created_at', 'workspace_user.role', 'workspaces.name as workspace'])
                ->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'workspace' => $global ? $user->workspace : null,
                    'createdAt' => $settings->formatDate(CarbonImmutable::parse($user->created_at, 'UTC')),
                ])
                ->values()
                ->all(),
        ];
    }

    /** People with a membership in one Workspace, or in any of them when `$target` is null (each person counted once). */
    public function memberCount(?Workspace $target): int
    {
        return DB::table('workspace_user')
            ->when($target, fn ($query) => $query->where('workspace_id', $target->id))
            ->distinct()->count('user_id');
    }

    /**
     * What the analytics part of Reportes needs: the period with its options, the metrics (none has a source yet, so
     * none reports a value) and the one real series available today, the people added to this Workspace.
     *
     * @return array<string, mixed>
     */
    public function analytics(Workspace $active, ReportPeriod $period, ?Workspace $target = null, bool $global = false): array
    {
        $settings = $active->settingsOrDefault();

        return [
            'filters' => ['period' => $period->key, 'granularity' => $period->granularity],
            'range' => ['from' => $settings->formatDate($period->from), 'to' => $settings->formatDate($period->to), 'days' => config("reports.periods.{$period->key}.days")],
            'options' => [
                'periods' => collect(config('reports.periods'))->map(fn ($p, $key) => ['value' => $key, 'label' => $p['label']])->values()->all(),
                'granularities' => collect($period->granularities())->map(fn ($g) => ['value' => $g, 'label' => config("reports.granularities.{$g}")])->all(),
            ],
            // A metric is "connected" once it has a source; until then it carries no value at all (never a placeholder number).
            'metrics' => collect(config('reports.metrics'))->map(fn ($metric, $key) => [
                'key' => $key,
                'label' => $metric['label'],
                'hint' => $metric['hint'],
                'section' => $metric['section'],
                'connected' => $metric['source'] !== null,
                'value' => null,
            ])->values()->all(),
            'teamGrowth' => $this->teamGrowth($active, $period, $target ?? $active, $global),
        ];
    }

    /**
     * People added to the Workspace per bucket of the period (from the membership timestamps).
     *
     * @return array{total: int, points: list<array{key: string, label: string, value: int}>}
     */
    private function teamGrowth(Workspace $active, ReportPeriod $period, Workspace $target, bool $global): array
    {
        $settings = $active->settingsOrDefault();

        $counts = DB::table('workspace_user')
            ->when(! $global, fn ($query) => $query->where('workspace_id', $target->id))
            ->whereBetween('created_at', [$period->from->utc(), $period->to->utc()])
            ->pluck('created_at')
            ->countBy(fn ($createdAt) => $period->bucketStart(CarbonImmutable::parse($createdAt, 'UTC'))->toDateString());

        $points = collect($period->buckets())->map(fn (CarbonImmutable $start) => [
            'key' => $start->toDateString(),
            'label' => match ($period->granularity) {
                'month' => $start->locale('es')->isoFormat('MMM YYYY'),
                'week' => 'Semana del '.$settings->formatDate($start),
                default => $settings->formatDate($start),
            },
            'short' => $period->granularity === 'month' ? $start->locale('es')->isoFormat('MMM') : $start->format('d/m'),
            'value' => (int) ($counts[$start->toDateString()] ?? 0),
        ])->all();

        return ['total' => (int) $counts->sum(), 'points' => $points];
    }
}
