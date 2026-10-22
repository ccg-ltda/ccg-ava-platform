<?php

namespace App\Http\Requests;

use App\Booking\Locations;
use App\Services\LocationScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Create / update of a service of the ACTIVE Workspace. Authorization is the `workspace.permission:create-services` / `edit-services` route middleware. */
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
            // The locations that offer the service (the same `location_service` relation the Sedes page edits). None = offered in every location, as for every
            // service that existed before; the server validates each id (this Workspace, active) and a limited member can only choose their own.
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)],
            'resource_ids' => ['nullable', 'array'],
            'resource_ids.*' => ['integer', Rule::exists('bookable_resources', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! app(Locations::class)->schemaReady()) {
                return;
            }

            $workspace = $this->attributes->get('workspace');
            $ids = $this->locationIds();
            $scope = app(LocationScope::class);
            $kept = $this->route('service') ? DB::table('location_service')->where('service_id', $this->route('service'))->pluck('location_id')->map(fn ($id) => (int) $id) : collect();

            if ($ids !== []) {
                $active = $workspace->locations()->where('is_active', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);

                if (collect($ids)->diff($active)->diff($kept)->isNotEmpty()) {
                    $validator->errors()->add('location_ids', 'Una de las sedes elegidas no está activa.');
                }
            }

            if ($scope->restricted($workspace, $this->user())) {
                if ($ids === []) {
                    $validator->errors()->add('location_ids', 'Elige al menos una de tus sedes: un servicio sin sedes se ofrece en todas.');
                } elseif (collect($ids)->diff($scope->allowedIds($workspace, $this->user()))->diff($kept)->isNotEmpty()) {
                    $validator->errors()->add('location_ids', 'No tienes acceso a una de las sedes elegidas.');
                }
            }
        }];
    }

    /** @return list<int> the locations chosen (empty = every location) */
    public function locationIds(): array
    {
        return collect((array) $this->input('location_ids', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values()->all();
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
