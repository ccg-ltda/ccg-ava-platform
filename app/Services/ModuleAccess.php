<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceModuleOverride;
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
 *
 * Two layers sit on top of the plan, and nothing else: (1) the subscription of the Workspace, when it has one, must not withdraw access
 * (suspended, cancelled, expired; pending, active and grace do not; a Workspace WITHOUT a subscription row is a legacy one governed by its plan alone), and (2) the authorized exceptions of
 * that single Workspace (`workspace_module_overrides`): a `grant` adds a module the plan lacks, a `revoke` removes one it includes. The plan itself is
 * never edited by an exception. Dependencies are applied last, so an exception can never make a module operative without what it needs.
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

        if (! $plan || ! $plan->is_active || ! $this->subscriptionAllows($workspace)) {
            return [];
        }

        $keys = $plan->modules()->where('modules.is_active', true)->pluck('modules.key')
            ->filter(fn (string $key) => $this->isImplemented($key))->values()->all();

        return $this->operative($this->withOverrides($workspace, $keys));
    }

    /** The subscription of a client Workspace, if it has one. */
    public function subscription(Workspace $workspace): ?Subscription
    {
        return Subscription::where('workspace_id', $workspace->id)->first();
    }

    /** No subscription row = legacy Workspace (plan only). With one, only a status that grants access opens the modules. */
    public function subscriptionAllows(Workspace $workspace): bool
    {
        $subscription = $this->subscription($workspace);

        return $subscription === null || ! $subscription->withdrawsAccess();
    }

    /**
     * Applies the exceptions of this Workspace to the modules its plan gives. Only active, implemented modules can be granted.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function withOverrides(Workspace $workspace, array $keys): array
    {
        $overrides = WorkspaceModuleOverride::where('workspace_id', $workspace->id)->join('modules', 'modules.id', '=', 'workspace_module_overrides.module_id')
            ->get(['modules.key as key', 'modules.is_active as active', 'workspace_module_overrides.mode as mode']);

        foreach ($overrides as $override) {
            if ($override->mode === WorkspaceModuleOverride::REVOKE) {
                $keys = array_values(array_diff($keys, [$override->key]));
            } elseif ($override->active && $this->isImplemented($override->key)) {
                $keys[] = $override->key;
            }
        }

        return array_values(array_unique($keys));
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

    /** Modules the booking tools of the agent API need (see routes/api.php). */
    public const BOOKING_MODULES = ['assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];

    /** Every module the assistant's order tools need (the channel, the catalog and Pedidos). */
    public const ORDER_MODULES = ['assistants', 'conversations', 'product_catalog', 'orders'];

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
