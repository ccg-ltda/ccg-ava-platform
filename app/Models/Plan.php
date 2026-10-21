<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A commercial plan: the modules a Workspace may use. Plans are created by CCG and never deleted, only deactivated. */
#[Fillable(['key', 'name', 'description', 'module_category_id', 'price_cents', 'currency', 'billing_period', 'is_public', 'requires_quote', 'is_active'])]
class Plan extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_public' => 'boolean', 'requires_quote' => 'boolean', 'price_cents' => 'integer'];
    }

    public const PERIODS = ['monthly' => 'Mensual', 'yearly' => 'Anual'];

    /** The solution (module category) the plan is offered under, if any. */
    public function solution(): BelongsTo
    {
        return $this->belongsTo(ModuleCategory::class, 'module_category_id');
    }

    /** Whether the plan has a complete, usable price: an amount, a currency and a period. Nothing is assumed when one is missing. */
    public function hasUsablePrice(): bool
    {
        return $this->price_cents !== null && $this->price_cents > 0 && filled($this->currency) && array_key_exists((string) $this->billing_period, self::PERIODS);
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

        if (! $this->requires_quote && $this->module_category_id === null) {
            $issues[] = 'Falta la solución.';
        }

        if ($this->module_category_id !== null && $this->solution?->is_active === false) {
            $issues[] = 'La solución está desactivada.';
        }

        if ($this->module_category_id !== null && $this->solution !== null && ! $this->solution->isSolution()) {
            $issues[] = 'La categoría elegida agrupa capacidades compartidas, no es una solución comercial.';
        }

        if (! $this->requires_quote && ! $this->hasUsablePrice()) {
            $issues[] = 'Falta el precio, la moneda o la periodicidad.';
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
