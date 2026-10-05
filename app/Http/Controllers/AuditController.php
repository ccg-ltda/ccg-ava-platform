<?php

namespace App\Http\Controllers;

use App\Audit\AuditPdf;
use App\Audit\AuditPresenter;
use App\Http\Requests\AuditFilterRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditReport;
use App\Services\ListPagination;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Auditoría: who changed what, where and when, in the active Workspace (a superuser may widen the view). The
 * page and the PDF share the same filters and the same query. Every action requires `manage-settings`.
 */
class AuditController extends Controller
{
    public function __construct(private readonly AuditReport $report) {}

    public function index(AuditFilterRequest $request): InertiaResponse
    {
        $workspace = $request->attributes->get('workspace');
        $viewer = $request->user();
        $filters = $request->filters();
        $present = new AuditPresenter($workspace->settingsOrDefault());

        $page = $this->report->ordered($this->report->query($workspace, $viewer, $filters))
            ->paginate($request->perPage(), ['*'], 'page')
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'events' => [
                'data' => $page->getCollection()->map(fn ($log) => $present->present($log))->values(),
                'meta' => ListPagination::meta($page),
            ],
            'summary' => $this->report->summary($workspace, $viewer, $filters),
            'filters' => $filters + ['perPage' => $request->perPage()],
            'perPageOptions' => ListPagination::OPTIONS,
            'options' => [
                'users' => $this->report->users($workspace, $viewer, $filters['workspace']),
                'workspaces' => $viewer->is_superuser ? $this->workspaceOptions() : null,
                'resources' => $this->labels('resources'),
                'actions' => $this->labels('actions'),
            ],
            'currentWorkspace' => ['id' => $workspace->id, 'name' => $workspace->name],
        ]);
    }

    public function export(AuditFilterRequest $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $viewer = $request->user();
        $filters = $request->filters();
        $settings = $workspace->settingsOrDefault();
        $present = new AuditPresenter($settings);
        $limit = (int) config('audit.pdf_max_events');

        $query = $this->report->query($workspace, $viewer, $filters);
        $matching = (clone $query)->count();
        $events = $this->report->ordered($query)->limit($limit)->get()->map(fn ($log) => $present->present($log));

        $now = now();
        $pdf = (new AuditPdf)->render($events, [
            'workspace' => $this->scopeName($workspace, $viewer, $filters['workspace']),
            'period' => $this->period($filters, $settings),
            'filters' => $this->appliedFilters($filters, $viewer, $workspace),
            'generatedBy' => "{$viewer->name} ({$viewer->email})",
            'generatedAt' => $settings->formatDate($now).' '.$settings->formatPreciseTime($now),
            'included' => $events->count(),
            'matching' => $matching,
            'summary' => $this->report->summary($workspace, $viewer, $filters),
            'brand' => $this->brandColor($settings->primary_color),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="auditoria-'.preg_replace('/[^a-z0-9_-]/', '', strtolower($workspace->code)).'-'.$now->format('Ymd-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function labels(string $group): array
    {
        return collect(config("audit.{$group}"))->map(fn ($item, $key) => ['value' => $key, 'label' => $item['label']])->values()->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function workspaceOptions(): array
    {
        return Workspace::orderBy('name')->get(['id', 'name', 'code'])
            ->map(fn ($workspace) => ['value' => (string) $workspace->id, 'label' => "{$workspace->name} ({$workspace->code})"])
            ->all();
    }

    private function scopeName(Workspace $workspace, User $viewer, ?string $filter): string
    {
        if (! $viewer->is_superuser || $filter === null) {
            return "{$workspace->name} ({$workspace->code})";
        }

        if ($filter === 'all') {
            return 'Todos los Workspaces';
        }

        $chosen = Workspace::find((int) $filter);

        return $chosen ? "{$chosen->name} ({$chosen->code})" : "{$workspace->name} ({$workspace->code})";
    }

    /** @param  array<string, mixed>  $filters */
    private function period(array $filters, $settings): string
    {
        $format = fn (string $date) => $settings->formatDate(Carbon::createFromFormat('Y-m-d', $date, $settings->timezone));

        return match (true) {
            $filters['from'] && $filters['to'] => 'Del '.$format($filters['from']).' al '.$format($filters['to']),
            (bool) $filters['from'] => 'Desde el '.$format($filters['from']),
            (bool) $filters['to'] => 'Hasta el '.$format($filters['to']),
            default => 'Todo el historial',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function appliedFilters(array $filters, User $viewer, Workspace $workspace): array
    {
        $applied = [];

        if ($filters['search'] !== '') {
            $applied[] = "Búsqueda: {$filters['search']}";
        }

        if ($filters['user']) {
            $name = $this->report->users($workspace, $viewer, $filters['workspace'])->firstWhere('value', (string) $filters['user'])['label'] ?? "#{$filters['user']}";
            $applied[] = "Usuario: {$name}";
        }

        if ($filters['resource']) {
            $applied[] = 'Módulo: '.config("audit.resources.{$filters['resource']}.label");
        }

        if ($filters['action']) {
            $applied[] = 'Acción: '.config("audit.actions.{$filters['action']}.label");
        }

        return $applied;
    }

    /** @return array{0: int, 1: int, 2: int} the Workspace brand color as RGB (the default blue when it has none) */
    private function brandColor(?string $hex): array
    {
        $hex = ltrim($hex ?: config('workspace.palette.0'), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
