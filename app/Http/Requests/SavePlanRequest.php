<?php

namespace App\Http\Requests;

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
            'module_ids' => ['sometimes', 'array'],
            'module_ids.*' => ['integer', 'distinct', Rule::exists('modules', 'id')],
        ];
    }

    /** @return list<int> */
    public function moduleIds(): array
    {
        return array_map('intval', $this->validated('module_ids', []));
    }
}
