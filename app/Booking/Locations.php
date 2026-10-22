<?php

namespace App\Booking;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\ScheduleRule;
use App\Models\Workspace;
use App\Services\LocationScope;
use App\Services\ModuleAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one place that decides how locations (sedes) take part in booking, so the admin agenda, the public page and Ava Chat can never disagree.
 *
 * Locations only matter while the plan of the Workspace includes the `locations` module AND Ava's own agenda is used (an external agenda has
 * its own locations). They are never mandatory by themselves: a Workspace with no active location books exactly as before; with ONE active
 * location that location is implied; with SEVERAL the caller must name one, because guessing would put an appointment in the wrong place.
 * A location named by a caller must be an ACTIVE location of the Workspace: anything else (another Workspace, inactive, not a number) is the same refusal.
 */
class Locations
{
    private ?bool $schemaReady = null;

    public function __construct(private readonly ModuleAccess $modules, private readonly LocationScope $scope) {}

    /**
     * Whether the database has the structure of locations (migration `create_locations_and_booking_traceability`). The code ships before a
     * migration is applied in some environments; until then the pages that read must still open and say so, instead of failing with a
     * missing table. Checked once per request (the Locations service is scoped).
     */
    public function schemaReady(): bool
    {
        return $this->schemaReady ??= Schema::hasTable('locations') && Schema::hasColumn('appointments', 'location_id');
    }

    public function enabled(Workspace $workspace): bool
    {
        return $this->schemaReady() && $this->modules->allows($workspace, 'locations');
    }

    /**
     * The active locations the CURRENT member may work with: every one for an unrestricted member (and for the public page and Ava Chat, which act for the
     * business and have no signed-in member), only the authorized ones for a member limited to selected locations. Everything that lists, validates or resolves
     * a location of a signed-in member goes through here, so none can name a location the member is not authorized for.
     *
     * @return Collection<int, Location>
     */
    public function active(Workspace $workspace): Collection
    {
        $all = $this->enabled($workspace) ? $workspace->locations()->where('is_active', true)->orderBy('name')->get() : new Collection;
        $allowed = $this->scope->allowedIds($workspace, auth()->user());

        return $allowed === null ? $all : $all->filter(fn (Location $location) => in_array($location->id, $allowed, true))->values();
    }

    /** True when a booking must name its location (two or more are active). */
    public function required(Workspace $workspace): bool
    {
        return $this->active($workspace)->count() > 1;
    }

    /** The location a booking or a search is about, or null when locations do not apply. @throws BookingException */
    public function resolve(Workspace $workspace, mixed $requested): ?Location
    {
        $active = $this->active($workspace);

        if ($requested !== null && $requested !== '') {
            $found = (is_int($requested) || (is_string($requested) && ctype_digit($requested))) ? $active->firstWhere('id', (int) $requested) : null;

            return $found ?? throw BookingException::invalid('Esa sede no está disponible.');
        }

        return match (true) {
            $active->isEmpty() => null,
            $active->count() === 1 => $active->first(),
            default => throw BookingException::invalid('Indica la sede donde quieres la cita.'),
        };
    }

    /**
     * The location an appointment is moved within: its own. An appointment made without one (all the historical ones) moves freely; one whose
     * location was switched off cannot be moved (it would take a place nobody attends any more): cancel it and book again.
     *
     * @throws BookingException
     */
    public function ofAppointment(Appointment $appointment): ?int
    {
        if ($appointment->location_id === null || ! $this->enabled($appointment->workspace)) {
            return null;
        }

        $active = $appointment->workspace->locations()->where('is_active', true)->whereKey($appointment->location_id)->exists();

        return $active ? $appointment->location_id : throw BookingException::state('La sede de esta cita ya no está activa. Cancélala y crea una nueva en otra sede.');
    }

    /** Whether the location offers the service (a service with no location of its own is offered in all of them). */
    public function offers(Location $location, int $serviceId): bool
    {
        $rows = DB::table('location_service')->where('workspace_id', $location->workspace_id)->where('service_id', $serviceId);

        return ! $rows->exists() || (clone $rows)->where('location_id', $location->id)->exists();
    }

    /** Ids of the professionals with working hours at the location (hours without a location count: they apply everywhere). @return list<int> */
    public function professionalIds(Workspace $workspace, int $locationId): array
    {
        return ScheduleRule::where('workspace_id', $workspace->id)
            ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $locationId))
            ->distinct()->pluck('professional_id')->map(fn ($id) => (int) $id)->all();
    }
}
