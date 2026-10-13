<?php

namespace App\Booking;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * The agenda a Workspace chose but cannot use right now (the provider is not connected, was switched off, or the plan no
 * longer includes it). It fails closed: nothing is read from, or written to, another agenda instead, so the two sources are
 * never mixed silently.
 */
class UnavailableCalendar implements CalendarProvider
{
    public function __construct(private readonly string $provider) {}

    public function key(): string
    {
        return $this->provider;
    }

    public function capabilities(): array
    {
        return ['services' => false, 'professionals' => false, 'availability' => false, 'book' => false, 'reschedule' => false, 'cancel' => false, 'find' => false];
    }

    public function services(): array
    {
        throw ProviderException::unavailable();
    }

    public function professionals(int|string|null $serviceId = null): array
    {
        throw ProviderException::unavailable();
    }

    public function availability(int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array
    {
        throw ProviderException::unavailable();
    }

    public function book(BookingRequest $request): ProviderBooking
    {
        throw ProviderException::unavailable();
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId): ProviderBooking
    {
        throw ProviderException::unavailable();
    }

    public function cancel(Appointment $appointment): void
    {
        throw ProviderException::unavailable();
    }

    public function find(Appointment $appointment): ?ProviderBooking
    {
        throw ProviderException::unavailable();
    }
}
