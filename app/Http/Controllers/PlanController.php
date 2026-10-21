<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlatformAdministration;
use App\Http\Requests\SavePlanModulesRequest;
use App\Http\Requests\SavePlanRequest;
use App\Models\ModuleCategory;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ListPagination;
use App\Services\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planes: the commercial plans of CCG (the modules a Workspace may use). Only superusers inside the administrative
 * Workspace reach it (`workspace.platform`): the page opens with `view-plans` or `view-modules` and shows only the part
 * its permission allows; every operation (create, edit, activate, assign) has its own permission too. Plans are never deleted, only deactivated, and the rules live in ModuleCatalog.
 */
class PlanController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $counts = $this->catalog->workspaceCounts();
        $can = $this->abilities($request);

        return Inertia::render('Plans/Index', [
            'can' => $can,
            'plans' => ! $can['plans'] ? [] : Plan::query()->withCount('modules')->with('solution')
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                    $query->whereRaw("lower(name) like ? escape '!'", [$like]);
                })
                ->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (Plan $plan) => $this->summary($plan, $counts[$plan->id] ?? 0))->all(),
            'catalog' => $this->catalog->grouped(),
            'filters' => ['search' => $search],
            'commercial' => $this->commercialOptions(),
        ]);
    }

    /**
     * What this person may do on the page: which parts open and which operations each part offers. The routes enforce every one of them; this only
     * lets the page offer what will work.
     *
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $allows = fn (string $permission) => EnsurePlatformAdministration::allows($request, $permission);

        return [
            'plans' => $allows('view-plans'),
            'modules' => $allows('view-modules'),
            'createPlan' => $allows('create-plans'),
            'editPlan' => $allows('edit-plans'),
            'activatePlan' => $allows('activate-plans'),
            'assignPlan' => $allows('assign-plans'),
            'createModule' => $allows('create-modules'),
            'editModule' => $allows('edit-modules'),
            'activateModule' => $allows('activate-modules'),
        ];
    }

    public function show(Request $request, Plan $plan): Response
    {
        return Inertia::render('Plans/Show', [
            'can' => $this->abilities($request),
            'plan' => $this->summary($plan->loadMissing('solution')->loadCount('modules'), $this->catalog->workspacesOf($plan)->count())
                + ['moduleIds' => $plan->modules()->pluck('modules.id')->all()],
            'catalog' => $this->catalog->grouped(),
            'commercial' => $this->commercialOptions(),
            'workspaces' => fn () => $this->workspacesPage($request, $plan),
            'perPageOptions' => ListPagination::OPTIONS,
        ]);
    }

    public function store(SavePlanRequest $request): RedirectResponse
    {
        $plan = $this->catalog->createPlan($request->safe()->only(['name', 'description']), $request->moduleIds(), $request->commercial());

        return redirect()->route('plans.show', $plan)->with('success', 'Plan creado correctamente');
    }

    /** Name and description, and the modules too when the editor sends them (it always does): the plan is saved exactly as the form shows it. */
    public function update(SavePlanRequest $request, Plan $plan): RedirectResponse
    {
        DB::transaction(function () use ($request, $plan) {
            $this->catalog->updatePlan($plan, $request->safe()->only(['name', 'description']), $request->commercial());

            if ($request->has('module_ids')) {
                $this->catalog->syncModules($plan, $request->moduleIds());
            }
        });

        return back()->with('success', 'Plan actualizado correctamente');
    }

    public function updateModules(SavePlanModulesRequest $request, Plan $plan): RedirectResponse
    {
        $this->catalog->syncModules($plan, $request->moduleIds());

        return back()->with('success', 'Módulos del plan actualizados correctamente');
    }

    public function activate(Plan $plan): RedirectResponse
    {
        $this->catalog->setPlanActive($plan, true);

        return back()->with('success', 'Plan activado correctamente');
    }

    public function deactivate(Plan $plan): RedirectResponse
    {
        $this->catalog->setPlanActive($plan, false);

        return back()->with('success', 'Plan desactivado correctamente');
    }

    /** @return array<string, mixed> */
    private function summary(Plan $plan, int $workspacesCount): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'isActive' => $plan->is_active,
            'isDefault' => $plan->isDefault(),
            'moduleIds' => $plan->modules()->pluck('modules.id')->all(),
            'modulesCount' => $plan->modules_count,
            'workspacesCount' => $workspacesCount,
            // The commercial offer, exactly as saved (price in major units): what the public sign-up reads.
            'offer' => [
                'solutionId' => $plan->module_category_id, 'price' => $plan->price_cents !== null ? $plan->price_cents / 100 : null, 'currency' => $plan->currency,
                'period' => $plan->billing_period, 'isPublic' => $plan->is_public, 'requiresQuote' => $plan->requires_quote,
                // private | online | quote | incomplete (+ why): the same rule the public sign-up applies.
                'state' => $plan->offerState()['state'], 'issues' => $plan->offerState()['issues'],
            ],
            // Modules of the plan that are NOT operative because a module they need is not in the plan.
            'unmet' => $this->catalog->unmetDependencies($plan->modules()->pluck('modules.key')->all()),
        ];
    }

    /** @return array<string, mixed> the choices of the commercial form: solutions (module categories), currencies and periods */
    private function commercialOptions(): array
    {
        return [
            // Only categories that are commercial solutions: the groups of shared capabilities are not offered as a solution.
            'solutions' => ModuleCategory::where('is_active', true)->orderBy('sort_order')->get()->filter(fn (ModuleCategory $c) => $c->isSolution())->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            'currencies' => collect(config('workspace.currencies'))->map(fn (string $name, string $code) => ['value' => $code, 'label' => "{$code} · {$name}"])->values()->all(),
            'periods' => collect(Plan::PERIODS)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values()->all(),
        ];
    }

    /** The Workspaces that use the plan (paginated on the server: there can be many). */
    private function workspacesPage(Request $request, Plan $plan): array
    {
        $page = ListPagination::paginate($this->catalog->workspacesOf($plan)->orderBy('workspaces.name'), $request, 'workspaces');

        return [
            'data' => $page->getCollection()->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'code' => $workspace->code,
                'name' => $workspace->name,
                'organization' => $workspace->organization->name,
                'isActive' => $workspace->is_active,
                'explicit' => $workspace->plan_id !== null,
            ])->values()->all(),
            'meta' => ListPagination::meta($page),
        ];
    }
}
