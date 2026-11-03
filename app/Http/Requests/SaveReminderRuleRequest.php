<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or edits an automatic reminder rule of the ACTIVE Workspace (taken from the request context, never from the client). Authorization is the route's middleware.
 * Shape only: that the services, the channel and the message are usable by THIS Workspace is judged by ReminderRuleService.
 */
class SaveReminderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $limits = config('reminders.limits');

        return [
            'name' => ['required', 'string', 'max:'.config('reminders.rules.name_max')],
            'service_ids' => ['required', 'array', 'min:1', 'max:100'],
            'service_ids.*' => ['integer', 'min:1'],
            'delay_amount' => ['required', 'integer', 'min:1'],
            'delay_unit' => ['required', 'string', Rule::in(array_keys(config('reminders.rules.delay_units')))],
            'send_time' => ['nullable', 'date_format:H:i'],
            'channel' => ['required', 'string', Rule::in(array_keys(config('reminders.channels')))],
            'subject' => ['nullable', 'string', 'max:'.$limits['subject_max']],
            'body' => ['required', 'string', 'max:'.$limits['body_max']],
            'template_name' => ['nullable', 'string', 'max:120'],
            'template_language' => ['nullable', 'string', 'max:12'],
            'template_params' => ['nullable', 'array', 'max:10'],
            'template_params.*' => ['string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'service_ids' => 'servicios', 'delay_amount' => 'espera', 'delay_unit' => 'unidad de espera', 'send_time' => 'hora de envío', 'channel' => 'canal', 'subject' => 'asunto', 'body' => 'mensaje'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'service_ids.required' => 'Elige al menos un servicio.',
            'service_ids.min' => 'Elige al menos un servicio.',
            'delay_amount.required' => 'Indica cuánto esperar después del servicio.',
            'body.required' => 'El mensaje es obligatorio.',
        ];
    }
}
