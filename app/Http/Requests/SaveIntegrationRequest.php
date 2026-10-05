<?php

namespace App\Http\Requests;

use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / update of an integration. Authorization is the `workspace.permission:manage-settings` route middleware;
 * here the integration being edited is looked up INSIDE the active Workspace (another Workspace's id is a 404).
 */
class SaveIntegrationRequest extends FormRequest
{
    public ?Integration $current = null;

    public function authorize(): bool
    {
        if ($this->route('integration') !== null) {
            $this->current = $this->attributes->get('workspace')->integrations()->findOrFail($this->route('integration'));
        }

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $registry = app(IntegrationRegistry::class);
        $workspace = $this->attributes->get('workspace');
        // The type of an existing integration never changes.
        $type = $this->current?->type ?? $this->input('type');

        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('integrations', 'name')->where('workspace_id', $workspace->id)->ignore($this->current?->id)],
            'provider' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];

        // The type is chosen only on creation: that of an existing integration never changes.
        if (! $this->current) {
            $rules['type'] = ['required', Rule::in($registry->keys())];
        }

        return in_array($type, $registry->keys(), true)
            ? $rules + $registry->get($type)->rules($this->all(), $this->current)
            : $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'provider' => 'el proveedor',
            'description' => 'la descripción',
            'type' => 'el tipo',
            'base_url' => 'la URL base',
            'endpoint' => 'el endpoint',
            'method' => 'el método',
            'timeout' => 'el tiempo de espera',
            'auth_type' => 'el tipo de autenticación',
            'auth_name' => 'el nombre del parámetro',
            'auth_location' => 'la ubicación',
            'auth_username' => 'el usuario',
            'auth_secret' => 'la credencial',
            'headers' => 'los headers',
            'headers.*.name' => 'el nombre del header',
            'headers.*.value' => 'el valor del header',
            'query' => 'los parámetros',
            'query.*.name' => 'el nombre del parámetro',
            'query.*.value' => 'el valor del parámetro',
            'body' => 'el body',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una integración con ese nombre en este Workspace.',
            'headers.*.name.regex' => 'El nombre del header solo admite letras, números y guiones.',
            'query.*.name.regex' => 'El nombre del parámetro contiene caracteres no válidos.',
            'endpoint.regex' => 'El endpoint no puede contener espacios.',
            'timeout.between' => 'El tiempo de espera debe estar entre 1 y :max segundos.',
        ];
    }
}
