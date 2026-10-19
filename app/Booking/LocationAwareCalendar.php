<?php

namespace App\Booking;

/**
 * An external agenda that serves several of the business's own locations (sucursales) under ONE Workspace, each with the id the provider
 * gave it (Booker's `LocationID`). The list is what the Workspace configured, never something guessed; Ava's own agenda has its own locations
 * (App\Booking\Locations) and does not use this. `forLocation()` returns the agenda bound to one of the configured locations.
 */
interface LocationAwareCalendar extends CalendarProvider
{
    /** @return list<array{id: string, name: string}> the locations configured for this Workspace, main one first */
    public function locations(): array;

    /** @throws ProviderException invalid when the id is not one of the configured locations; null means the main (first) one */
    public function forLocation(int|string|null $locationId): static;

    /** The id and the name of the location this agenda is bound to. @return array{id: string, name: string} */
    public function location(): array;
}
