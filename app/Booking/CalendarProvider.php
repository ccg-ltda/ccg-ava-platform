<?php

namespace App\Booking;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * The common contract of an agenda: Ava's own (`InternalCalendar`) or an external one (`BookerCalendar`). Ava talks to every
 * agenda through it, so the admin pages, the public booking page and Ava Chat never know which one is behind.
 *
 * Ids are the agenda's own (strings for an external one). Times are always UTC `CarbonImmutable`. An operation the agenda
 * cannot do is declared false in `capabilities()` and throws `ProviderException::unsupported()` if called: it is never faked.
 * An external agenda stays the source of truth: Ava never reports as confirmed what that agenda has not confirmed.
 */
interface CalendarProvider
{
    /** `internal` or the key of the provider (config/bookings.php `providers`). */
    public function key(): string;

    /**
     * @return array{services: bool, professionals: bool, availability: bool, book: bool, reschedule: bool, cancel: bool, find: bool}
     */
    public function capabilities(): array;

    /** @return list<array{id: int|string, name: string, description: ?string, duration_minutes: ?int, price: ?float, category: ?string}> */
    public function services(): array;

    /** @return list<array{id: int|string, name: string, specialty: ?string}> */
    public function professionals(int|string|null $serviceId = null): array;

    /**
     * Free starts for a service between two UTC moments. One entry per professional and start. `$ignoreAppointment` is an
     * appointment that is being moved: the time it holds counts as free for it (an agenda that cannot tell ignores it).
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, professional_id: int|string|null, professional_name: ?string}>
     *
     * @throws ProviderException
     */
    public function availability(int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array;

    /**
     * Books the slot. Returns what the agenda really did: `confirmed`, or `requested` when it still has to be confirmed there.
     *
     * @throws ProviderException
     */
    public function book(BookingRequest $request): ProviderBooking;

    /** @throws ProviderException */
    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId): ProviderBooking;

    /** @throws ProviderException */
    public function cancel(Appointment $appointment): void;

    /** The agenda's current view of an appointment, or null when it no longer knows it. @throws ProviderException */
    public function find(Appointment $appointment): ?ProviderBooking;
}
