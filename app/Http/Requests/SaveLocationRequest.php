<?php

namespace App\Http\Requests;

use App\Booking\Locations;
use App\Rules\InternationalPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update of a location (sede) of the ACTIVE Workspace. Authorization is the `workspace.permission:create-locations` / `edit-locations` route middleware. */
class SaveLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')->where('workspace_id', $workspace->id)],
        ];
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

        return ['name' => trim($data['name']), 'address' => $text('address'), 'city' => $text('city'), 'phone' => $text('phone'), 'notes' => $text('notes')];
    }

    /** @return list<int> */
    public function serviceIds(): array
    {
        return array_map('intval', $this->validated('service_ids') ?? []);
    }
}
