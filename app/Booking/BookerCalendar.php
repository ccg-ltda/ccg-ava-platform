<?php

namespace App\Booking;

use App\Integrations\BookerClient;
use App\Models\Appointment;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Booker as an external agenda: services, professionals and appointments live in Booker, which stays the source of truth.
 *
 * Only what Booker's open-source Ruby client documents with BOTH request and response is implemented (see BookerClient):
 * services (treatments), professionals (employees), reading and cancelling an appointment. Availability, booking and
 * rescheduling are declared unsupported: the availability response of Booker (`/v5/availability/availability`) has a nested
 * shape that no verifiable source describes, and a booking needs one of its time slots, so inventing either would be guessing.
 * Ava therefore never offers a Booker slot nor reports a Booker booking as made until those are verified against a Sandbox.
 */
class BookerCalendar implements CalendarProvider
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 5;

    public function __construct(private readonly Integration $integration, private readonly BookerClient $client) {}

    public function key(): string
    {
        return 'booker';
    }

    public function capabilities(): array
    {
        return ['services' => true, 'professionals' => true, 'availability' => false, 'book' => false, 'reschedule' => false, 'cancel' => true, 'find' => true];
    }

    public function services(): array
    {
        return array_values(array_map(fn (array $treatment) => [
            'id' => (string) $treatment['ID'],
            'name' => (string) ($treatment['Name'] ?? ''),
            'description' => filled($treatment['Description'] ?? null) ? (string) $treatment['Description'] : null,
            'duration_minutes' => isset($treatment['TotalDuration']) ? (int) $treatment['TotalDuration'] : (isset($treatment['TreatmentDuration']) ? (int) $treatment['TreatmentDuration'] : null),
            'price' => $this->price($treatment['Price'] ?? null),
            'category' => filled($treatment['Category'] ?? null) ? (string) (is_array($treatment['Category']) ? ($treatment['Category']['Name'] ?? '') : $treatment['Category']) : null,
        ], $this->treatments()));
    }

    public function professionals(int|string|null $serviceId = null): array
    {
        $allowed = null;

        if ($serviceId !== null) {
            $treatment = collect($this->treatments())->first(fn (array $item) => (string) $item['ID'] === (string) $serviceId);
            $allowed = collect($treatment['EmployeeIDs'] ?? [])->map(fn ($id) => (string) $id)->all();
        }

        return collect($this->paged('/v4.1/customer/employees', 'Employees'))
            ->filter(fn ($employee) => is_array($employee) && isset($employee['ID']) && ($allowed === null || $allowed === [] || in_array((string) $employee['ID'], $allowed, true)))
            ->map(fn (array $employee) => ['id' => (string) $employee['ID'], 'name' => trim(($employee['FirstName'] ?? '').' '.($employee['LastName'] ?? '')), 'specialty' => null])
            ->values()->all();
    }

    public function availability(int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array
    {
        throw ProviderException::unsupported();
    }

    public function book(BookingRequest $request): ProviderBooking
    {
        throw ProviderException::unsupported();
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId): ProviderBooking
    {
        throw ProviderException::unsupported();
    }

    public function cancel(Appointment $appointment): void
    {
        $id = $this->externalId($appointment);
        $this->client->put('/v4.1/customer/appointment/cancel', ['ID' => $id]);
    }

    public function find(Appointment $appointment): ?ProviderBooking
    {
        try {
            $json = $this->client->get('/v4.1/customer/appointment/'.$this->externalId($appointment));
        } catch (ProviderException $exception) {
            if ($exception->codeName === ProviderException::INVALID) {
                return null; // Booker no longer knows that appointment
            }

            throw $exception;
        }

        $data = is_array($json['Appointment'] ?? null) ? $json['Appointment'] : $json;

        if (! isset($data['ID'])) {
            return null;
        }

        // Only attributes the client documents on an appointment: cancelled wins, a held slot is not confirmed yet.
        $status = match (true) {
            (bool) ($data['IsCancelled'] ?? false) => Appointment::CANCELLED,
            (bool) ($data['IsHeld'] ?? false) => Appointment::REQUESTED,
            default => Appointment::CONFIRMED,
        };

        return new ProviderBooking((string) $data['ID'], $status);
    }

    private function externalId(Appointment $appointment): int
    {
        if (! ctype_digit((string) $appointment->external_id)) {
            throw ProviderException::invalid();
        }

        return (int) $appointment->external_id;
    }

    /** Treatments a customer may book online (classes and inactive ones are left out), cached briefly to spare the provider. @return list<array<string, mixed>> */
    private function treatments(): array
    {
        return Cache::remember("booker:treatments:{$this->integration->getKey()}", 60, fn () => collect($this->paged('/v4.1/customer/treatments', 'Treatments'))
            ->filter(fn ($item) => is_array($item) && isset($item['ID'])
                && ! ($item['IsClass'] ?? false) && ($item['IsActive'] ?? true) && ($item['AllowCustomersToBookOnline'] ?? true))
            ->values()->all());
    }

    /** Every page of a paginated Booker list for the configured location. @return list<mixed> */
    private function paged(string $path, string $resultsKey): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $json = $this->client->post($path, ['LocationID' => (int) $this->integration->config['location_id'], 'UsePaging' => true, 'PageSize' => self::PAGE_SIZE, 'PageNumber' => $page], retry: true);
            $results = $json['Results'] ?? $json[$resultsKey] ?? (array_is_list($json) ? $json : []);

            if (! is_array($results) || $results === []) {
                break;
            }

            $items = array_merge($items, $results);

            if (count($results) < self::PAGE_SIZE) {
                break;
            }
        }

        return $items;
    }

    private function price(mixed $price): ?float
    {
        $amount = is_array($price) ? ($price['Amount'] ?? null) : $price;

        return is_numeric($amount) ? (float) $amount : null;
    }
}
