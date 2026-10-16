<?php

namespace App\Booking;

use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Fills the text of a notification (or the parameters of a Meta template) with data of ONE appointment, in the Workspace's own timezone and formats.
 * The only variables are those of config/bookings.php: none is clinical, and nothing else of the customer or the appointment (notes, price,
 * contact data) can reach a message.
 */
class BookingMessageComposer
{
    public const PATTERN = '/\{\{\s*([A-Za-z_]+)\s*\}\}/';

    public function __construct(private readonly Workspace $workspace) {}

    /** The text to send: the Workspace's own, or the default of the kind. */
    public function text(Appointment $appointment, ?string $custom, string $kind): string
    {
        $body = filled($custom) ? $custom : (string) config("bookings.notifications.default_texts.{$kind}");
        $values = $this->values($appointment);
        $text = preg_replace_callback(self::PATTERN, fn (array $match) => $values[$match[1]] ?? '', $body);

        return trim(preg_replace('/[ \t]{2,}/', ' ', (string) $text));
    }

    /**
     * The body parameters of a template, in the order the Workspace declared. Meta refuses an empty parameter, so a missing value is a dash.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function parameters(Appointment $appointment, array $keys): array
    {
        $values = $this->values($appointment);

        return array_map(fn (string $key) => ($values[$key] ?? '') !== '' ? $values[$key] : '-', $keys);
    }

    /** @return array<string, string> */
    private function values(Appointment $appointment): array
    {
        $settings = $this->workspace->settingsOrDefault();
        $start = CarbonImmutable::instance($appointment->starts_at);
        $name = trim((string) $appointment->customer?->name);

        return [
            'nombre' => $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? $name),
            'servicio' => (string) $appointment->service_name,
            'profesional' => (string) $appointment->professional_name,
            'sede' => (string) $appointment->location_name,
            'fecha' => $settings->formatDate($start),
            'hora' => $settings->formatTime($start),
            'negocio' => (string) $this->workspace->name,
        ];
    }
}
