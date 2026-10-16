<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update of a service of the ACTIVE Workspace. Authorization is the `workspace.permission:manage-services` route middleware. */
class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');
        $current = $this->route('service');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('services', 'name')->where('workspace_id', $workspace->id)->ignore($current)],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:80'],
            'duration_minutes' => ['required', 'integer', 'between:5,720'],
            'buffer_minutes' => ['nullable', 'integer', 'between:0,240'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'resource_ids' => ['nullable', 'array'],
            'resource_ids.*' => ['integer', Rule::exists('bookable_resources', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'el nombre', 'duration_minutes' => 'la duración', 'buffer_minutes' => 'el tiempo entre citas', 'price' => 'el precio', 'category' => 'la categoría'];
    }

    /** @return array<string, mixed> the columns of the service */
    public function service(): array
    {
        $data = $this->validated();

        return [
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'category' => filled($data['category'] ?? null) ? trim($data['category']) : null,
            'duration_minutes' => (int) $data['duration_minutes'],
            'buffer_minutes' => (int) ($data['buffer_minutes'] ?? 0),
            'price' => isset($data['price']) && $data['price'] !== '' ? $data['price'] : null,
        ];
    }

    /** @return list<int> */
    public function resourceIds(): array
    {
        return array_map('intval', $this->validated('resource_ids') ?? []);
    }
}
