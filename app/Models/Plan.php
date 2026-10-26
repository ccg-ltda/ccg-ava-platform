<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** A commercial plan: the modules a Workspace may use. Plans are created by CCG and never deleted, only deactivated. */
#[Fillable(['key', 'name', 'description', 'module_category_id', 'price_monthly_cents', 'price_yearly_cents', 'currency', 'is_public', 'requires_quote', 'is_active'])]
class Plan extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_public' => 'boolean', 'requires_quote' => 'boolean', 'price_monthly_cents' => 'integer', 'price_yearly_cents' => 'integer'];
    }

    public const PERIODS = ['monthly' => 'Mensual', 'yearly' => 'Anual'];

    /**
     * Whether the database has the commercial columns (migrations `create_public_signup_tables` and `split_plan_prices_by_period`). Code can be deployed
     * before they are applied; until then the commercial offer can not be saved, and the editor and the server say so instead of failing in SQL.
     */
    public static function commercialReady(): bool
    {
        return Schema::hasColumn('plans', 'module_category_id') && Schema::hasColumn('plans', 'is_public') && Schema::hasColumn('plans', 'price_monthly_cents');
    }

    /**
     * Keeps the PRIMARY solution column (`plans.module_category_id`, the first solution) among the plan's solutions when something sets it directly: the relation
     * `solutions` is the source of truth, the column only lets the previous release read the plan after a rollback.
     */
    protected static function booted(): void
    {
        static::saved(function (Plan $plan) {
            if ($plan->module_category_id !== null && ($plan->wasRecentlyCreated || $plan->wasChanged('module_category_id')) && self::solutionsReady()) {
                $plan->solutions()->syncWithoutDetaching([$plan->module_category_id]);
            }
        });
    }

    /** The solutions (module categories) the plan is offered under; none = a custom plan. A plan appears once per solution (unique in the database). */
    public function solutions(): BelongsToMany
    {
        return $this->belongsToMany(ModuleCategory::class, 'module_category_plan')->withTimestamps()->orderBy('module_categories.sort_order')->orderBy('module_categories.name');
    }

    /** Whether the database has the table of the plan solutions (migration `create_module_category_plan_table`). */
    public static function solutionsReady(): bool
    {
        return Schema::hasTable('module_category_plan');
    }

    /**
     * The solutions of the plan. Until the migration is applied the plan has the one in its primary column, exactly as before, so a deployment ahead of the
     * migration neither fails nor loses its offer.
     *
     * @return Collection<int, ModuleCategory>
     */
    public function solutionList(): Collection
    {
        if (self::solutionsReady()) {
            return $this->solutions;
        }

        return $this->module_category_id !== null ? ModuleCategory::whereKey($this->module_category_id)->get() : new Collection;
    }

    /** @return list<int> the ids of the plan's solutions */
    public function solutionIds(): array
    {
        return $this->solutionList()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The solutions where the public sign-up may show the plan: active, public and a real commercial solution. @return Collection<int, ModuleCategory> */
    public function offeredSolutions(): Collection
    {
        return $this->solutionList()->filter(fn (ModuleCategory $solution) => $solution->isPubliclyOffered() && $solution->isSolution())->values();
    }

    /** The price in minor units for a period, or null when that period is not sold: it needs an amount above zero AND the currency. Nothing is assumed. */
    public function priceFor(?string $period): ?int
    {
        $cents = match ($period) {
            'monthly' => $this->price_monthly_cents,
            'yearly' => $this->price_yearly_cents,
            default => null,
        };

        return $cents !== null && $cents > 0 && filled($this->currency) ? $cents : null;
    }

    /** @return list<string> the periods that have a usable price, monthly first */
    public function availablePeriods(): array
    {
        return array_values(array_filter(array_keys(self::PERIODS), fn (string $period) => $this->priceFor($period) !== null));
    }

    /** Whether at least one period has a usable price and the currency. */
    public function hasUsablePrice(): bool
    {
        return $this->availablePeriods() !== [];
    }

    /**
     * Where the plan stands in the public offer, and why it is not offered when it should be. ONE definition used by the public catalog
     * (SignupCatalog) and by the administration, so what the administrator sees is exactly what the sign-up does.
     *  - private: not marked public (nothing to fix);
     *  - online: public and complete, sold with an immediate checkout;
     *  - quote: public and sold by quote (no checkout, no provisioning);
     *  - incomplete: marked public but missing something, so it is NOT shown to the public.
     *
     * @return array{state: string, issues: list<string>}
     */
    public function offerState(): array
    {
        if (! $this->is_public) {
            return ['state' => 'private', 'issues' => []];
        }

        $issues = [];

        if (! $this->is_active) {
            $issues[] = 'El plan está desactivado.';
        }

        $solutions = $this->solutionList();

        if (! $this->requires_quote && $solutions->isEmpty()) {
            $issues[] = 'Falta la solución.';
        }

        // A plan is shown under each of its solutions that can be offered; it is incomplete only when none of them can.
        if ($solutions->isNotEmpty() && $this->offeredSolutions()->isEmpty()) {
            $issues[] = $solutions->every(fn (ModuleCategory $solution) => ! $solution->is_active)
                ? ($solutions->count() === 1 ? 'La solución está desactivada.' : 'Todas sus soluciones están desactivadas.')
                : 'Ninguna de sus soluciones se puede ofrecer: deben estar activas, ser públicas y ser soluciones comerciales (no capacidades compartidas).';
        }

        if (! $this->requires_quote && ! $this->hasUsablePrice()) {
            $issues[] = 'Falta la moneda o al menos un precio (mensual o anual).';
        }

        return ['state' => $issues !== [] ? 'incomplete' : ($this->requires_quote ? 'quote' : 'online'), 'issues' => $issues];
    }

    /** Whether the public sign-up shows the plan. */
    public function isOffered(): bool
    {
        return in_array($this->offerState()['state'], ['online', 'quote'], true);
    }

    /** Whether the public sign-up may sell it with an immediate checkout: active, public, not by quote and with a usable price. */
    public function isPurchasable(): bool
    {
        return $this->is_active && $this->is_public && ! $this->requires_quote && $this->hasUsablePrice();
    }

    /** The system plan that every Workspace without an explicit choice falls back to (config `modules.default_plan`). */
    public function isDefault(): bool
    {
        return $this->key !== null && $this->key === config('modules.default_plan');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class)->withTimestamps();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function auditResource(): string
    {
        return 'plan';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['name' => 'Nombre', 'description' => 'Descripción', 'is_active' => 'Activo'];
    }
}
