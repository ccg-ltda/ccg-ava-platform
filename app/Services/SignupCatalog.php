<?php

namespace App\Services;

use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What the public sign-up offers, read from the real commercial configuration (Planes): a plan is offered only while it is active and marked public; a
 * solution is a module category that has at least one such plan; "Personalizado" exists only while there is a public plan sold by quote that belongs to
 * no solution. Prices, currencies, periods and modules come from the plan; a missing price is shown as missing, never filled in. The limits shown are the ones the plan really
 * sets (`WorkspaceLimits::METRICS`, the same source the backend enforces); a metric without a value is shown as "sin límite definido", never invented. Read only: nothing here grants access to a module.
 */
class SignupCatalog
{
    public const CUSTOM = 'custom';

    public function __construct(private readonly ModuleAccess $access, private readonly WorkspaceLimits $limits) {}

    private ?bool $ready = null;

    /**
     * Whether the database has what the sign-up reads (the migration `create_public_signup_tables` was applied). Without it nothing is offered and nothing
     * is queried, so a deployment ahead of its migration shows "no offers" instead of a SQL error.
     */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('signup_requests') && Schema::hasTable('signup_verifications') && Schema::hasColumn('plans', 'is_public') && Schema::hasColumn('plans', 'price_monthly_cents') && Plan::solutionsReady();
    }

    /** @return Collection<int, Plan> every plan the public sign-up may show */
    private function offered(): Collection
    {
        if (! $this->ready()) {
            return new Collection;
        }

        return Plan::query()->where('is_active', true)->where('is_public', true)
            ->with(['modules' => fn ($q) => $q->where('modules.is_active', true)->orderBy('modules.name'), 'solutions'])
            ->get()
            ->filter(fn (Plan $plan) => $plan->isOffered())
            // Cheapest first, comparing every plan by its monthly cost (a yearly total over 12 when there is no monthly price); a quote has no price and goes last.
            ->sortBy(fn (Plan $plan) => [$plan->priceFor('monthly') ?? ($plan->priceFor('yearly') !== null ? intdiv($plan->priceFor('yearly'), 12) : PHP_INT_MAX), mb_strtolower($plan->name)])
            ->values();
    }

    /** Whether there is anything to contract at all: without it the sign-up says so instead of collecting data for nothing. */
    public function hasOffers(): bool
    {
        return $this->offered()->isNotEmpty();
    }

    /** @return list<array<string, mixed>> */
    public function solutions(): array
    {
        // A plan is listed once under EACH solution it is offered under (a solution that can not be offered shows none of its plans).
        $solutions = $this->offered()->flatMap(fn (Plan $plan) => $plan->solutions->isEmpty() ? [[self::CUSTOM, $plan]] : $plan->offeredSolutions()->map(fn (ModuleCategory $solution) => [$solution->id, $plan])->all())
            ->groupBy(fn (array $pair) => $pair[0]);

        return $solutions->map(function (Collection $pairs, string|int $key) {
            $plans = $pairs->map(fn (array $pair) => $pair[1])->unique('id');
            $custom = $key === self::CUSTOM;
            $solution = $custom ? null : $plans->first()->solutions->firstWhere('id', (int) $key);
            $modules = $plans->flatMap(fn (Plan $plan) => $plan->modules->pluck('name'))->unique()->values();

            return [
                'key' => $custom ? self::CUSTOM : (string) $key,
                'name' => $custom ? 'Personalizado' : $solution->name,
                'description' => $custom ? 'Una solución a la medida de tu empresa, con un asesor de CCG.' : $solution->description,
                'custom' => $custom,
                'plansCount' => $plans->count(),
                'modulesCount' => $modules->count(),
                'modulesSummary' => $modules->take(4)->all(),
                'sort' => $custom ? PHP_INT_MAX : (int) $solution->sort_order,
            ];
        })->sortBy('sort')->map(fn (array $solution) => array_diff_key($solution, ['sort' => 1]))->values()->all();
    }

    /** The name of an offered solution by its key, or null (a stale or tampered key). */
    public function solutionName(string $key): ?string
    {
        return $this->solution($key)['name'] ?? null;
    }

    /** Whether the plan is shown under the solution (or is a custom plan, for «Personalizado»): ONE rule for listing, choosing and verifying a plan. */
    private function inSolution(Plan $plan, string $solutionKey): bool
    {
        return $solutionKey === self::CUSTOM ? $plan->solutions->isEmpty() : $plan->offeredSolutions()->contains(fn (ModuleCategory $solution) => (string) $solution->id === $solutionKey);
    }

    /** The solution as the wizard knows it, or null when it is not offered (a stale or tampered key). */
    public function solution(string $key): ?array
    {
        return collect($this->solutions())->firstWhere('key', $key);
    }

    /** @return list<array<string, mixed>> the plans of one solution, in the order the page shows them */
    public function plans(string $solutionKey): array
    {
        return $this->offered()
            ->filter(fn (Plan $plan) => $this->inSolution($plan, $solutionKey))
            ->map(fn (Plan $plan) => $this->plan($plan))->values()->all();
    }

    /** The plan offered under this solution, or null: the server re-checks it on every step, it never trusts the browser. */
    public function offeredPlan(string $solutionKey, int $planId): ?Plan
    {
        return $this->offered()->first(fn (Plan $plan) => $plan->id === $planId && $this->inSolution($plan, $solutionKey));
    }

    /** @return array<string, mixed> */
    public function plan(Plan $plan): array
    {
        $operative = $this->access->operative($plan->modules->pluck('key')->filter(fn (string $key) => $this->access->isImplemented($key))->all());

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'requiresQuote' => $plan->requires_quote,
            'purchasable' => $plan->isPurchasable(),
            // One entry per period that has a usable price (monthly first); empty = nothing can be charged and the page says so.
            'prices' => array_map(fn (string $period) => $this->price($plan, $period), $plan->availablePeriods()),
            'modules' => $plan->modules->map(fn (Module $module) => [
                'key' => $module->key,
                'name' => $module->name,
                'description' => $module->description,
                'operative' => in_array($module->key, $operative, true),
            ])->values()->all(),
            'limits' => $this->limitsOf($this->limits->planLimits($plan)),
        ];
    }

    /**
     * The limits as the pages show them: one row per metric the backend enforces, with its unit (e.g. executions per calendar month). A null value means the
     * plan sets none (not that it is zero).
     *
     * @param  array<string, ?int>  $values  metric => value
     * @return list<array{key: string, label: string, unit: string, value: ?int}>
     */
    public function limitsOf(array $values): array
    {
        return collect(WorkspaceLimits::METRICS)->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label'], 'unit' => $meta['unit'], 'value' => $values[$key] ?? null])->values()->all();
    }

    /** @return array{amount: float, amountCents: int, currency: string, period: string, periodLabel: string} the price of one period of a plan that sells it */
    public function price(Plan $plan, string $period): array
    {
        $cents = (int) $plan->priceFor($period);

        return ['amount' => $cents / 100, 'amountCents' => $cents, 'currency' => (string) $plan->currency, 'period' => $period, 'periodLabel' => Plan::PERIODS[$period]];
    }

    /** @return list<array{key: string, name: string}> the solutions as options for the Planes form (every active module category) */
    public function options(): array
    {
        return ModuleCategory::where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])->map(fn (ModuleCategory $category) => ['key' => (string) $category->id, 'name' => $category->name])->all();
    }
}
