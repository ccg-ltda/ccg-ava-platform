<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registers or edits an organizational category of modules. Categories are data: a new one needs no code. */
class SaveModuleCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', Rule::unique('module_categories', 'name')->ignore($this->route('category'))],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
