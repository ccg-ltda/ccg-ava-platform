<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update of an optional resource (a chair, a room) of the ACTIVE Workspace. Route middleware: `create-professionals` / `edit-professionals`. */
class SaveBookableResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('bookable_resources', 'name')->where('workspace_id', $this->attributes->get('workspace')->id)->ignore($this->route('resource'))],
            'kind' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'el nombre', 'kind' => 'el tipo'];
    }
}
