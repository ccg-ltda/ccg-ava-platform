<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or edits a reminder of the ACTIVE Workspace (taken from the request context, never from the client). Authorization is the route's `workspace.permission` middleware.
 * Shape only: that the customer, the related record and the channel are usable by THIS Workspace is judged by ReminderService (the same rules serve the agent API).
 */
class SaveReminderRequest extends FormRequest
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
            'customer_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:'.$limits['name_max']],
            'purpose' => ['nullable', 'string', 'max:'.$limits['purpose_max']],
            'related_type' => ['nullable', 'string', Rule::in(array_keys(config('reminders.related_types')))],
            'related_id' => ['nullable', 'required_with:related_type', 'integer', 'min:1'],
            'channel' => ['required', 'string', Rule::in(array_keys(config('reminders.channels')))],
            'subject' => ['nullable', 'string', 'max:'.$limits['subject_max']],
            'body' => ['required', 'string', 'max:'.$limits['body_max']],
            'template_name' => ['nullable', 'string', 'max:120'],
            'template_language' => ['nullable', 'string', 'max:12'],
            'template_params' => ['nullable', 'array', 'max:10'],
            'template_params.*' => ['string', 'max:40'],
            'send_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['customer_id' => 'cliente', 'name' => 'nombre', 'purpose' => 'finalidad', 'channel' => 'canal', 'subject' => 'asunto', 'body' => 'mensaje', 'send_at' => 'fecha y hora', 'related_id' => 'registro relacionado'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'send_at.date_format' => 'Elige la fecha y la hora del envío.',
            'send_at.required' => 'Elige la fecha y la hora del envío.',
            'name.required' => 'El nombre es obligatorio.',
            'customer_id.required' => 'Elige un cliente.',
            'body.required' => 'El mensaje es obligatorio.',
        ];
    }
}
