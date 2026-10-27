<?php

namespace App\Http\Requests;

use App\Booking\Locations;
use App\Rules\InternationalPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update of a professional of the ACTIVE Workspace: who they are, which services they perform and their weekly
 * schedule as a flat list of intervals (ISO weekday, local times; a break is the gap between two intervals of a day).
 * Authorization is the `workspace.permission:create-professionals` / `edit-professionals` route middleware.
 */
class SaveProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');
        $stored = $this->route('professional') ? $workspace->professionals()->whereKey($this->route('professional'))->value('phone') : null;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('professionals', 'name')->where('workspace_id', $workspace->id)->ignore($this->route('professional'))],
            'specialty' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', new InternationalPhone($stored)],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('workspace_id', $workspace->id)],
            'schedule' => ['nullable', 'array', 'max:56'],
            'schedule.*.weekday' => ['required', 'integer', 'between:1,7'],
            'schedule.*.starts_at' => ['required', 'date_format:H:i'],
            'schedule.*.ends_at' => ['required', 'date_format:H:i'],
            // The location (sede) the interval is for; empty = every location. Only a location of this Workspace.
            'schedule.*.location_id' => app(Locations::class)->schemaReady() ? ['nullable', 'integer', Rule::exists('locations', 'id')->where('workspace_id', $workspace->id)] : ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // Locations are a module of the plan: without it nothing can be tied to one.
            $named = collect($this->input('schedule', []))->filter(fn ($row) => is_array($row) && filled($row['location_id'] ?? null));

            if ($named->isNotEmpty() && ! app(Locations::class)->enabled($this->attributes->get('workspace'))) {
                $validator->errors()->add('schedule', 'El plan de este Workspace no incluye Sedes.');

                return;
            }

            // A new assignment only takes ACTIVE services and locations of this Workspace; one the professional already has stays even if it was switched off
            // later (editing never drops it silently, and the history is kept). A forged id of an inactive record is refused here, not only hidden in the form.
            $workspace = $this->attributes->get('workspace');
            $professional = $this->route('professional') ? $workspace->professionals()->find($this->route('professional')) : null;
            $keptServices = $professional ? $professional->services()->pluck('services.id')->map(fn ($id) => (int) $id) : collect();
            $inactive = $workspace->services()->whereIn('id', $this->serviceIds())->where('is_active', false)->pluck('id')->map(fn ($id) => (int) $id)->diff($keptServices);

            if ($inactive->isNotEmpty()) {
                $validator->errors()->add('service_ids', 'Uno de los servicios elegidos no está activo.');
            }

            $placeIds = $named->pluck('location_id')->map(fn ($id) => (int) $id)->unique();

            if ($placeIds->isNotEmpty()) {
                $keptPlaces = $professional ? $professional->scheduleRules()->whereNotNull('location_id')->pluck('location_id')->map(fn ($id) => (int) $id) : collect();
                $closed = $workspace->locations()->whereIn('id', $placeIds)->where('is_active', false)->pluck('id')->map(fn ($id) => (int) $id)->diff($keptPlaces);

                if ($closed->isNotEmpty()) {
                    $validator->errors()->add('schedule', 'Una de las sedes elegidas no está activa.');
                }
            }

            $byDay = collect($this->input('schedule', []))->filter(fn ($row) => is_array($row))->groupBy('weekday');

            foreach ($byDay as $rows) {
                $rows = $rows->sortBy('starts_at')->values();

                foreach ($rows as $index => $row) {
                    $overlaps = $index > 0 && ($row['starts_at'] ?? '') < ($rows[$index - 1]['ends_at'] ?? '');

                    if (($row['starts_at'] ?? '') >= ($row['ends_at'] ?? '') || $overlaps) {
                        $validator->errors()->add('schedule', 'Revisa el horario: cada tramo debe terminar después de empezar y los tramos de un día no pueden cruzarse.');

                        return;
                    }
                }
            }
        }];
    }

    public function attributes(): array
    {
        return ['name' => 'el nombre', 'specialty' => 'la especialidad', 'phone' => 'el teléfono'];
    }

    /** @return array<string, mixed> */
    public function professional(): array
    {
        $data = $this->validated();

        return ['name' => trim($data['name']), 'specialty' => filled($data['specialty'] ?? null) ? trim($data['specialty']) : null, 'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null];
    }

    /** @return list<int> */
    public function serviceIds(): array
    {
        return array_map('intval', $this->validated('service_ids') ?? []);
    }

    /** @return list<array{weekday: int, starts_at: string, ends_at: string, location_id: ?int}> */
    public function schedule(): array
    {
        return collect($this->validated('schedule') ?? [])->map(fn (array $row) => ['weekday' => (int) $row['weekday'], 'starts_at' => $row['starts_at'].':00', 'ends_at' => $row['ends_at'].':00', 'location_id' => filled($row['location_id'] ?? null) ? (int) $row['location_id'] : null])->values()->all();
    }

    public function hasSchedule(): bool
    {
        return $this->has('schedule');
    }
}
