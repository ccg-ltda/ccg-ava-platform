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
            'startsAt' => $start->utc()->toIso8601ZuluString(),
            'endsAt' => CarbonImmutable::instance($appointment->ends_at)->utc()->toIso8601ZuluString(),
            'date' => $start->setTimezone($settings->timezone)->toDateString(),
            'dateLabel' => $settings->formatDate($start),
            'timeLabel' => $settings->formatTime($start),
            'status' => $appointment->status,
            'statusLabel' => Appointment::LABELS[$appointment->status],
            'provider' => $appointment->provider,
            'external' => $appointment->provider !== 'internal',
            'syncStatus' => $appointment->sync_status,
            'syncError' => $appointment->sync_error,
            'channel' => $appointment->channel,
            'notes' => $appointment->notes,
            'cancelReason' => $appointment->cancel_reason,
            'customer' => $appointment->customer ? ['name' => $appointment->customer->name, 'phone' => $appointment->customer->phone, 'email' => $appointment->customer->email] : null,
            'active' => $appointment->isActive(),
        ];
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
            'startsAt' => $start->utc()->toIso8601ZuluString(),
            'dateLabel' => $settings->formatDate($start),
            'timeLabel' => $settings->formatTime($start),
            'status' => $appointment->status,
            'statusLabel' => Appointment::LABELS[$appointment->status],
            'canChange' => $appointment->isActive() && $appointment->provider === 'internal' && $appointment->starts_at->gte($deadline),
            'canCancel' => $appointment->isActive() && $appointment->starts_at->gte($deadline),
        ];
    }
}
