<?php

namespace App\Booking;

use App\Models\Appointment;
use App\Models\BookingSetting;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * How appointments and free slots leave Ava (admin pages, public booking page, Ava Chat): one shape, times as UTC ISO plus a
 * ready-to-show label in the Workspace's own timezone and formats. The public view carries no contact data at all.
 */
class BookingPresenter
{
    /** @return array<string, mixed> */
    public function slot(Workspace $workspace, array $slot): array
    {
        $settings = $workspace->settingsOrDefault();
        $start = CarbonImmutable::instance($slot['starts_at']);

        return [
            'startsAt' => $start->utc()->toIso8601ZuluString(),
            'endsAt' => CarbonImmutable::instance($slot['ends_at'])->utc()->toIso8601ZuluString(),
            'date' => $start->setTimezone($settings->timezone)->toDateString(),
            'label' => $settings->formatTime($start),
            'professionalId' => $slot['professional_id'],
            'professionalName' => $slot['professional_name'],
        ];
    }

    /** @return array<string, mixed> for staff (includes who booked) */
    public function appointment(Appointment $appointment): array
    {
        $settings = $appointment->workspace->settingsOrDefault();
        $start = CarbonImmutable::instance($appointment->starts_at);

        return [
            'id' => $appointment->id,
            'service' => $appointment->service_name,
            'serviceId' => $appointment->service_id,
            'professional' => $appointment->professional_name,
            'professionalId' => $appointment->professional_id,
            'location' => $appointment->location_name,
            'locationId' => $appointment->location_id,
            // The price recorded WHEN it was booked (null = none was recorded: a free or unpriced service, or a booking from before prices were recorded).
            'price' => $appointment->price_amount === null ? null : (float) $appointment->price_amount,
            'currency' => $appointment->price_currency,
            'startsAt' => $start->utc()->toIso8601ZuluString(),
            'endsAt' => CarbonImmutable::instance($appointment->ends_at)->utc()->toIso8601ZuluString(),
            'date' => $start->setTimezone($settings->timezone)->toDateString(),
            'dateLabel' => $settings->formatDate($start),
            'timeLabel' => $settings->formatTime($start),
            // For the calendar grid: minutes since LOCAL midnight (Workspace time zone), the length, and the rest after it (`blocks_until`) the service reserves.
            'startMinute' => (int) $start->setTimezone($settings->timezone)->format('G') * 60 + (int) $start->setTimezone($settings->timezone)->format('i'),
            'durationMinutes' => max(0, (int) $start->diffInMinutes(CarbonImmutable::instance($appointment->ends_at), false)),
            'restMinutes' => $appointment->blocks_until ? max(0, (int) CarbonImmutable::instance($appointment->ends_at)->diffInMinutes(CarbonImmutable::instance($appointment->blocks_until), false)) : 0,
            'status' => $appointment->status,
            'statusLabel' => Appointment::LABELS[$appointment->status],
            'provider' => $appointment->provider,
            'external' => $appointment->provider !== 'internal',
            'syncStatus' => $appointment->sync_status,
            'syncError' => $appointment->sync_error,
            'channel' => $appointment->channel,
            'originLabel' => $this->origin($appointment),
            'customerConfirmed' => $appointment->customer_confirmed_at !== null,
            'customerConfirmedAt' => $appointment->customer_confirmed_at?->utc()->toIso8601ZuluString(),
            'confirmationSource' => $appointment->customer_confirmation_source,
            'notifications' => $appointment->relationLoaded('notifications') ? app(AppointmentNotifier::class)->summary($appointment, true) : [],
            'notes' => $appointment->notes,
            'cancelReason' => $appointment->cancel_reason,
            'customer' => $appointment->customer ? ['name' => $appointment->customer->name, 'phone' => $appointment->customer->phone, 'email' => $appointment->customer->email] : null,
            'active' => $appointment->isActive(),
        ];
    }

    /**
     * Where the booking came from, only as far as the data proves it: staff, the booking page, or the assistant (and the conversation channel it
     * was credited to when it came from a conversation of this Workspace).
     */
    public function origin(Appointment $appointment): string
    {
        return match ($appointment->channel) {
            'admin' => 'Agenda del negocio',
            'web' => 'Página de reservas',
            default => 'Asistente'.(match ($appointment->origin_channel) {
                'whatsapp' => ' · WhatsApp', 'web' => ' · Chat web', default => ''
            }),
        };
    }

    /** @return array<string, mixed> what the customer who owns the link may see: nothing about anyone else */
    public function publicAppointment(Appointment $appointment): array
    {
        $settings = $appointment->workspace->settingsOrDefault();
        $start = CarbonImmutable::instance($appointment->starts_at);
        $deadline = now()->addHours(BookingSetting::for($appointment->workspace)->cancel_notice_hours);

        return [
            'service' => $appointment->service_name,
            'serviceId' => $appointment->service_id,
            'professional' => $appointment->professional_name,
            'professionalId' => $appointment->professional_id,
            'location' => $appointment->location_name,
            'price' => $appointment->price_amount === null ? null : (float) $appointment->price_amount,
            'currency' => $appointment->price_currency,
            'startsAt' => $start->utc()->toIso8601ZuluString(),
            'dateLabel' => $settings->formatDate($start),
            'timeLabel' => $settings->formatTime($start),
            'status' => $appointment->status,
            'statusLabel' => Appointment::LABELS[$appointment->status],
            'customerConfirmed' => $appointment->customer_confirmed_at !== null,
            'canConfirm' => $appointment->isActive() && $appointment->provider === 'internal' && $appointment->customer_confirmed_at === null && $appointment->starts_at->isFuture(),
            'canChange' => $appointment->isActive() && $appointment->provider === 'internal' && $appointment->starts_at->gte($deadline),
            'canCancel' => $appointment->isActive() && $appointment->starts_at->gte($deadline),
        ];
    }
}
