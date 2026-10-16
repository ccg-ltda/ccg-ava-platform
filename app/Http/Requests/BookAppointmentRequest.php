<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BookingInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asks to book a service. The same request serves the admin agenda, the public booking page and Ava Chat; who may ask is the
 * route's business (permission, public key, agent token), never this class. `website` is a honeypot: a person never fills it.
 */
class BookAppointmentRequest extends FormRequest
{
    use BookingInput;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['service_id' => ['required', self::ID], 'location_id' => ['nullable', 'integer', 'min:1'], 'website' => ['nullable', 'max:0']] + $this->slotRules() + $this->customerRules();
    }

    public function attributes(): array
    {
        return ['service_id' => 'el servicio', 'location_id' => 'la sede', 'starts_at' => 'la fecha y hora', 'customer.name' => 'el nombre', 'customer.phone' => 'el teléfono', 'customer.email' => 'el correo', 'notes' => 'la nota'];
    }

    /** @return array<string, mixed> what BookingManager::request() takes */
    public function booking(): array
    {
        return [
            'service_id' => $this->validated('service_id'),
            'professional_id' => $this->validated('professional_id') ?: null,
            'location_id' => $this->validated('location_id') ? (int) $this->validated('location_id') : null,
            'starts_at' => $this->startsAt(),
            'customer' => $this->customer(),
            'notes' => filled($this->validated('notes')) ? trim($this->validated('notes')) : null,
        ];
    }
}
