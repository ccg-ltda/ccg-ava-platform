<?php

namespace App\Http\Requests\Concerns;

use App\Campaigns\MessageTemplates;
use Illuminate\Validation\Validator;

/**
 * The rules of a message body shared by templates and campaigns, so the two can never disagree: the same normalisation of line breaks,
 * the same length limit of a WhatsApp template body (counted in characters, never silently cut) and the same check of the variable markers.
 */
trait ValidatesMessageBody
{
    /** Line breaks are one "\n" before anything is counted or stored. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => app(MessageTemplates::class)->normalize($this->input('body'))]);
        }
    }

    protected function validateMessageBody(Validator $validator): void
    {
        $body = (string) $this->input('body');
        $templates = app(MessageTemplates::class);

        if ($error = $templates->lengthError($body)) {
            $validator->errors()->add('body', $error);

            return;
        }

        if ($templates->hasMalformedMarkers($body)) {
            $validator->errors()->add('body', 'Revisa las llaves dobles: una variable se escribe como {{nombre}}. Ava no usa variables numeradas como {{1}} ni llaves sueltas.');

            return;
        }

        $unknown = $templates->unknown($body);

        if ($unknown !== []) {
            $validator->errors()->add('body', 'Variables que no existen: '.collect($unknown)->map(fn ($v) => '{{'.$v.'}}')->implode(', ').'. Usa: '.collect(array_keys(config('campaigns.variables')))->map(fn ($v) => '{{'.$v.'}}')->implode(', ').'.');
        }
    }
}
