<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Which modules a Workspace may use: the ones of its plan that are registered, active AND implemented
 * (config/modules.php). It is the only place that decides module availability; the `workspace.module` middleware, the
 * navigation, the agent API, the widget, the Meta webhook and the background jobs all ask it. It never grants a
 * permission: roles still decide what each user may do inside an available module.
 *
 * A module with technical dependencies (config/modules.php `dependencies`) is only available when the modules it needs are
 * too, so a plan that includes Asistentes without Conversaciones never presents Asistentes as operative.
 *
 * The administrative Workspace of Ava Platform always has every implemented module (it must be able to administer the
 * platform). A Workspace without a plan uses the default plan; if there is none or it is inactive, it has no modules
 * (fail closed).
 */
class ModuleAccess
{
    private const ATTRIBUTE = 'workspace_modules';

    /** What a chatbot needs to carry conversations over a channel: the assistant itself and the inbox that stores them. */
    public const CHANNEL_MODULES = ['assistants', 'conversations'];

    /** @return list<string> */
    public function implemented(): array
    {
        return array_keys(config('modules.implemented'));
    }

    public function isImplemented(string $key): bool
    {
        return in_array($key, $this->implemented(), true);
    }

    /** @return array<string, list<string>> module key => keys of the modules it needs */
    public function dependencies(): array
    {
        return config('modules.dependencies', []);
    }

    /**
     * The modules that `$key` needs and `$available` lacks.
     *
     * @param  list<string>  $available
     * @return list<string>
     */
    public function missingFor(string $key, array $available): array
    {
        return array_values(array_diff($this->dependencies()[$key] ?? [], $available));
    }

    /**
     * The conceptual group of a module (config/modules.php `groups`): the one its code declares, otherwise the one of its category,
     * otherwise `default_group`. It only organizes the catalog and the menu; it grants and requires nothing.
     */
    public function groupOf(string $moduleKey, ?string $categoryKey): string
    {
        return config("modules.implemented.{$moduleKey}.group")
            ?? config("modules.categories.{$categoryKey}.group")
            ?? config('modules.default_group');
    }

    public function defaultPlan(): ?Plan
    {
        return Plan::where('key', config('modules.default_plan'))->first();
    }

    /** @return list<string> stable keys of the modules the Workspace can use right now */
    public function keys(Workspace $workspace): array
    {
        if ($workspace->isAdministrative()) {
            return $this->implemented();
        }

        $plan = $workspace->plan_id ? $workspace->plan : $this->defaultPlan();

        if (! $plan || ! $plan->is_active) {
            return [];
        }

        return $this->operative($plan->modules()->where('modules.is_active', true)->pluck('modules.key')
            ->filter(fn (string $key) => $this->isImplemented($key))->values()->all());
    }

    /**
     * Drops the modules whose dependencies are not available, repeatedly (a dropped module can leave another without its own).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function operative(array $keys): array
    {
        do {
            $kept = array_values(array_filter($keys, fn (string $key) => $this->missingFor($key, $keys) === []));
            $changed = count($kept) !== count($keys);
            $keys = $kept;
        } while ($changed);

        return $keys;
    }

    public function allows(Workspace $workspace, string $module): bool
    {
        return in_array($module, $this->keys($workspace), true);
    }

    /** @param  list<string>  $modules */
    public function allowsAll(Workspace $workspace, array $modules): bool
    {
        return array_diff($modules, $this->keys($workspace)) === [];
    }

    /**
     * The modules that decide what a page may show about `$target` (null = "all Workspaces", which only a platform
     * administrator may ask for: the active Workspace is the administrative one and has every module).
     *
     * @return list<string>
     */
    public function forScope(?Workspace $target, Workspace $active): array
    {
        return $this->keys($target ?? $active);
    }

    /** @return list<string> the modules of the request's Workspace, computed once per request */
    public function forRequest(Request $request): array
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $workspace = $request->attributes->get('workspace');
            $request->attributes->set(self::ATTRIBUTE, $workspace ? $this->keys($workspace) : []);
        }

        return $request->attributes->get(self::ATTRIBUTE);
    }
}
