<?php

namespace App\Http\Requests;

use App\Services\SavedFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates or updates a saved filter of a module. The module must exist and the user needs the permission that opens its
 * page (403). The name is unique among the user's filters of that module in the active Workspace, and the criteria are
 * checked against that Workspace's data; a filter with no criteria at all is refused.
 */
class SavedFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(SavedFilters::class)->allows((string) $this->route('scope'), $this->attributes->get('workspace_permissions', []));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $scope = $this->route('scope');
        $presence = $this->isMethod('PUT') ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$presence, 'string', 'max:'.config('saved_filters.name_max'),
                Rule::unique('saved_filters', 'name')
                    ->where('workspace_id', $this->attributes->get('workspace')->id)->where('user_id', $this->user()->id)->where('scope', $scope)
                    ->ignore($this->route('filter')),
            ],
            'criteria' => [...$presence, 'array'],
            ...app(SavedFilters::class)->criteriaRules($scope, $this->attributes->get('workspace')),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('criteria') || ! $this->has('criteria')) {
                return;
            }

            $allowed = collect(array_keys(app(SavedFilters::class)->criteriaRules($this->route('scope'), $this->attributes->get('workspace'))))->map(fn (string $key) => substr($key, 9));

            if (collect($this->input('criteria'))->keys()->diff($allowed)->isNotEmpty()) {
                $validator->errors()->add('criteria', 'Hay criterios que este módulo no admite.');
            } elseif ($this->cleaned() === []) {
                $validator->errors()->add('criteria', 'Configura al menos un filtro antes de guardar.');
            }
        }];
    }

    /** @return array<string, mixed> the criteria that have a value */
    public function cleaned(): array
    {
        return app(SavedFilters::class)->clean($this->input('criteria', []));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'criteria' => 'filtros', 'criteria.from' => 'fecha inicial', 'criteria.to' => 'fecha final', 'criteria.user' => 'usuario',
            'criteria.resource' => 'módulo', 'criteria.action' => 'acción', 'criteria.status' => 'estado', 'criteria.channel' => 'canal', 'criteria.chatbot' => 'asistente',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.unique' => 'Ya tienes un filtro guardado con ese nombre.'];
    }
}
