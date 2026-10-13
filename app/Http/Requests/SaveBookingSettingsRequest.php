<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The booking rules of the ACTIVE Workspace. Route middleware: `manage-services`. Whether the chosen agenda is really usable is checked by the controller. */
class SaveBookingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(array_keys(config('bookings.providers')))],
            'requires_confirmation' => ['required', 'boolean'],
            'min_notice_minutes' => ['required', 'integer', 'between:0,10080'],
            'max_advance_days' => ['required', 'integer', 'between:1,365'],
            'cancel_notice_hours' => ['required', 'integer', 'between:0,168'],
            'slot_step_minutes' => ['required', 'integer', Rule::in(config('bookings.steps'))],
            'require_phone' => ['required', 'boolean'],
            'require_email' => ['required', 'boolean'],
            'public_enabled' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'provider' => 'la agenda', 'min_notice_minutes' => 'la anticipación mínima', 'max_advance_days' => 'la anticipación máxima',
            'cancel_notice_hours' => 'el plazo de cancelación', 'slot_step_minutes' => 'el intervalo de horarios',
        ];
    }
}
