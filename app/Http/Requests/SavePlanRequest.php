<?php

namespace App\Http\Requests;

use App\Models\ModuleCategory;
use App\Models\Plan;
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
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'currency' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('workspace.currencies')))],
            'billing_period' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(Plan::PERIODS))],
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
            $price = $this->input('price');
            $priced = $price !== null && $price !== '' && (float) $price > 0;

            if ($priced && (blank($this->input('currency')) || blank($this->input('billing_period')))) {
                $validator->errors()->add('price', 'Un precio necesita su moneda y su periodicidad.');
            }

            if ($this->boolean('is_public') && ! $this->boolean('requires_quote')) {
                if (blank($this->input('module_category_id'))) {
                    $validator->errors()->add('module_category_id', 'Para ofrecer el plan en el registro público elige su solución.');
                }

                if (! $priced || blank($this->input('currency')) || blank($this->input('billing_period'))) {
                    $validator->errors()->add('price', 'Para ofrecer el plan con compra en línea indica su precio, moneda y periodicidad; o márcalo como «Por cotización».');
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
        $data = $this->validated();
        $out = [];

        foreach (['module_category_id', 'currency', 'billing_period', 'is_public', 'requires_quote'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === '' ? null : $data[$key];
            }
        }

        if (array_key_exists('price', $data)) {
            $out['price_cents'] = $data['price'] === null || $data['price'] === '' ? null : (int) round(((float) $data['price']) * 100);
        }

        return $out;
    }

    /** @return list<int> */
    public function moduleIds(): array
    {
        return array_map('intval', $this->validated('module_ids', []));
    }
}
