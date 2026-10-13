<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BookingInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** A new time (and optionally another professional) for an existing appointment: admin agenda, public link or Ava Chat. */
class RescheduleAppointmentRequest extends FormRequest
{
    use BookingInput;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return $this->slotRules();
    }

    public function attributes(): array
    {
        return ['starts_at' => 'la fecha y hora'];
    }

    public function professionalId(): int|string|null
    {
        return $this->validated('professional_id') ?: null;
    }
}
