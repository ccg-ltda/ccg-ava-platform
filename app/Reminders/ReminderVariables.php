<?php

namespace App\Reminders;

use App\Campaigns\MessageTemplates;
use App\Models\Appointment;
use App\Models\Customer;

/**
 * The variables a reminder message may use and how each is filled when it goes out. Every reminder has the CUSTOMER's data (`config/campaigns.php`: name, last name, full name, phone,
 * e-mail). A reminder an automatic rule scheduled also has the data of the APPOINTMENT that triggered it (`config/reminders.php` `appointment_variables`: service, professional, date,
 * time, location): those can only be resolved for a reminder tied to an appointment, so a manual reminder neither offers nor accepts them (it would send an empty hole). A value that is
 * missing stays visible as its own marker, never a silent blank.
 */
class ReminderVariables
{
    public function __construct(private readonly MessageTemplates $templates) {}

    /** @return list<array{key: string, label: string}> what the editor offers: the customer's data and, for a rule, the appointment's */
    public function offered(bool $withAppointment): array
    {
        $customer = collect(config('campaigns.variables'))->map(fn (array $variable, string $key) => ['key' => $key, 'label' => $variable['label']]);
        $appointment = $withAppointment ? collect(config('reminders.appointment_variables'))->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label]) : collect();

        return $customer->merge($appointment)->values()->all();
    }

    /** @return list<string> markers of the body that this kind of reminder cannot resolve */
    public function unknown(string $body, bool $withAppointment): array
    {
        $allowed = array_column($this->offered($withAppointment), 'key');

        return array_values(array_diff($this->templates->used($body), $allowed));
    }

    /** The value of a variable for this customer and (when there is one) this appointment, or null when that data is missing. */
    public function valueFor(Customer $customer, string $variable, ?Appointment $appointment): ?string
    {
        if (array_key_exists($variable, config('reminders.appointment_variables'))) {
            return $appointment ? $this->appointmentValue($appointment, $variable) : null;
        }

        return $this->templates->valueFor($customer, $variable);
    }

    /** The body for one customer and appointment. */
    public function render(string $body, Customer $customer, ?Appointment $appointment): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z_]+)\s*\}\}/', fn (array $match) => $this->valueFor($customer, $match[1], $appointment) ?? $match[0], $body);
    }

    private function appointmentValue(Appointment $appointment, string $variable): ?string
    {
        $settings = $appointment->workspace?->settingsOrDefault();
        $value = match ($variable) {
            'servicio' => $appointment->service_name,
            'profesional' => $appointment->professional_name,
            'sede' => $appointment->location_name,
            'fecha' => $settings?->formatDate($appointment->starts_at),
            'hora' => $settings?->formatTime($appointment->starts_at),
            default => null,
        };

        return filled($value) ? trim((string) $value) : null;
    }
}
