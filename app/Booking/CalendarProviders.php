<?php

namespace App\Booking;

use App\Integrations\CalendarProviderType;
use App\Integrations\IntegrationRegistry;
use App\Models\BookingSetting;
use App\Models\Workspace;
use App\Services\ModuleAccess;

/**
 * Which agenda is the source of truth for a Workspace. Ava's own unless the Workspace chose a provider; a chosen provider is
 * used only while it is connected (its integration exists and is active) AND the plan includes `calendar_providers`. If not,
 * the answer is an agenda that refuses everything, never Ava's own: the two sources are never mixed.
 */
class CalendarProviders
{
    public function __construct(
        private readonly IntegrationRegistry $types,
        private readonly ModuleAccess $modules,
        private readonly AvailabilityCalculator $calculator,
        private readonly AppointmentService $appointments,
    ) {}

    /** The agenda the Workspace chose (or, with `$provider`, a given one: an old appointment is always handled by the agenda that owns it). */
    public function for(Workspace $workspace, ?string $provider = null): CalendarProvider
    {
        $provider ??= BookingSetting::for($workspace)->provider;

        if ($provider === 'internal') {
            return new InternalCalendar($workspace, $this->calculator, $this->appointments);
        }

        $integrationType = config("bookings.providers.{$provider}.integration");
        $integration = $integrationType && $this->modules->allows($workspace, 'calendar_providers')
            ? $workspace->integrations()->where('type', $integrationType)->where('is_active', true)->first()
            : null;
        $type = $integration ? $this->types->get($integration->type) : null;

        return $type instanceof CalendarProviderType ? $type->calendar($integration) : new UnavailableCalendar($provider);
    }

    public function isInternal(CalendarProvider $provider): bool
    {
        return $provider instanceof InternalCalendar;
    }
}
