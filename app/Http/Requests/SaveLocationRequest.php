<?php

namespace App\Http\Requests;

use App\Booking\Locations;
use App\Rules\InternationalPhone;
use App\Services\LocationScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Create / update of a location (sede) of the ACTIVE Workspace. Authorization is the `workspace.permission:create-locations` / `edit-locations` route middleware. */
class SaveLocationRequest extends FormRequest
{
    /**
     * Who may touch the location is decided BEFORE the form is validated, so a foreign or out-of-scope location answers 404 / 403 and never a validation hint:
     * the location must be of the active Workspace, a member limited to selected locations can only edit their own and can not create one.
     */
    public function authorize(): bool
    {
        $workspace = $this->attributes->get('workspace');

        if (! $workspace || ! app(Locations::class)->schemaReady()) {
            return true;
        }

        $scope = app(LocationScope::class);

        if ($this->route('location')) {
            $model = $workspace->locations()->findOrFail($this->route('location'));
            $scope->assertAllows($workspace, $this->user(), $model->id);
        } else {
            abort_if($scope->restricted($workspace, $this->user()), 403, 'Solo quien tiene acceso a todas las sedes puede crear una sede.');
        }

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        // Before the migration there is no table to validate against: the controller then refuses the change with a clear message.
        if (! app(Locations::class)->schemaReady()) {
            return ['name' => ['required', 'string', 'max:120']];
        }

        $workspace = $this->attributes->get('workspace');
        $stored = $this->route('location') ? $workspace->locations()->whereKey($this->route('location'))->value('phone') : null;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('locations', 'name')->where('workspace_id', $workspace->id)->ignore($this->route('location'))],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', new InternationalPhone($stored)],
            'notes' => ['nullable', 'string', 'max:500'],
            // «Todos los servicios» is the flag (no list, no ids); otherwise at least one service of this Workspace. With the flag on, a list is ignored.
            'offers_all_services' => ['nullable', 'boolean'],
            // Opening hours: `has_hours` false = no hours configured (the sede is not restricted); true = the open days listed, every other day closed. Absent = unchanged.
            'has_hours' => ['nullable', 'boolean'],
            'hours' => ['nullable', 'array', 'max:7'],
            'hours.*.weekday' => ['required_with:hours', 'integer', 'between:1,7', 'distinct'],
            'hours.*.opens_at' => ['required_with:hours', 'date_format:H:i'],
            'hours.*.closes_at' => ['required_with:hours', 'date_format:H:i'],
            'service_ids' => $this->boolean('offers_all_services') ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    /** A location can only start offering an ACTIVE service; one it already offers can stay even if it was deactivated later (editing never drops it silently). */
    public function after(): array
    {
        return [function ($validator) {
            $workspace = $this->attributes->get('workspace');

            if ($validator->errors()->isNotEmpty() || ! $workspace || ! app(Locations::class)->schemaReady()) {
                return;
            }

            if ($this->boolean('has_hours')) {
                if (! app(Locations::class)->hoursReady()) {
                    $validator->errors()->add('hours', 'Falta aplicar la última migración de la base de datos para guardar horarios.');
                } elseif (collect($this->input('hours', []))->isEmpty()) {
                    $validator->errors()->add('hours', 'Marca al menos un día de atención o desactiva el horario de la sede.');
                } elseif (collect($this->input('hours', []))->contains(fn ($row) => is_array($row) && ($row['opens_at'] ?? '') >= ($row['closes_at'] ?? ''))) {
                    $validator->errors()->add('hours', 'En cada día, la hora de cierre debe ser posterior a la de apertura.');
                }
            }

            $kept = $this->route('location') ? $workspace->locations()->whereKey($this->route('location'))->first()?->services()->pluck('services.id')->all() ?? [] : [];
            $inactive = $workspace->services()->whereIn('id', $this->serviceIds())->where('is_active', false)->pluck('id')->diff($kept);

            if ($inactive->isNotEmpty()) {
                $validator->errors()->add('service_ids', 'Uno de los servicios elegidos no está activo.');
            }
        }];
    }

    public function messages(): array
    {
        return ['service_ids.required' => 'Elige al menos un servicio que ofrece la sede.', 'service_ids.min' => 'Elige al menos un servicio que ofrece la sede.'];
    }

    public function attributes(): array
    {
        return ['name' => 'el nombre', 'address' => 'la dirección', 'city' => 'la ciudad', 'phone' => 'el teléfono', 'notes' => 'las indicaciones'];
    }

    /** @return array<string, mixed> the columns of the location */
    public function location(): array
    {
        $data = $this->validated();
        $text = fn (string $key) => filled($data[$key] ?? null) ? trim($data[$key]) : null;

        return ['name' => trim($data['name']), 'address' => $text('address'), 'city' => $text('city'), 'phone' => $text('phone'), 'notes' => $text('notes')] + (Schema::hasColumn('locations', 'offers_all_services') ? ['offers_all_services' => $this->offersAllServices()] : []);
    }

    /** Whether the form said anything about the hours (otherwise they stay as they are). */
    public function hasHoursInput(): bool
    {
        return $this->has('has_hours');
    }

    /** @return list<array{weekday: int, opens_at: string, closes_at: string}> the open days (empty = no hours configured) */
    public function hours(): array
    {
        return ! $this->boolean('has_hours') ? [] : collect($this->validated('hours') ?? [])->map(fn (array $row) => ['weekday' => (int) $row['weekday'], 'opens_at' => $row['opens_at'].':00', 'closes_at' => $row['closes_at'].':00'])->sortBy('weekday')->values()->all();
    }

    public function offersAllServices(): bool
    {
        return $this->boolean('offers_all_services');
    }

    /** @return list<int> the services chosen one by one; none while «Todos los servicios» is on (an ambiguous mix is not accepted) */
    public function serviceIds(): array
    {
        return $this->offersAllServices() ? [] : array_map('intval', $this->validated('service_ids') ?? []);
    }
}
