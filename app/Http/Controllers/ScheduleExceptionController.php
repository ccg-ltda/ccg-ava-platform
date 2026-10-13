<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveScheduleExceptionRequest;
use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Days (or hours) nobody can be booked: holidays, vacations, a training. A setting, so it can be removed (the removal is audited).
 * It only stops NEW bookings: appointments already made inside it stay (cancelling a customer is a person's decision), so the
 * response says how many there are.
 */
class ScheduleExceptionController extends Controller
{
    use HandlesBooking;

    public function store(SaveScheduleExceptionRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        return $this->attempt(function () use ($request, $workspace) {
            $this->assertOwnAgenda($workspace);
            $exception = $request->exception();
            $workspace->scheduleExceptions()->create($exception);

            return $this->affected($workspace, $exception);
        }, 'Excepción de horario guardada');
    }

    public function update(SaveScheduleExceptionRequest $request, int $exception): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->scheduleExceptions()->findOrFail($exception);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $data = $request->exception();
            $model->update($data);

            return $this->affected($workspace, $data);
        }, 'Excepción de horario actualizada');
    }

    public function destroy(Request $request, int $exception): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->scheduleExceptions()->findOrFail($exception);

        return $this->attempt(function () use ($workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $model->delete();
        }, 'Excepción de horario eliminada');
    }

    /** A warning when appointments already made fall inside the exception (the whole business, or the professional it names). */
    private function affected(Workspace $workspace, array $exception): ?string
    {
        $timezone = $workspace->settingsOrDefault()->timezone;
        $from = CarbonImmutable::parse($exception['date_from'], $timezone)->startOfDay();
        $to = CarbonImmutable::parse($exception['date_to'], $timezone)->endOfDay();

        $count = $workspace->appointments()->upcoming()
            ->where('starts_at', '<', $to->utc())->where('blocks_until', '>', $from->utc())
            ->when($exception['professional_id'], fn ($query, $id) => $query->where('professional_id', $id))
            ->get(['starts_at', 'ends_at'])
            ->filter(function (Appointment $appointment) use ($exception, $timezone) {
                if (! $exception['starts_at']) {
                    return true;
                }

                // A partial block only touches the appointments whose own hours overlap it, on a day inside the range.
                $start = CarbonImmutable::instance($appointment->starts_at)->setTimezone($timezone);
                $end = CarbonImmutable::instance($appointment->ends_at)->setTimezone($timezone);

                return $start->setTimeFromTimeString($exception['starts_at']) < $end && $start->setTimeFromTimeString($exception['ends_at']) > $start;
            })->count();

        return $count > 0 ? "Hay {$count} cita(s) ya reservadas en ese periodo. La excepción solo cierra reservas nuevas: revisa esas citas en la agenda." : null;
    }
}
