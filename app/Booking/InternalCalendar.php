<?php

namespace App\Booking;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Ava's own agenda: services, professionals, schedules and appointments live in Ava's database and AvailabilityCalculator
 * decides what is free. Used by Workspaces without an external agenda connected.
 */
class InternalCalendar implements CalendarProvider
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly AvailabilityCalculator $calculator,
        private readonly AppointmentService $appointments,
    ) {}

    public function key(): string
    {
        return 'internal';
    }

    public function capabilities(): array
    {
        return ['services' => true, 'professionals' => true, 'availability' => true, 'book' => true, 'reschedule' => true, 'cancel' => true, 'find' => true];
    }

    public function services(): array
    {
        return $this->workspace->services()->where('is_active', true)->orderBy('category')->orderBy('name')->get()->map(fn (Service $service) => [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'price' => $service->price === null ? null : (float) $service->price,
            'category' => $service->category,
        ])->all();
    }

    public function professionals(int|string|null $serviceId = null): array
    {
        return $this->workspace->professionals()->where('is_active', true)
            ->when($serviceId, fn ($query) => $query->whereHas('services', fn ($services) => $services->where('services.id', $serviceId)->where('services.is_active', true)))
            ->orderBy('name')->get()->map(fn ($professional) => ['id' => $professional->id, 'name' => $professional->name, 'specialty' => $professional->specialty])->all();
    }

    public function availability(int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array
    {
        return $this->availabilityIn(null, $serviceId, $professionalId, $from, $to, $ignoreAppointment);
    }

    /** The same, limited to a location of Ava's agenda (already resolved by BookingManager); null = no location applies. */
    public function availabilityIn(?int $locationId, int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointment = null): array
    {
        $service = $this->workspace->services()->find($serviceId);

        return $service ? array_map(fn (array $slot) => array_diff_key($slot, ['resource_id' => 0]), $this->calculator->slots($this->workspace, $service, $professionalId ? (int) $professionalId : null, $from, $to, $ignoreAppointment, false, $locationId)) : [];
    }

    public function book(BookingRequest $request): ProviderBooking
    {
        $service = $this->workspace->services()->find($request->serviceId) ?? throw BookingException::invalid('Ese servicio no está disponible.');
        $result = $this->appointments->book($this->workspace, $service, $request->professionalId ? (int) $request->professionalId : null, $request->startsAt, $request->customer, $request->channel, $request->notes, $request->conversationId);

        return $this->booking($result['appointment']);
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId): ProviderBooking
    {
        return $this->booking($this->appointments->reschedule($appointment, $startsAt, $professionalId ? (int) $professionalId : null, 'admin'));
    }

    public function cancel(Appointment $appointment): void
    {
        $this->appointments->cancel($appointment, null, 'admin');
    }

    public function find(Appointment $appointment): ?ProviderBooking
    {
        $current = $this->workspace->appointments()->find($appointment->id);

        return $current ? $this->booking($current) : null;
    }

    private function booking(Appointment $appointment): ProviderBooking
    {
        return new ProviderBooking((string) $appointment->id, $appointment->status, CarbonImmutable::instance($appointment->starts_at)->utc(), CarbonImmutable::instance($appointment->ends_at)->utc());
    }
}
