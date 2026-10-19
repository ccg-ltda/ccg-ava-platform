<?php

namespace App\Http\Requests;

use App\Booking\AppointmentNotifier;
use App\Booking\BookingMessageComposer;
use App\Models\BookingSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The confirmations and reminders of the ACTIVE Workspace (one shared capability: the same fields for a clinic and a salon). Route middleware:
 * `manage-booking-settings`. Nothing is assumed: no anticipation, text or template exists until the Workspace writes it. What the Workspace cannot do yet
 * (no WhatsApp account, an external agenda) is refused here instead of letting a switch look on while nothing would be sent.
 */
class SaveBookingNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $workspace = $this->attributes->get('workspace');
        $limits = config('bookings.notifications.offsets');
        $rules = [
            'notify_received' => ['required', 'boolean'],
            'notify_confirmed' => ['required', 'boolean'],
            'reminders_enabled' => ['required', 'boolean'],
            'reminder_offsets' => ['nullable', 'array', 'max:'.$limits['max_count']],
            'reminder_offsets.*' => ['integer', 'distinct', "between:{$limits['min']},{$limits['max']}"],
            'notify_integration_id' => ['nullable', 'integer', Rule::exists('integrations', 'id')->where('workspace_id', $workspace->id)->where('type', 'whatsapp')->where('is_active', true)],
            'messages' => ['nullable', 'array'],
        ];

        foreach (config('bookings.notifications.kinds') as $kind) {
            $rules["messages.{$kind}"] = ['nullable', 'array'];
            $rules["messages.{$kind}.text"] = ['nullable', 'string', 'max:'.config('bookings.notifications.text_max')];
            $rules["messages.{$kind}.template"] = ['nullable', 'array'];
            $rules["messages.{$kind}.template.name"] = ['nullable', 'string', 'regex:/^[a-z0-9_]{1,512}$/'];
            $rules["messages.{$kind}.template.language"] = ['nullable', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'];
            $rules["messages.{$kind}.template.params"] = ['nullable', 'array', 'max:10'];
            $rules["messages.{$kind}.template.params.*"] = ['string', Rule::in(config('bookings.notifications.variables'))];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $workspace = $this->attributes->get('workspace');
            $current = BookingSetting::for($workspace);
            $any = $this->boolean('notify_received') || $this->boolean('notify_confirmed') || $this->boolean('reminders_enabled');

            if ($this->boolean('reminders_enabled') && count((array) $this->input('reminder_offsets', [])) === 0) {
                $validator->errors()->add('reminder_offsets', 'Indica al menos una anticipación para los recordatorios (por ejemplo, 1440 minutos = 1 día antes).');
            }

            // Turning something ON needs a way to send it: a switch that looks active while nothing can leave is a lie.
            $turningOn = ($this->boolean('notify_received') && ! $current->notify_received) || ($this->boolean('notify_confirmed') && ! $current->notify_confirmed) || ($this->boolean('reminders_enabled') && ! $current->reminders_enabled);

            if ($any && $current->provider !== 'internal') {
                $validator->errors()->add('reminders_enabled', 'Los avisos solo funcionan con la agenda de Ava: con una agenda externa esa agenda es quien confirma y recuerda.');
            } elseif ($turningOn && ! app(AppointmentNotifier::class)->channel($workspace, $this->input('notify_integration_id'))['ok']) {
                $validator->errors()->add('reminders_enabled', 'Antes de activar los avisos conecta una cuenta de WhatsApp activa en Integraciones (el plan debe incluir Integraciones y, si hay varias cuentas, elige cuál usar).');
            }

            foreach (config('bookings.notifications.kinds') as $kind) {
                $text = (string) $this->input("messages.{$kind}.text", '');
                $name = $this->input("messages.{$kind}.template.name");
                $language = $this->input("messages.{$kind}.template.language");

                if (filled($name) !== filled($language)) {
                    $validator->errors()->add("messages.{$kind}.template.name", 'Una plantilla de Meta necesita su nombre y su idioma.');
                }

                if ($text !== '' && ($problem = $this->textProblem($text))) {
                    $validator->errors()->add("messages.{$kind}.text", $problem);
                }
            }
        }];
    }

    /** Only the variables of config/bookings.php, written as {{nombre}}; a stray or numbered marker is refused rather than sent. */
    private function textProblem(string $text): ?string
    {
        $unknown = collect();
        preg_match_all(BookingMessageComposer::PATTERN, $text, $found);

        foreach ($found[1] as $variable) {
            if (! in_array($variable, config('bookings.notifications.variables'), true)) {
                $unknown->push($variable);
            }
        }

        if ($unknown->isNotEmpty()) {
            return 'Variables que no existen: '.$unknown->unique()->map(fn ($v) => '{{'.$v.'}}')->implode(', ').'. Usa: '.collect(config('bookings.notifications.variables'))->map(fn ($v) => '{{'.$v.'}}')->implode(', ').'.';
        }

        $rest = preg_replace(BookingMessageComposer::PATTERN, '', $text);

        return str_contains($rest, '{{') || str_contains($rest, '}}') ? 'Hay llaves dobles mal escritas: una variable se escribe {{nombre}}.' : null;
    }

    public function attributes(): array
    {
        return ['reminder_offsets' => 'las anticipaciones', 'reminder_offsets.*' => 'la anticipación', 'notify_integration_id' => 'la cuenta de WhatsApp', 'messages.*.text' => 'el texto', 'messages.*.template.name' => 'el nombre de la plantilla', 'messages.*.template.language' => 'el idioma', 'messages.*.template.params.*' => 'la variable'];
    }

    /** @return array<string, mixed> the columns to store */
    public function settings(): array
    {
        $offsets = array_values(array_unique(array_map('intval', (array) $this->validated('reminder_offsets', []))));
        rsort($offsets);
        $messages = [];

        foreach (config('bookings.notifications.kinds') as $kind) {
            $text = trim((string) $this->validated("messages.{$kind}.text", ''));
            $name = trim((string) $this->validated("messages.{$kind}.template.name", ''));
            $entry = array_filter([
                'text' => $text !== '' ? $text : null,
                'template' => $name !== '' ? ['name' => $name, 'language' => (string) $this->validated("messages.{$kind}.template.language"), 'params' => array_values((array) $this->validated("messages.{$kind}.template.params", []))] : null,
            ]);

            if ($entry !== []) {
                $messages[$kind] = $entry;
            }
        }

        return [
            'notify_received' => $this->boolean('notify_received'), 'notify_confirmed' => $this->boolean('notify_confirmed'), 'reminders_enabled' => $this->boolean('reminders_enabled'),
            'reminder_offsets' => $offsets === [] ? null : $offsets, 'notify_integration_id' => $this->validated('notify_integration_id') ?: null, 'notify_messages' => $messages === [] ? null : $messages,
        ];
    }
}
