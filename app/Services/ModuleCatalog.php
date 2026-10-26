<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceModuleOverride;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The commercial catalog (module categories, modules and plans) and the rules that protect it. Everything the Planes
 * module changes goes through here: stable keys are generated once and never edited, nothing is deleted, and a module or
 * plan that Workspaces depend on cannot be switched off by accident. Whether a module WORKS is never stored here
 * (see ModuleAccess); registering one only reserves its place.
 */
class ModuleCatalog
{
    public function __construct(private readonly ModuleAccess $access, private readonly AuditLogger $audit) {}

    /** Creates the categories and modules declared in config/modules.php that are missing. Never overwrites an edited name. */
    public function sync(): void
    {
        foreach (config('modules.categories') as $key => $category) {
            ModuleCategory::firstOrCreate(['key' => $key], ['name' => $category['name'], 'description' => $category['description'], 'sort_order' => $category['sort']]);
        }

        foreach (config('modules.implemented') as $key => $module) {
            Module::firstOrCreate(['key' => $key], [
                'module_category_id' => ModuleCategory::where('key', $module['category'])->value('id'),
                'name' => $module['name'],
                'description' => $module['description'],
            ]);
        }
    }

    /** The system plan with every implemented module. Created once; after that an administrator owns its contents. */
    public function ensureDefaultPlan(): Plan
    {
        $plan = Plan::firstOrCreate(['key' => config('modules.default_plan')], [
            'name' => config('modules.default_plan_name'),
            'description' => config('modules.default_plan_description'),
        ]);

        if ($plan->wasRecentlyCreated) {
            // Only the shared modules: the rest of the catalog is added to a plan on purpose, never inherited by every Workspace.
            $shared = collect(config('modules.implemented'))->filter(fn (array $module) => $module['category'] === 'shared')->keys();
            $plan->modules()->sync(Module::whereIn('key', $shared)->pluck('id'));
        }

        return $plan;
    }

    // --- categories ------------------------------------------------------------------------------------------

    /** @param  array{name: string, description?: ?string}  $data */
    public function createCategory(array $data): ModuleCategory
    {
        return ModuleCategory::create([
            'key' => $this->uniqueKey($data['name'], fn (string $key) => ModuleCategory::where('key', $key)->exists()),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ModuleCategory::max('sort_order') + 10,
        ]);
    }

    /** @param  array{name: string, description?: ?string}  $data */
    public function updateCategory(ModuleCategory $category, array $data): ModuleCategory
    {
        $category->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);

        return $category;
    }

    /** A category only groups modules, so switching it off changes nothing for Workspaces: it just stops being offered for new modules. */
    public function setCategoryActive(ModuleCategory $category, bool $active): void
    {
        $category->update(['is_active' => $active]);
    }

    /**
     * Shows or hides a solution in the PUBLIC offer. Hiding it deletes nothing and changes nothing for Workspaces, subscriptions or historical plans: it only
     * stops being offered to new customers. A solution that is not active can not be made public. Audited by the model.
     */
    public function setCategoryPublic(ModuleCategory $category, bool $public): void
    {
        if (! ModuleCategory::visibilityReady()) {
            throw ValidationException::withMessages(['category' => 'La visibilidad pública todavía no está disponible: falta aplicar la migración pendiente.']);
        }

        if ($public && ! $category->is_active) {
            throw ValidationException::withMessages(['category' => 'Activa la solución antes de hacerla pública.']);
        }

        if (! $category->isSolution()) {
            throw ValidationException::withMessages(['category' => 'Esta categoría agrupa capacidades compartidas: no es una solución comercial y no se ofrece públicamente.']);
        }

        $category->update(['is_public' => $public]);
    }

    // --- modules ---------------------------------------------------------------------------------------------

    /** @param  array{name: string, description?: ?string, module_category_id: int}  $data */
    public function createModule(array $data): Module
    {
        return Module::create([
            'key' => $this->uniqueKey($data['name'], fn (string $key) => Module::where('key', $key)->exists() || $this->access->isImplemented($key)),
            'module_category_id' => $data['module_category_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /** @param  array{name: string, description?: ?string, module_category_id: int}  $data */
    public function updateModule(Module $module, array $data): Module
    {
        $module->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'module_category_id' => $data['module_category_id'],
        ]);

        return $module;
    }

    /** Switching a module off removes it from every Workspace whose plan includes it, so it is refused while any depends on it. */
    public function setModuleActive(Module $module, bool $active): void
    {
        if (! $active) {
            $affected = $this->workspacesUsing($module)->count();

            if ($affected > 0) {
                throw ValidationException::withMessages(['status' => "No se puede desactivar «{$module->name}»: lo usan {$affected} Workspace(s) por su plan. Quítalo de esos planes o reasígnalos primero."]);
            }
        }

        $module->update(['is_active' => $active]);
    }

    // --- plans -----------------------------------------------------------------------------------------------

    /**
     * @param  array{name: string, description?: ?string}  $data
     * @param  list<int>  $moduleIds
     */
    public function createPlan(array $data, array $moduleIds = [], array $commercial = [], ?array $solutionIds = null): Plan
    {
        return DB::transaction(function () use ($data, $moduleIds, $commercial, $solutionIds) {
            $plan = Plan::create(['name' => $data['name'], 'description' => $data['description'] ?? null] + $commercial);
            $this->syncModules($plan, $moduleIds);

            if ($solutionIds !== null) {
                $this->syncSolutions($plan, $solutionIds);
            }

            return $plan;
        });
    }

    /** @param  array{name: string, description?: ?string}  $data */
    public function updatePlan(Plan $plan, array $data, array $commercial = [], ?array $solutionIds = null): Plan
    {
        $plan->update(['name' => $data['name'], 'description' => $data['description'] ?? null] + $commercial);

        if ($solutionIds !== null) {
            $this->syncSolutions($plan, $solutionIds);
        }

        return $plan;
    }

    /**
     * Replaces the solutions a plan is offered under (empty = a custom plan). It only changes the COMMERCIAL offer: no subscription, Workspace, module or
     * override is touched, so removing a solution from a plan never changes what a contracted Workspace can use (its terms are frozen and its modules come from
     * its plan and subscription). Audited as one event with the names before and after. The first solution is also kept in `plans.module_category_id` (the
     * primary solution) so the previous release can read the plan after a rollback.
     *
     * @param  list<int>  $solutionIds
     */
    public function syncSolutions(Plan $plan, array $solutionIds): void
    {
        $solutionIds = array_values(array_unique(array_map('intval', $solutionIds)));
        $before = $plan->solutions()->pluck('module_categories.id')->map(fn ($id) => (int) $id)->all();

        if ($before === [] && $solutionIds === []) {
            return;
        }

        $invalid = ModuleCategory::whereIn('id', array_diff($solutionIds, $before))->get()->filter(fn (ModuleCategory $category) => ! $category->is_active || ! $category->isSolution());

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages(['solution_ids' => 'Solo se pueden añadir soluciones comerciales activas: '.$invalid->pluck('name')->implode(', ').'.']);
        }

        $plan->solutions()->sync($solutionIds);
        $plan->unsetRelation('solutions');
        $primary = $solutionIds === [] ? null : (in_array($plan->module_category_id, $solutionIds, true) ? $plan->module_category_id : $plan->solutions()->orderBy('module_category_plan.id')->value('module_categories.id'));
        $plan->forceFill(['module_category_id' => $primary])->saveQuietly();

        if (collect($before)->sort()->values()->all() !== collect($solutionIds)->sort()->values()->all()) {
            $names = fn (array $ids) => ModuleCategory::whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') ?: null;
            $this->audit->record('updated', 'plan', $plan->id, $plan->name, [AuditLogger::change('Soluciones', $names($before), $names($solutionIds))]);
        }
    }

    /** A plan with Workspaces cannot be switched off (they would lose every module), and neither can the default plan. */
    public function setPlanActive(Plan $plan, bool $active): void
    {
        if (! $active) {
            if ($plan->isDefault()) {
                throw ValidationException::withMessages(['status' => 'El plan por defecto no se puede desactivar: es el que usan los Workspaces sin plan asignado.']);
            }

            $assigned = $this->workspacesOf($plan)->count();

            if ($assigned > 0) {
                throw ValidationException::withMessages(['status' => "No se puede desactivar «{$plan->name}»: tiene {$assigned} Workspace(s) asignados. Reasígnalos a otro plan primero."]);
            }
        }

        $plan->update(['is_active' => $active]);
    }

    /**
     * Replaces the modules of a plan. A module that is switched off can stay where it already was but cannot be added.
     * The change is audited as one event listing the modules before and after. A plan may have ANY number of modules, none included, and may leave a
     * module without what it needs (decision E4): that is only warned about (`unmetDependencies`, shown in Planes) and ModuleAccess keeps the module
     * inoperative, but it never blocks saving. Assigning such a plan to a Workspace is still refused (see assignPlan).
     *
     * @param  list<int>  $moduleIds
     */
    public function syncModules(Plan $plan, array $moduleIds): void
    {
        $moduleIds = array_values(array_unique($moduleIds));
        $current = $plan->modules()->pluck('modules.id')->all();

        $inactive = Module::whereIn('id', array_diff($moduleIds, $current))->where('is_active', false)->pluck('name');

        if ($inactive->isNotEmpty()) {
            throw ValidationException::withMessages(['module_ids' => 'No se pueden añadir módulos desactivados: '.$inactive->implode(', ').'.']);
        }

        $before = $this->names($current);
        $plan->modules()->sync($moduleIds);
        $after = $this->names($moduleIds);

        if ($before !== $after) {
            $this->audit->record('updated', 'plan', $plan->id, $plan->name, [AuditLogger::change('Módulos', $before ?: null, $after ?: null)]);
        }
    }

    /**
     * Modules of a set that lack a dependency, with what is missing.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, name: string, missing: list<string>}>
     */
    public function unmetDependencies(array $keys): array
    {
        $names = Module::whereIn('key', $keys)->pluck('name', 'key');

        return collect($keys)->map(fn (string $key) => ['key' => $key, 'name' => $this->labelOf($key, (string) ($names[$key] ?? $key)), 'missing' => $this->access->missingFor($key, $keys)])
            ->filter(fn (array $unmet) => $unmet['missing'] !== [])->values()->all();
    }

    /** @param  list<string>  $keys */
    private function namesOf(array $keys): string
    {
        $names = Module::whereIn('key', $keys)->pluck('name', 'key');

        return collect($keys)->map(fn (string $key) => '«'.$this->labelOf($key, (string) ($names[$key] ?? $key)).'»')->implode(', ');
    }

    /** The name a module goes by when another one is described (config `labels`: the sidebar's), or its catalog name when it has no page. */
    public function labelOf(string $key, string $fallback): string
    {
        return (string) config("modules.labels.{$key}", $fallback);
    }

    // --- exceptions of one Workspace over its plan -----------------------------------------------------------------

    /**
     * Every module of the catalog as ONE Workspace sees it: whether its plan includes it, the exception it carries (grant / revoke) and whether it is
     * effectively available now. Reading never changes anything.
     *
     * @return array{plan: ?array{id: int, name: string, solution: ?string, explicit: bool}, subscription: ?array<string, ?string>, isActive: bool, effectiveCount: int, blocked: ?string, modules: list<array<string, mixed>>}
     */
    public function workspaceModules(Workspace $workspace): array
    {
        $plan = $workspace->plan_id ? $workspace->plan : $this->access->defaultPlan();
        $inPlan = $this->access->baseKeys($workspace, $plan, false); // what its contract names: the frozen conditions, or the plan for a historical one
        $overrides = WorkspaceModuleOverride::where('workspace_id', $workspace->id)->pluck('mode', 'module_id');
        $effective = $this->access->keys($workspace);
        $subscription = $this->access->subscriptionRow($workspace); // as it is, a scheduled trial included (the administration must see it)

        return [
            'plan' => $plan ? [
                'id' => $plan->id, 'name' => $plan->name, 'solution' => Plan::commercialReady() ? $plan->solutionList()->pluck('name')->implode(', ') ?: null : null, 'explicit' => $workspace->plan_id !== null,
                // What the plan offers TODAY (only for new contracts); the contract of this Workspace is `subscription.terms`.
                'offer' => Plan::commercialReady() && ($plan->price_monthly_cents || $plan->price_yearly_cents) ? [
                    'currency' => $plan->currency, 'priceMonthly' => $plan->price_monthly_cents !== null ? $plan->price_monthly_cents / 100 : null, 'priceYearly' => $plan->price_yearly_cents !== null ? $plan->price_yearly_cents / 100 : null,
                ] : null,
            ] : null,
            // Dates exist only when the subscription has them: nothing is shown for a trial or an expiry the model does not hold.
            'subscription' => $subscription ? [
                'status' => $subscription->status, 'paymentStatus' => $subscription->payment_status,
                'activatedAt' => $subscription->activated_at?->toIso8601String(), 'graceEndsAt' => $subscription->grace_ends_at?->toIso8601String(), 'endsAt' => $subscription->ends_at?->toIso8601String(),
                // The conditions frozen when it contracted (null = historical and incomplete) and the trial, only when the database has those columns.
                'termsReady' => Subscription::termsReady(),
                'terms' => Subscription::termsReady() ? $subscription->terms : null,
                'termsSource' => Subscription::termsReady() ? $subscription->terms_source : null,
                'termsCapturedAt' => Subscription::termsReady() ? $subscription->terms_captured_at?->toIso8601String() : null,
                'trial' => Subscription::termsReady() && $subscription->trial_ends_at ? [
                    'startsAt' => $subscription->trial_starts_at?->toIso8601String(), 'endsAt' => $subscription->trial_ends_at->toIso8601String(), 'reason' => $subscription->trial_reason,
                    'by' => $subscription->trial_granted_by ? User::whereKey($subscription->trial_granted_by)->value('name') : null,
                    'active' => $subscription->status === Subscription::TRIALING, 'scheduled' => $subscription->status === Subscription::SCHEDULED, 'expired' => $subscription->trialExpired(),
                ] : null,
            ] : null,
            'isActive' => $workspace->is_active,
            'effectiveCount' => count($effective),
            'blocked' => match (true) {
                ! $plan || ! $plan->is_active => 'plan',
                ! $this->access->subscriptionAllows($workspace) => 'subscription',
                default => null,
            },
            'modules' => Module::with('category:id,name')->orderBy('name')->get()->map(fn (Module $module) => [
                'id' => $module->id,
                'key' => $module->key,
                'name' => $module->name,
                'category' => $module->category?->name,
                'isActive' => $module->is_active,
                'implemented' => $module->isImplemented(),
                'inPlan' => in_array($module->key, $inPlan, true),
                'override' => $overrides[$module->id] ?? null,
                'effective' => in_array($module->key, $effective, true),
                'requires' => collect($this->access->dependencies()[$module->key] ?? [])->map(fn (string $key) => $this->labelOf($key, $key))->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Replaces the exceptions of ONE client Workspace. `$granted` are modules the plan lacks, `$revoked` modules the plan includes; anything that would
     * change nothing (already in the plan / already outside it) is dropped. Exceptions that stay keep their reason and author. The plan and every
     * other Workspace are untouched. Audited as one event with the modules granted and revoked before and after.
     *
     * @param  list<int>  $granted
     * @param  list<int>  $revoked
     */
    public function setWorkspaceOverrides(Workspace $workspace, array $granted, array $revoked, ?string $reason, ?int $userId): void
    {
        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo siempre dispone de todos los módulos: no admite excepciones.']);
        }

        $plan = $workspace->plan_id ? $workspace->plan : $this->access->defaultPlan();
        $planIds = Module::whereIn('key', $this->access->baseKeys($workspace, $plan, false))->pluck('id')->all();
        $current = WorkspaceModuleOverride::where('workspace_id', $workspace->id)->get()->keyBy('module_id');

        $inactive = Module::whereIn('id', $granted)->where('is_active', false)->whereNotIn('id', $current->where('mode', WorkspaceModuleOverride::GRANT)->keys())->pluck('name');

        if ($inactive->isNotEmpty()) {
            throw ValidationException::withMessages(['granted' => 'No se pueden conceder módulos desactivados: '.$inactive->implode(', ').'.']);
        }

        if (array_intersect($granted, $revoked) !== []) {
            throw ValidationException::withMessages(['granted' => 'Un módulo no puede concederse y retirarse a la vez.']);
        }

        $wanted = collect($granted)->reject(fn (int $id) => in_array($id, $planIds, true))->mapWithKeys(fn (int $id) => [$id => WorkspaceModuleOverride::GRANT])->all()
            + collect($revoked)->filter(fn (int $id) => in_array($id, $planIds, true))->mapWithKeys(fn (int $id) => [$id => WorkspaceModuleOverride::REVOKE])->all();
        $wanted = collect($wanted);

        $before = $this->overrideSummary($current->map->mode->all());

        DB::transaction(function () use ($workspace, $current, $wanted, $reason, $userId) {
            WorkspaceModuleOverride::where('workspace_id', $workspace->id)->whereNotIn('module_id', $wanted->keys())->delete();

            foreach ($wanted as $moduleId => $mode) {
                if (($current[$moduleId]->mode ?? null) !== $mode) {
                    WorkspaceModuleOverride::updateOrCreate(['workspace_id' => $workspace->id, 'module_id' => $moduleId], ['mode' => $mode, 'reason' => $reason, 'created_by' => $userId]);
                }
            }
        });

        $after = $this->overrideSummary($wanted->all());

        if ($before !== $after) {
            $this->audit->record('updated', 'workspace_modules', $workspace->id, $workspace->auditLabel(), [AuditLogger::change('Excepciones de módulos', $before ?: null, $after ?: null)]
                + ($reason ? [1 => AuditLogger::change('Motivo', null, $reason)] : []), $workspace);
        }
    }

    /** @param  array<int, string>  $overrides  module id => mode */
    private function overrideSummary(array $overrides): string
    {
        $names = Module::whereIn('id', array_keys($overrides))->pluck('name', 'id');

        return collect($overrides)->map(fn (string $mode, int $id) => ($mode === WorkspaceModuleOverride::GRANT ? '+ ' : '− ').($names[$id] ?? $id))->sort()->implode(', ');
    }

    /**
     * The commercial solution (module category id) a client Workspace belongs to, or null when it has none identifiable: its explicit plan decides, and a
     * trial scheduled for later reserves the solution of ITS plan. A plan without a solution (the default plan, a custom one) fixes nothing.
     */
    public function solutionOf(Workspace $workspace): ?int
    {
        if (! Plan::solutionsReady()) {
            $legacy = $workspace->plan_id ? Plan::whereKey($workspace->plan_id)->value('module_category_id') : null;

            return $legacy !== null ? (int) $legacy : null;
        }

        $plan = $workspace->plan_id ? Plan::with('solutions')->find($workspace->plan_id) : null;

        if ($plan !== null && $plan->solutions->isNotEmpty()) {
            return $this->contractedSolution($workspace, $plan);
        }

        if (! Subscription::termsReady()) {
            return null;
        }

        $scheduled = Subscription::where('workspace_id', $workspace->id)->where('status', Subscription::SCHEDULED)->first();
        $reserved = $scheduled ? Plan::with('solutions')->find($scheduled->plan_id) : null;

        return $reserved !== null && $reserved->solutions->isNotEmpty() ? $this->contractedSolution($workspace, $reserved, $scheduled) : null;
    }

    /**
     * The solution of a plan with solutions for ONE Workspace: the one frozen in its contract (the customer chose it), or the only one the plan has. A plan
     * offered under several solutions and a Workspace whose contract does not say which one it chose fix nothing (null): it is never guessed.
     */
    private function contractedSolution(Workspace $workspace, Plan $plan, ?Subscription $subscription = null): ?int
    {
        $ids = $plan->solutionIds();
        $subscription ??= Subscription::termsReady() ? Subscription::where('workspace_id', $workspace->id)->latest('id')->first() : null;
        $frozen = $subscription?->terms['solution']['id'] ?? null;

        if ($frozen !== null && in_array((int) $frozen, $ids, true)) {
            return (int) $frozen;
        }

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * One commercial solution per Workspace: a Workspace that already has one only takes plans of that same solution (changing plan inside it is fine).
     * Historical or ambiguous Workspaces (no identifiable solution) are never reassigned by this check.
     */
    public function assertSameSolution(Workspace $workspace, Plan $plan): void
    {
        $current = $this->solutionOf($workspace);

        if ($current !== null && ! in_array($current, $plan->solutionIds(), true)) {
            $name = ModuleCategory::whereKey($current)->value('name');

            throw ValidationException::withMessages(['plan_id' => "Este Workspace ya pertenece a la solución «{$name}»: solo puede recibir planes de esa solución."]);
        }
    }

    /** Every reason a plan can NOT be assigned to this Workspace (inactive, incomplete, another solution); throws the first one. Changes nothing. */
    public function assertAssignable(Workspace $workspace, Plan $plan): void
    {
        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo de la plataforma no tiene plan: siempre dispone de todos los módulos.']);
        }

        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan_id' => 'No se puede asignar un plan desactivado.']);
        }

        // A plan saved before a dependency was declared can be incomplete: it is never applied to a Workspace like that, because the
        // Workspace would be offered a module that does not work (ModuleAccess drops it silently). The server says which ones are missing.
        $unmet = $this->unmetDependencies($plan->modules()->pluck('modules.key')->all());

        if ($unmet !== []) {
            $detail = collect($unmet)->map(fn (array $item) => "«{$item['name']}» necesita ".$this->namesOf($item['missing']))->implode('; ');
            throw ValidationException::withMessages(['plan_id' => "El plan «{$plan->name}» está incompleto y no se puede asignar: {$detail}. Complétalo en Planes."]);
        }

        $this->assertSameSolution($workspace, $plan);
    }

    /** Assigns a plan to a client Workspace. The administrative Workspace takes no commercial restrictions. */
    public function assignPlan(Workspace $workspace, Plan $plan): void
    {
        $this->assertAssignable($workspace, $plan);
        $workspace->update(['plan_id' => $plan->id]);
    }

    /**
     * Gives the default plan explicitly to the client Workspaces that have none. Their access does not change (an empty plan
     * already means the default plan); it only makes the assignment visible and manageable. Re-runnable, never touches a
     * Workspace that has a plan or the administrative one. Returns how many were updated.
     */
    public function assignDefaultPlanToUnassigned(): int
    {
        $default = $this->access->defaultPlan();

        return $default ? $this->clients()->whereNull('plan_id')->update(['plan_id' => $default->id]) : 0;
    }

    /** Client Workspaces whose plan (explicit, or the default one when empty) includes the module. */
    public function workspacesUsing(Module $module): Builder
    {
        $default = $this->access->defaultPlan();
        $planIds = $module->plans()->pluck('plans.id');

        return $this->clients()->where(fn (Builder $q) => $q
            ->whereIn('plan_id', $planIds)
            ->when($default && $planIds->contains($default->id), fn (Builder $q) => $q->orWhereNull('plan_id')));
    }

    /** Client Workspaces that use the plan: the assigned ones, plus those without a plan when it is the default. */
    public function workspacesOf(Plan $plan): Builder
    {
        return $this->clients()->where(fn (Builder $q) => $q
            ->where('plan_id', $plan->id)
            ->when($plan->isDefault(), fn (Builder $q) => $q->orWhereNull('plan_id')));
    }

    /**
     * The catalog as the Planes screens show it: categories in order, each with its modules, whether each one is
     * implemented (config/modules.php, never the table) and in how many plans it is used.
     *
     * @return list<array<string, mixed>>
     */
    public function grouped(): array
    {
        $names = Module::pluck('name', 'key');
        $categoryNames = ModuleCategory::pluck('name', 'key');

        $plans = Plan::commercialReady() && Plan::solutionsReady() ? DB::table('module_category_plan')->selectRaw('module_category_id, count(*) as total')->groupBy('module_category_id')->pluck('total', 'module_category_id') : collect();

        return ModuleCategory::with(['modules' => fn ($q) => $q->withCount('plans')->orderBy('name')])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ModuleCategory $category) => [
                'id' => $category->id,
                'key' => $category->key,
                'name' => $category->name,
                'description' => $category->description,
                'isActive' => $category->is_active,
                'isSolution' => $category->isSolution(),
                'isPublic' => ModuleCategory::visibilityReady() ? (bool) $category->is_public : null,
                'plansCount' => (int) ($plans[$category->id] ?? 0),
                'modules' => $category->modules->map(fn (Module $module) => [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
                    'label' => $this->labelOf($module->key, $module->name),
                    'description' => $module->description,
                    'categoryId' => $module->module_category_id,
                    'group' => $this->groupMeta($this->access->groupOf($module->key, $category->key)),
                    // Where the module sits in the application menu (General, Operación, Administración): how the catalog and the plan builder organize it.
                    'section' => $this->sectionMeta($module->key),
                    'hint' => config("modules.plan_hints.{$module->key}"),
                    'isActive' => $module->is_active,
                    'implemented' => $module->isImplemented(),
                    // Other solutions that reuse this very module (config `also_for`): a label only, it enables nothing.
                    'alsoFor' => collect(config("modules.implemented.{$module->key}.also_for", []))
                        ->filter(fn (string $key) => $key !== $category->key && isset($categoryNames[$key]))
                        ->map(fn (string $key) => ['key' => $key, 'name' => $categoryNames[$key]])->values()->all(),
                    'route' => config("modules.implemented.{$module->key}.route"),
                    'plansCount' => $module->plans_count,
                    'requires' => collect($this->access->dependencies()[$module->key] ?? [])
                        ->map(fn (string $key) => ['key' => $key, 'name' => $this->labelOf($key, (string) ($names[$key] ?? $key))])->values()->all(),
                ])->all(),
            ])->all();
    }

    /** @return array{key: string, name: string, description: ?string, sort: int} the menu section of a module (config `menu`; a module without a page takes `default_menu_section`) */
    public function sectionMeta(string $moduleKey): array
    {
        $key = config("modules.menu.{$moduleKey}") ?? config('modules.default_menu_section');
        $key = config("modules.menu_sections.{$key}") ? $key : config('modules.default_menu_section');

        return ['key' => $key, 'name' => config("modules.menu_sections.{$key}.name"), 'description' => config("modules.menu_sections.{$key}.description"), 'sort' => (int) config("modules.menu_sections.{$key}.sort")];
    }

    /** @return array{key: string, name: string, sort: int} a conceptual group of config/modules.php, for the catalog screens */
    private function groupMeta(string $key): array
    {
        return ['key' => $key, 'name' => config("modules.groups.{$key}.name", $key), 'sort' => (int) config("modules.groups.{$key}.sort", 99)];
    }

    /** @return array<int, int> plan id => client Workspaces using it (those without a plan count for the default one) */
    public function workspaceCounts(): array
    {
        $counts = $this->clients()->selectRaw('plan_id, count(*) as total')->groupBy('plan_id')->pluck('total', 'plan_id')->all();

        if ($default = $this->access->defaultPlan()) {
            $counts[$default->id] = ($counts[$default->id] ?? 0) + ($counts[''] ?? 0);
        }

        unset($counts['']);

        return array_map('intval', $counts);
    }

    /** Every Workspace except the administrative one, which has no commercial plan. */
    private function clients(): Builder
    {
        return Workspace::query()->where('workspaces.code', '!=', Workspace::normalizeCode((string) config('workspace.admin_code')));
    }

    /** @param  list<int>  $ids */
    private function names(array $ids): string
    {
        return Module::whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ');
    }

    /** Stable technical key from a name ("Reservas de citas" => "reservas_de_citas"), unique among its siblings. */
    private function uniqueKey(string $name, callable $exists): string
    {
        $base = Str::slug($name, '_') ?: 'item';
        $key = $base;

        for ($i = 2; $exists($key); $i++) {
            $key = "{$base}_{$i}";
        }

        return $key;
    }
}
