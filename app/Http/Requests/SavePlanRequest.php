<?php

namespace App\Http\Requests;

use App\Models\ModuleCategory;
use App\Models\Plan;
use App\Services\WorkspaceLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Name and description of a plan. A new plan may also come with its modules (see SavePlanModulesRequest). */
class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => ($description = trim((string) $this->input('description'))) === '' ? null : $description,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('plans', 'name')->ignore($this->route('plan'))],
            'description' => ['nullable', 'string', 'max:1000'],
            // Commercial offer (all optional; only the keys that are sent are changed). A plan is offered in the public sign-up only when it is public.
            'module_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('module_categories', 'id')->where('is_active', true), function ($attribute, $value, $fail) {
                if ($value !== null && ! ModuleCategory::whereKey($value)->first()?->isSolution()) {
                    $fail('Elige una solución comercial: esa categoría agrupa capacidades compartidas.');
                }
            }],
            // Two prices of the same offer, in the same currency: monthly and yearly TOTAL. Numeric (never INF/NAN), not negative, at most 2 decimals; empty or 0 = that period is not sold.
            'price_monthly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'price_yearly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('workspace.currencies')))],
            // Standard limits the plan sells (only metrics the backend enforces). Empty = the plan sets none.
            'limits' => ['sometimes', 'array'],
            'limits.users' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'limits.ai_executions' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'is_public' => ['sometimes', 'boolean'],
            'requires_quote' => ['sometimes', 'boolean'],
            'module_ids' => ['sometimes', 'array'],
            'module_ids.*' => ['integer', 'distinct', Rule::exists('modules', 'id')],
        ];
    }

    /** A public plan sold online needs everything the checkout will use; a price is never half set. The server never fills in a missing value. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Code deployed ahead of its migrations: say it, never fail in SQL and never drop what was typed in silence.
            if (! Plan::commercialReady() && $this->offerTyped()) {
                $validator->errors()->add('offer', 'La oferta comercial todavía no se puede guardar: faltan migraciones pendientes de aplicar en esta base de datos.');
            }

            if ((filled($this->input('limits.users')) || filled($this->input('limits.ai_executions'))) && ! app(WorkspaceLimits::class)->available()) {
                $validator->errors()->add('limits', 'Los límites todavía no se pueden guardar: falta aplicar la migración pendiente.');
            }

            $priced = $this->isPriced('price_monthly') || $this->isPriced('price_yearly');

            if ($priced && blank($this->input('currency'))) {
                $validator->errors()->add('currency', 'Un precio necesita su moneda.');
            }

            if ($this->boolean('is_public') && ! $this->boolean('requires_quote')) {
                if (blank($this->input('module_category_id'))) {
                    $validator->errors()->add('module_category_id', 'Para ofrecer el plan en el registro público elige su solución.');
                }

                if (! $priced || blank($this->input('currency'))) {
                    $validator->errors()->add('price_monthly', 'Para ofrecer el plan con compra en línea indica la moneda y al menos un precio (mensual o anual); o márcalo como «Por cotización».');
                }
            }
        });
    }

    /**
     * The commercial fields that were sent, as columns (price in minor units). Absent keys are absent: saving a plan from a form that does not know
     * these fields never changes them.
     *
     * @return array<string, mixed>
     */
    public function commercial(): array
    {
        if (! Plan::commercialReady()) {
            return [];
        }

        $data = $this->validated();
        $out = [];

        foreach (['module_category_id', 'currency', 'is_public', 'requires_quote'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === '' ? null : $data[$key];
            }
        }

        foreach (['price_monthly' => 'price_monthly_cents', 'price_yearly' => 'price_yearly_cents'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $out[$column] = $data[$field] === null || $data[$field] === '' ? null : (int) round(((float) $data[$field]) * 100);
            }
        }

        return $out;
    }

    /** Whether the form carries any commercial value (not just empty defaults). */
    private function offerTyped(): bool
    {
        return $this->boolean('is_public') || $this->boolean('requires_quote') || filled($this->input('module_category_id')) || filled($this->input('currency'))
            || $this->isPriced('price_monthly') || $this->isPriced('price_yearly');
    }

    /** @return array<string, ?int>|null the standard limits that were sent (metric => value, null = none), or null when the form sent none */
    public function limits(): ?array
    {
        if (! $this->has('limits')) {
            return null;
        }

        return collect(array_keys(WorkspaceLimits::METRICS))->mapWithKeys(fn (string $metric) => [$metric => filled($this->input("limits.{$metric}")) ? (int) $this->input("limits.{$metric}") : null])->all();
    }

    private function isPriced(string $field): bool
    {
        $value = $this->input($field);

        return $value !== null && $value !== '' && is_numeric($value) && (float) $value > 0;
    }

    /** @return list<int> */
    public function moduleIds(): array
    {
        return array_map('intval', $this->validated('module_ids', []));
    }
}
