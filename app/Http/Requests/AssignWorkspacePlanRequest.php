<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The plan chosen for a Workspace (the Workspace comes from the route and ModuleCatalog::assignPlan applies the business rules). */
class AssignWorkspacePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['plan_id' => ['required', 'integer', Rule::exists('plans', 'id')]];
    }
}
