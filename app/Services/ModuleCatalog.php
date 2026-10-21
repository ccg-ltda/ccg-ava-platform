<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Plan;
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
    public function createPlan(array $data, array $moduleIds = [], array $commercial = []): Plan
    {
        return DB::transaction(function () use ($data, $moduleIds, $commercial) {
            $plan = Plan::create(['name' => $data['name'], 'description' => $data['description'] ?? null] + $commercial);
            $this->syncModules($plan, $moduleIds);

            return $plan;
        });
    }

    /** @param  array{name: string, description?: ?string}  $data */
    public function updatePlan(Plan $plan, array $data, array $commercial = []): Plan
    {
        $plan->update(['name' => $data['name'], 'description' => $data['description'] ?? null] + $commercial);

        return $plan;
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

        return collect($keys)->map(fn (string $key) => ['key' => $key, 'name' => (string) ($names[$key] ?? $key), 'missing' => $this->access->missingFor($key, $keys)])
            ->filter(fn (array $unmet) => $unmet['missing'] !== [])->values()->all();
    }

    /** @param  list<string>  $keys */
    private function namesOf(array $keys): string
    {
        $names = Module::whereIn('key', $keys)->pluck('name', 'key');

        return collect($keys)->map(fn (string $key) => '«'.($names[$key] ?? $key).'»')->implode(', ');
    }

    // --- exceptions of one Workspace over its plan -----------------------------------------------------------------

    /**
     * Every module of the catalog as ONE Workspace sees it: whether its plan includes it, the exception it carries (grant / revoke) and whether it is
     * effectively available now. Reading never changes anything.
     *
     * @return array{plan: ?array{id: int, name: string}, subscription: ?array{status: string, paymentStatus: string}, blocked: ?string, modules: list<array<string, mixed>>}
     */
    public function workspaceModules(Workspace $workspace): array
    {
        $plan = $workspace->plan_id ? $workspace->plan : $this->access->defaultPlan();
        $inPlan = $plan ? $plan->modules()->pluck('modules.key')->all() : [];
        $overrides = WorkspaceModuleOverride::where('workspace_id', $workspace->id)->pluck('mode', 'module_id');
        $effective = $this->access->keys($workspace);
        $subscription = $this->access->subscription($workspace);

        return [
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name] : null,
            'subscription' => $subscription ? ['status' => $subscription->status, 'paymentStatus' => $subscription->payment_status] : null,
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
                'requires' => collect($this->access->dependencies()[$module->key] ?? [])->values()->all(),
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
        $planIds = $plan ? $plan->modules()->pluck('modules.id')->all() : [];
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

    /** Assigns a plan to a client Workspace. The administrative Workspace takes no commercial restrictions. */
    public function assignPlan(Workspace $workspace, Plan $plan): void
    {
        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo de Ava Platform no tiene plan: siempre dispone de todos los módulos.']);
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

        return ModuleCategory::with(['modules' => fn ($q) => $q->withCount('plans')->orderBy('name')])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ModuleCategory $category) => [
                'id' => $category->id,
                'key' => $category->key,
                'name' => $category->name,
                'description' => $category->description,
                'isActive' => $category->is_active,
                'modules' => $category->modules->map(fn (Module $module) => [
                    'id' => $module->id,
                    'key' => $module->key,
                    'name' => $module->name,
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
                        ->map(fn (string $key) => ['key' => $key, 'name' => $names[$key] ?? $key])->values()->all(),
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
