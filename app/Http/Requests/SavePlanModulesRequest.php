<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The modules of a plan: any registered module, of any category (inactive ones can only stay where they already are, see ModuleCatalog). */
class SavePlanModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'module_ids' => ['present', 'array'],
            'module_ids.*' => ['integer', 'distinct', Rule::exists('modules', 'id')],
        ];
    }

    /** @return list<int> */
    public function moduleIds(): array
    {
        return array_map('intval', $this->validated('module_ids', []));
    }
}
