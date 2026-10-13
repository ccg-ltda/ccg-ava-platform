<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update of a professional of the ACTIVE Workspace: who they are, which services they perform and their weekly
 * schedule as a flat list of intervals (ISO weekday, local times; a break is the gap between two intervals of a day).
 * Authorization is the `workspace.permission:manage-services` route middleware.
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

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('professionals', 'name')->where('workspace_id', $workspace->id)->ignore($this->route('professional'))],
            'specialty' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('workspace_id', $workspace->id)],
            'schedule' => ['nullable', 'array', 'max:56'],
            'schedule.*.weekday' => ['required', 'integer', 'between:1,7'],
            'schedule.*.starts_at' => ['required', 'date_format:H:i'],
            'schedule.*.ends_at' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
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

    /** @return list<array{weekday: int, starts_at: string, ends_at: string}> */
    public function schedule(): array
    {
        return collect($this->validated('schedule') ?? [])->map(fn (array $row) => ['weekday' => (int) $row['weekday'], 'starts_at' => $row['starts_at'].':00', 'ends_at' => $row['ends_at'].':00'])->values()->all();
    }

    public function hasSchedule(): bool
    {
        return $this->has('schedule');
    }
}
