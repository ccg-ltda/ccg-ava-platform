<?php

namespace App\Booking;

use App\Integrations\BookerClient;
use App\Models\Appointment;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Booker's Merchant API as an external agenda, for the operations Booker's support confirmed: treatments, customer search, availability
 * (1 day) and appointment creation (`integrations.booker.merchant.paths`). Booker stays the source of truth.
 *
 * WHAT IS CONFIRMED and what is not. Confirmed (support): the four paths, the flow (cache treatments, read availability right before
 * booking, find the customer by email or phone, create the appointment with the slot exactly as availability returned it), and that a booking
 * needs `LocationID`, `ItineraryTimeSlotList`, the treatment and time data, `Customer` and the access token (`EmployeeID` when it applies).
 * NOT confirmed (assumed here, to be checked in Staging, and the reason this class is the ONLY place that knows these shapes):
 *   (A1) the availability request: `LocationID`, `StartDateTime` (local day) and `Itineraries[].Treatments[]` with `TreatmentID`/`EmployeeID`;
 *   (A2) the availability response: `ItineraryTimeSlotLists[]`, each with its slots under `Slots` (or `SlotList`), every slot carrying
 *        `StartDateTime` and `TreatmentTimeSlots[]` (`TreatmentID`, `EmployeeID`);
 *   (A3) the create request wraps ONE of those lists, reduced to the chosen slot, as `ItineraryTimeSlotList`, and `Customer` is `{ID}` or a new
 *        customer `{FirstName, LastName, Email, CellPhone}`; the response carries the appointment id as `Appointment.ID`, `AppointmentID` or `ID`;
 *   (A4) the customer search filters by `Email` or `PhoneNumber` and answers `Customers` or `Results`.
 * A response that does not look like (A2)/(A3) is never guessed: it is an error (`unknown` is uncertain for a write, so the appointment is kept
 * as "sin confirmar" and never repeated). `StartDateTime` is kept exactly as Booker sent it (the slot is passed back untouched); only a parsed
 * copy is used to compare and to show.
 *
 * Without a confirmed endpoint Ava does NOT invent one: professionals, cancelling, rescheduling and reading an appointment are declared
 * unsupported here (the Ruby-client customer endpoints of BookerCalendar are a different API and are not used with this token).
 * Availability and booking are offered for a Workspace pointed at Staging, and for production only when `merchant.verified` is on.
 */
class BookerMerchantCalendar implements LocationAwareCalendar
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 5;

    private const MAX_DAYS = 7;

    public function __construct(private readonly Integration $integration, private readonly BookerClient $client, private readonly ?string $boundLocation = null) {}

    public function key(): string
    {
        return 'booker';
    }

    public function capabilities(): array
    {
        $live = $this->live();

        return ['services' => true, 'professionals' => false, 'availability' => $live, 'book' => $live, 'reschedule' => false, 'cancel' => false, 'find' => false];
    }

    /** Staging is where this API is being verified; production waits for `merchant.verified`. */
    private function live(): bool
    {
        return ($this->integration->config['environment'] ?? 'staging') === 'staging' || (bool) config('integrations.booker.merchant.verified');
    }

    public function locations(): array
    {
        $configured = $this->integration->config['locations'] ?? null;

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(fn (array $location) => ['id' => (string) $location['id'], 'name' => (string) $location['name']], $configured));
        }

        return [['id' => (string) $this->integration->config['location_id'], 'name' => 'Sede principal']];
    }

    public function forLocation(int|string|null $locationId): static
    {
        if ($locationId === null || $locationId === '') {
            return new static($this->integration, $this->client, null);
        }

        if (! collect($this->locations())->contains(fn (array $location) => $location['id'] === (string) $locationId)) {
            throw ProviderException::invalid();
        }

        return new static($this->integration, $this->client, (string) $locationId);
    }

    public function location(): array
    {
        $locations = $this->locations();

        return collect($locations)->first(fn (array $location) => $location['id'] === $this->boundLocation) ?? $locations[0];
    }

    public function services(): array
    {
        return array_values(array_map(BookerCalendar::serviceOf(...), $this->treatments()));
    }

    public function professionals(int|string|null $serviceId = null): array
    {
        return []; // no confirmed endpoint lists the employees: a slot names its employee, and Ava shows no name it cannot vouch for
    }

    public function availability(int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array
    {
        $slots = [];
        $timezone = $this->timezone();
        $day = $from->setTimezone($timezone)->startOfDay();
        $last = $to->setTimezone($timezone);
        $duration = $this->durationOf((string) $serviceId);

        for ($i = 0; $i < self::MAX_DAYS && $day->lt($last); $i++, $day = $day->addDay()) {
            foreach ($this->daySlots((string) $serviceId, $professionalId === null ? null : (string) $professionalId, $day) as $slot) {
                if ($slot['starts']->gte($from) && $slot['starts']->lt($to)) {
                    $slots[] = ['starts_at' => $slot['starts'], 'ends_at' => $slot['ends'] ?? $slot['starts']->addMinutes($duration), 'professional_id' => $slot['employee'], 'professional_name' => null];
                }
            }
        }

        usort($slots, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /**
     * The recommended flow, in this order: availability read NOW for the chosen day, the slot found among what Booker returned (a start Ava
     * never offered, or one that is gone, is a conflict), the customer found or described (no duplicate when Booker knows them), then ONE create
     * call that is never repeated. Success is only a 2xx that returns the appointment id.
     */
    public function book(BookingRequest $request): ProviderBooking
    {
        $service = (string) $request->serviceId;
        $employee = $request->professionalId === null ? null : (string) $request->professionalId;
        $day = $request->startsAt->setTimezone($this->timezone())->startOfDay();
        $chosen = collect($this->daySlots($service, $employee, $day))->first(fn (array $slot) => $slot['starts']->equalTo($request->startsAt));

        if ($chosen === null) {
            throw ProviderException::conflict();
        }

        $customer = $this->customer($request->customer);
        $list = $chosen['entry'];
        $list[$chosen['key']] = [$chosen['slot']];

        $json = $this->client->merchant('appointment_create', [
            'LocationID' => (int) $this->location()['id'],
            'ItineraryTimeSlotList' => [$list],
            'Customer' => $customer,
        ], retry: false);

        $id = $json['Appointment']['ID'] ?? $json['AppointmentID'] ?? $json['ID'] ?? null;

        if (! is_scalar($id) || trim((string) $id) === '') {
            throw ProviderException::unknown(); // a 2xx without an appointment id is not a booking we can vouch for
        }

        return new ProviderBooking((string) $id, Appointment::CONFIRMED, $chosen['starts'], $chosen['ends'] ?? $chosen['starts']->addMinutes($this->durationOf($service)));
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId): ProviderBooking
    {
        throw ProviderException::unsupported();
    }

    public function cancel(Appointment $appointment): void
    {
        throw ProviderException::unsupported();
    }

    public function find(Appointment $appointment): ?ProviderBooking
    {
        throw ProviderException::unsupported();
    }

    /**
     * What Booker offers on one local day for one treatment (and employee), read live. Each item keeps the untouched list and slot Booker returned.
     *
     * @return list<array{entry: array<string, mixed>, key: string, slot: array<string, mixed>, starts: CarbonImmutable, ends: ?CarbonImmutable, employee: ?string}>
     */
    private function daySlots(string $serviceId, ?string $employeeId, CarbonImmutable $day): array
    {
        if (! ctype_digit($serviceId) || ($employeeId !== null && ! ctype_digit($employeeId))) {
            return [];
        }

        $treatment = ['TreatmentID' => (int) $serviceId] + ($employeeId !== null ? ['EmployeeID' => (int) $employeeId] : []);
        $json = $this->client->merchant('availability', [
            'LocationID' => (int) $this->location()['id'],
            'StartDateTime' => $day->format('Y-m-d\T00:00:00'),
            'Itineraries' => [['Treatments' => [$treatment]]],
        ], retry: true);

        if (! is_array($json['ItineraryTimeSlotLists'] ?? null)) {
            throw ProviderException::invalid(); // not the shape we know: nothing is offered on a guess
        }

        $found = [];

        foreach ($json['ItineraryTimeSlotLists'] as $entry) {
            $key = is_array($entry) ? collect(['Slots', 'SlotList'])->first(fn (string $name) => is_array($entry[$name] ?? null)) : null;

            foreach ($key ? $entry[$key] : [] as $slot) {
                $starts = is_array($slot) && is_string($slot['StartDateTime'] ?? null) ? $this->moment($slot['StartDateTime']) : null;
                $part = is_array($slot['TreatmentTimeSlots'][0] ?? null) ? $slot['TreatmentTimeSlots'][0] : [];

                if ($starts === null || (isset($part['TreatmentID']) && (string) $part['TreatmentID'] !== $serviceId)
                    || ($employeeId !== null && isset($part['EmployeeID']) && (string) $part['EmployeeID'] !== $employeeId)) {
                    continue;
                }

                $found[] = ['entry' => $entry, 'key' => $key, 'slot' => $slot, 'starts' => $starts, 'ends' => is_string($slot['EndDateTime'] ?? null) ? $this->moment($slot['EndDateTime']) : null,
                    'employee' => isset($part['EmployeeID']) ? (string) $part['EmployeeID'] : null];
            }
        }

        return $found;
    }

    /**
     * The customer of the booking: the one Booker already has (found by email, else by phone) or a new one. If Booker's search is ambiguous the
     * booking is refused rather than attached to the wrong person or to a duplicate.
     *
     * @param  array{name: string, phone: ?string, email: ?string}  $data
     * @return array<string, mixed>
     */
    private function customer(array $data): array
    {
        foreach ([['Email', $data['email'] ?? null], ['PhoneNumber', $data['phone'] ?? null]] as [$field, $value]) {
            if (! filled($value)) {
                continue;
            }

            $results = collect($this->search($field, (string) $value));

            if ($results->count() > 1 && $field === 'Email') {
                $results = $results->filter(fn ($item) => strtolower((string) ($item['Email'] ?? '')) === strtolower((string) $value))->values();
            }

            if ($results->count() > 1) {
                throw ProviderException::invalid();
            }

            if ($results->count() === 1 && isset($results[0]['ID'])) {
                return ['ID' => $results[0]['ID']];
            }
        }

        [$first, $last] = array_pad(explode(' ', trim($data['name']), 2), 2, '');

        return array_filter(['FirstName' => $first, 'LastName' => trim($last), 'Email' => $data['email'] ?? null, 'CellPhone' => $data['phone'] ?? null], fn ($value) => $value !== null && $value !== '');
    }

    /** @return list<array<string, mixed>> */
    private function search(string $field, string $value): array
    {
        $json = $this->client->merchant('customers', ['LocationID' => (int) $this->location()['id'], $field => $value, 'UsePaging' => true, 'PageSize' => 5, 'PageNumber' => 1], retry: true);
        $results = $json['Customers'] ?? $json['Results'] ?? [];

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /** Treatments Ava may offer for the bound location, cached so the catalog (which changes rarely) is not read on every message. @return list<array<string, mixed>> */
    private function treatments(): array
    {
        $location = $this->location()['id'];

        $key = "booker:merchant:treatments:{$this->integration->getKey()}:{$location}";

        if (is_array($cached = Cache::get($key)) && $cached !== []) {
            return $cached;
        }

        $fresh = (function () use ($location) {
            $items = [];

            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $json = $this->client->merchant('treatments', ['LocationID' => (int) $location, 'UsePaging' => true, 'PageSize' => self::PAGE_SIZE, 'PageNumber' => $page], retry: true);
                $results = $json['Treatments'] ?? $json['Results'] ?? [];

                if (! is_array($results) || $results === []) {
                    break;
                }

                $items = array_merge($items, $results);

                if (count($results) < self::PAGE_SIZE) {
                    break;
                }
            }

            return collect($items)->filter(fn ($item) => is_array($item) && isset($item['ID']) && ! ($item['IsClass'] ?? false) && ($item['IsActive'] ?? true) && ($item['AllowCustomersToBookOnline'] ?? true))->values()->all();
        })();

        $fresh === [] || Cache::put($key, $fresh, (int) config('integrations.booker.merchant.cache_seconds'));

        return $fresh;
    }

    private function durationOf(string $serviceId): int
    {
        try {
            $treatment = collect($this->treatments())->first(fn (array $item) => (string) $item['ID'] === $serviceId);
        } catch (ProviderException) {
            $treatment = null; // the duration only completes a slot Booker did not end: its absence never blocks the availability
        }

        return (int) (($treatment ? BookerCalendar::serviceOf($treatment)['duration_minutes'] : null) ?: 30);
    }

    private function timezone(): string
    {
        return $this->integration->workspace->settingsOrDefault()->timezone;
    }

    /**
     * Booker's date as a moment, for comparing and showing only (the raw value is what goes back to Booker). Accepts the Microsoft form
     * `/Date(1445272800000-0400)/` and ISO 8601; a time without offset is the Workspace's local time.
     */
    private function moment(string $raw): ?CarbonImmutable
    {
        try {
            if (preg_match('#^/Date\((-?\d+)(?:[+-]\d{4})?\)/$#', $raw, $match) === 1) {
                return CarbonImmutable::createFromTimestampMsUTC((int) $match[1]);
            }

            return CarbonImmutable::parse($raw, $this->timezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
