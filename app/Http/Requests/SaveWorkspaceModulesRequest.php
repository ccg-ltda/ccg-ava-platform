<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The exceptions of one Workspace over its plan: modules granted, modules revoked and an optional reason (ModuleCatalog applies the business rules). */
class SaveWorkspaceModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => ($reason = trim((string) $this->input('reason'))) === '' ? null : $reason]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'granted' => ['present', 'array'],
            'granted.*' => ['integer', 'distinct', Rule::exists('modules', 'id')],
            'revoked' => ['present', 'array'],
            'revoked.*' => ['integer', 'distinct', Rule::exists('modules', 'id')],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<int> */
    public function ids(string $field): array
    {
        return array_map('intval', $this->validated($field, []));
    }
}
