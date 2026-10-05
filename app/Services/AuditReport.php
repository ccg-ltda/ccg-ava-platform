<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reads the audit log for Auditoría and its PDF. The scope always starts from the Workspace of the request: a
 * superuser may widen it to another Workspace or to all of them, anyone else never leaves their own.
 *
 * @phpstan-type Filters array{search: string, from: ?string, to: ?string, user: ?int, workspace: ?string, resource: ?string, action: ?string}
 */
class AuditReport
{
    public function __construct(private readonly WorkspaceScope $scope) {}

    /** Filters a query by everything except the action (the summary counts each action under the other filters). */
    public function query(Workspace $active, User $viewer, array $filters, bool $withAction = true): Builder
    {
        $query = AuditLog::query();

        // Only who may choose a scope (WorkspaceScope) can widen it; any other filter value falls back to the session's Workspace.
        $chooses = $this->scope->canChoose($viewer, $active);

        match (true) {
            $chooses && $filters['workspace'] === WorkspaceScope::ALL => null,
            $chooses && $filters['workspace'] !== null => $query->where('workspace_id', (int) $filters['workspace']),
            default => $query->where('workspace_id', $active->id),
        };

        $timezone = $active->settingsOrDefault()->timezone;

        return $query
            ->when($filters['from'], fn (Builder $q, string $from) => $q->where('created_at', '>=', $this->startOf($from, $timezone)))
            ->when($filters['to'], fn (Builder $q, string $to) => $q->where('created_at', '<=', $this->endOf($to, $timezone)))
            ->when($filters['user'], fn (Builder $q, int $user) => $q->where('user_id', $user))
            ->when($filters['resource'], fn (Builder $q, string $resource) => $q->where('resource_type', $resource))
            ->when($withAction ? $filters['action'] : null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['search'] !== '', fn (Builder $q) => $this->search($q, $filters['search']));
    }

    /** Newest first; the id breaks ties so paging is stable for events of the same second. */
    public function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return array{total: int, created: int, updated: int, deleted: int} */
    public function summary(Workspace $active, User $viewer, array $filters): array
    {
        $counts = $this->query($active, $viewer, $filters, withAction: false)
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        return [
            'total' => (int) $counts->sum(),
            'created' => (int) ($counts['created'] ?? 0),
            'updated' => (int) ($counts['updated'] ?? 0),
            'deleted' => (int) ($counts['deleted'] ?? 0),
        ];
    }

    /**
     * Users that have events in the scope, for the filter (by id, with the name they had at the latest event).
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    public function users(Workspace $active, User $viewer, ?string $workspaceFilter): Collection
    {
        $scope = $this->query($active, $viewer, $this->blank(['workspace' => $workspaceFilter]));

        return $scope->whereNotNull('user_id')
            ->selectRaw('user_id, max(user_name) as name')
            ->groupBy('user_id')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn ($row) => ['value' => (string) $row->user_id, 'label' => $row->name]);
    }

    /** @return array<string, ?string> every filter empty, with the given ones set */
    public function blank(array $set = []): array
    {
        return $set + ['search' => '', 'from' => null, 'to' => null, 'user' => null, 'workspace' => null, 'resource' => null, 'action' => null];
    }

    private function startOf(string $date, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()->setTimezone(config('app.timezone'));
    }

    private function endOf(string $date, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, $timezone)->endOfDay()->setTimezone(config('app.timezone'));
    }

    private function search(Builder $query, string $term): Builder
    {
        // "!" is the escape character so user input cannot act as a LIKE wildcard.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            foreach (['user_name', 'user_email', 'resource_label', 'description', 'workspace_name', 'ip_address'] as $column) {
                $q->orWhereRaw("lower({$column}) like ? escape '!'", [$like]);
            }
        });
    }
}
