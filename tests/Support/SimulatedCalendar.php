<?php

namespace Tests\Support;

use App\Booking\BookingRequest;
use App\Booking\CalendarProvider;
use App\Booking\ProviderBooking;
use App\Booking\ProviderException;
use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * A SIMULATED external agenda for the tests: it is NOT Booker and proves nothing about Booker. It lets the tests exercise the
 * external-agenda paths of Ava (sync states, refusals, conflicts, unreachable provider) deterministically, and runs the same
 * contract checks as Ava's own agenda. `failWith` makes the next operation of a kind fail with a given ProviderException code.
 */
class SimulatedCalendar implements CalendarProvider
{
    /** @var array<string, string> operation => ProviderException code */
    public array $failWith = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, string> external id => status */
    public array $bookings = [];

    public string $bookStatus = Appointment::CONFIRMED;

    public function __construct(public array $capabilities = ['services' => true, 'professionals' => true, 'availability' => true, 'book' => true, 'reschedule' => true, 'cancel' => true, 'find' => true]) {}

    public function key(): string
    {
        return 'booker';
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function services(): array
    {
        $this->enter('services');

        return [['id' => 'S1', 'name' => 'Manicure externa', 'description' => null, 'duration_minutes' => 45, 'price' => 30.0, 'category' => 'Uñas']];
    }

    public function professionals(int|string|null $serviceId = null): array
    {
        $this->enter('professionals');

        return [['id' => 'P1', 'name' => 'Ana Externa', 'specialty' => null]];
    }

    public function availability(int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array
    {
        $this->enter('availability');
        $start = $from->addDay()->setTime(10, 0);

        return [['starts_at' => $start, 'ends_at' => $start->addMinutes(45), 'professional_id' => 'P1', 'professional_name' => 'Ana Externa']];
    }

    public function book(BookingRequest $request): ProviderBooking
    {
        $this->enter('book');
        $id = (string) (1000 + count($this->bookings));
        $this->bookings[$id] = $this->bookStatus;

        return new ProviderBooking($id, $this->bookStatus);
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId): ProviderBooking
    {
        $this->enter('reschedule');

        return new ProviderBooking((string) $appointment->external_id, Appointment::CONFIRMED, $startsAt, $startsAt->addMinutes(45));
    }

    public function cancel(Appointment $appointment): void
    {
        $this->enter('cancel');
        $this->bookings[(string) $appointment->external_id] = Appointment::CANCELLED;
    }

    public function find(Appointment $appointment): ?ProviderBooking
    {
        $this->enter('find');
        $status = $this->bookings[(string) $appointment->external_id] ?? null;

        return $status ? new ProviderBooking((string) $appointment->external_id, $status) : null;
    }

    private function enter(string $operation): void
    {
        $this->calls[] = $operation;

        if (isset($this->failWith[$operation])) {
            throw new ProviderException($this->failWith[$operation], in_array($this->failWith[$operation], [ProviderException::TIMEOUT, ProviderException::UNAVAILABLE, ProviderException::RATE_LIMITED], true));
        }
    }
}
