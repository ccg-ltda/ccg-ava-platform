<?php

namespace App\Http\Controllers;

use App\Audit\AuditPdf;
use App\Audit\AuditPresenter;
use App\Http\Requests\AuditFilterRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditReport;
use App\Services\ListPagination;
use App\Services\SavedFilters;
use App\Services\WorkspaceScope;
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
    public function __construct(
        private readonly AuditReport $report,
        private readonly WorkspaceScope $scope,
        private readonly SavedFilters $saved,
    ) {}

    public function index(AuditFilterRequest $request): InertiaResponse
    {
        $workspace = $request->attributes->get('workspace');
        $viewer = $request->user();
        $filters = $request->filters();
        // Refuses (403) a Workspace parameter from anyone who may not choose a scope, before anything is read.
        $chosen = $this->scope->resolve($viewer, $workspace, $filters['workspace']);
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
                'canChoose' => $this->scope->canChoose($viewer, $workspace),
                'resources' => $this->labels('resources'),
                'actions' => $this->labels('actions'),
                'actors' => [['value' => 'user', 'label' => 'Una persona'], ['value' => 'system', 'label' => 'Automático']],
                'outcomes' => [['value' => 'success', 'label' => 'Correcto'], ['value' => 'failed', 'label' => 'Fallido']],
            ],
            'scope' => $this->scopeProps($chosen),
            'savedFilters' => $this->saved->for($viewer, $workspace, 'audit'),
        ]);
    }

    public function export(AuditFilterRequest $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $viewer = $request->user();
        $filters = $request->filters();
        $chosen = $this->scope->resolve($viewer, $workspace, $filters['workspace']);
        $settings = $workspace->settingsOrDefault();
        $present = new AuditPresenter($settings);
        $limit = (int) config('audit.pdf_max_events');

        $query = $this->report->query($workspace, $viewer, $filters);
        $matching = (clone $query)->count();
        $events = $this->report->ordered($query)->limit($limit)->get()->map(fn ($log) => $present->present($log));

        $now = now();
        $pdf = (new AuditPdf)->render($events, [
            'workspace' => $this->scopeName($chosen),
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

    /**
     * @param  array{mode: string, workspace: ?Workspace}  $chosen
     * @return array{mode: string, workspace: ?array{id: int, name: string, code: string}}
     */
    private function scopeProps(array $chosen): array
    {
        $workspace = $chosen['workspace'];

        return ['mode' => $chosen['mode'], 'workspace' => $workspace ? ['id' => $workspace->id, 'name' => $workspace->name, 'code' => $workspace->code] : null];
    }

    /** @param  array{mode: string, workspace: ?Workspace}  $chosen */
    private function scopeName(array $chosen): string
    {
        return $chosen['workspace'] ? "{$chosen['workspace']->name} ({$chosen['workspace']->code})" : 'Todos los Workspaces';
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

        if ($filters['actor']) {
            $applied[] = 'Origen: '.($filters['actor'] === 'system' ? 'Automático' : 'Una persona');
        }

        if ($filters['outcome']) {
            $applied[] = 'Resultado: '.($filters['outcome'] === 'failed' ? 'Fallido' : 'Correcto');
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
