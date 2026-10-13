<?php

namespace App\Http\Controllers\Concerns;

use App\Booking\BookingException;
use App\Models\BookingSetting;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\RedirectResponse;

/** Shared by the admin controllers of Servicios y Reservas: what is refused is an error toast, never a crash. */
trait HandlesBooking
{
    /** Runs an action; a refused booking operation comes back as an error toast on the page the user was on. */
    private function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $warning = $action();
        } catch (BookingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // An action may hand back a warning (a string): what was done is fine, but something needs a person's attention.
        return back()->with('success', $success)->with('warning', is_string($warning) ? $warning : null);
    }

    /**
     * The catalog and the schedules are Ava's own data. With an external agenda chosen they are that agenda's, so they are read there
     * and not edited here: the two are never mixed.
     */
    private function assertOwnAgenda(Workspace $workspace): void
    {
        if (BookingSetting::for($workspace)->provider !== 'internal') {
            throw BookingException::state('Este Workspace usa una agenda externa: los servicios, profesionales y horarios se administran allí.');
        }
    }
}
