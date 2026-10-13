<?php

namespace App\Booking;

use App\Models\Appointment;
use App\Models\BookingSetting;
use App\Models\Professional;
use App\Models\ScheduleException;
use App\Models\ScheduleRule;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The only place that decides when something can be booked in Ava's own agenda. A start is free when the professional works
 * then (weekly intervals, in the Workspace's timezone), no exception (holiday, vacation, a blocked hour) covers it, no active
 * appointment of that professional overlaps what the new one would occupy (its duration plus the service's buffer), a resource
 * the service needs is free, and the booking rules of the Workspace (notice, how far ahead) allow it. Creating, rescheduling and
 * the public search all ask it, so what is offered and what is accepted can never disagree.
 */
class AvailabilityCalculator
{
    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, professional_id: int, professional_name: string, resource_id: ?int}>
     */
    public function slots(Workspace $workspace, Service $service, ?int $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointmentId = null, bool $ignorePolicy = false): array
    {
        if (! $service->is_active || $to <= $from) {
            return [];
        }

        $settings = BookingSetting::for($workspace);
        $timezone = $workspace->settingsOrDefault()->timezone;
        $now = CarbonImmutable::now('UTC');
        // Staff may book what the public cannot (a walk-in, a booking beyond the public window); the schedule itself still decides.
        $earliest = $ignorePolicy ? $now : $now->addMinutes($settings->min_notice_minutes);
        $latest = $ignorePolicy ? $now->addYears(2) : $now->addDays($settings->max_advance_days);

        $professionals = $service->professionals()->where('professionals.workspace_id', $workspace->id)->where('professionals.is_active', true)
            ->when($professionalId, fn ($query) => $query->where('professionals.id', $professionalId))->orderBy('professionals.name')->get();

        if ($professionals->isEmpty()) {
            return [];
        }

        $resources = $service->resources()->where('bookable_resources.workspace_id', $workspace->id)->where('bookable_resources.is_active', true)->orderBy('bookable_resources.id')->get();
        $needsResource = $service->resources()->exists();
        $busy = $this->busy($workspace, $from->subDay(), $to->addDay(), $ignoreAppointmentId);
        $rules = ScheduleRule::where('workspace_id', $workspace->id)->whereIn('professional_id', $professionals->modelKeys())->orderBy('starts_at')->get()
            ->groupBy(fn (ScheduleRule $rule) => $rule->professional_id.'|'.$rule->weekday);
        $firstDay = $from->setTimezone($timezone)->startOfDay();
        $lastDay = $to->subSecond()->setTimezone($timezone)->startOfDay();
        $exceptions = ScheduleException::where('workspace_id', $workspace->id)->where('date_to', '>=', $firstDay->toDateString())->where('date_from', '<=', $lastDay->toDateString())
            ->where(fn ($query) => $query->whereNull('professional_id')->orWhereIn('professional_id', $professionals->modelKeys()))->get();

        $duration = $service->duration_minutes;
        $step = max(1, $settings->slot_step_minutes);
        $slots = [];

        for ($day = $firstDay; $day <= $lastDay; $day = $day->addDay()) {
            foreach ($professionals as $professional) {
                $blocked = $this->blockedWindows($exceptions, $professional, $day, $timezone);

                if ($blocked === null) {
                    continue; // a closed day
                }

                foreach ($rules->get($professional->id.'|'.$day->dayOfWeekIso, collect()) as $rule) {
                    $intervalEnd = $day->setTimeFromTimeString($rule->ends_at);

                    for ($start = $day->setTimeFromTimeString($rule->starts_at); $start->addMinutes($duration) <= $intervalEnd; $start = $start->addMinutes($step)) {
                        $startUtc = $start->utc();
                        $endUtc = $startUtc->addMinutes($duration);
                        $occupiedUntil = $endUtc->addMinutes($service->buffer_minutes);

                        if ($startUtc < $earliest || $startUtc > $latest || $startUtc < $from || $startUtc >= $to) {
                            continue;
                        }

                        if ($this->overlaps($blocked, $startUtc, $endUtc) || $this->overlaps($busy['professional'][$professional->id] ?? [], $startUtc, $occupiedUntil)) {
                            continue;
                        }

                        $resourceId = null;

                        if ($needsResource) {
                            $resourceId = $resources->first(fn ($resource) => ! $this->overlaps($busy['resource'][$resource->id] ?? [], $startUtc, $occupiedUntil))?->id;

                            if ($resourceId === null) {
                                continue;
                            }
                        }

                        $slots[] = ['starts_at' => $startUtc, 'ends_at' => $endUtc, 'professional_id' => $professional->id, 'professional_name' => $professional->name, 'resource_id' => $resourceId];
                    }
                }
            }
        }

        usort($slots, fn (array $a, array $b) => [$a['starts_at'], $a['professional_name']] <=> [$b['starts_at'], $b['professional_name']]);

        return $slots;
    }

    /** The slot that starts exactly at `$startsAt` (for that professional, or the first free one when none is named), or null. */
    public function slotAt(Workspace $workspace, Service $service, ?int $professionalId, CarbonImmutable $startsAt, ?int $ignoreAppointmentId = null, bool $ignorePolicy = false): ?array
    {
        return $this->slotsAt($workspace, $service, $professionalId, $startsAt, $ignoreAppointmentId, $ignorePolicy)[0] ?? null;
    }

    /** Every free slot that starts exactly at `$startsAt` (one per free professional). @return list<array<string, mixed>> */
    public function slotsAt(Workspace $workspace, Service $service, ?int $professionalId, CarbonImmutable $startsAt, ?int $ignoreAppointmentId = null, bool $ignorePolicy = false): array
    {
        return array_values(array_filter(
            $this->slots($workspace, $service, $professionalId, $startsAt, $startsAt->addSecond(), $ignoreAppointmentId, $ignorePolicy),
            fn (array $slot) => $slot['starts_at']->equalTo($startsAt),
        ));
    }

    /**
     * Active appointments of the Workspace around the searched range, as [start, end-of-occupation] windows by professional and resource.
     *
     * @return array{professional: array<int, list<array{CarbonImmutable, CarbonImmutable}>>, resource: array<int, list<array{CarbonImmutable, CarbonImmutable}>>}
     */
    private function busy(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, ?int $ignore): array
    {
        $busy = ['professional' => [], 'resource' => []];

        Appointment::where('workspace_id', $workspace->id)->where('provider', 'internal')->whereIn('status', Appointment::BLOCKING)
            ->where('starts_at', '<', $to)->where('blocks_until', '>', $from)->when($ignore, fn ($query) => $query->where('id', '!=', $ignore))
            ->get(['professional_id', 'resource_id', 'starts_at', 'blocks_until'])
            ->each(function (Appointment $appointment) use (&$busy) {
                $window = [CarbonImmutable::instance($appointment->starts_at)->utc(), CarbonImmutable::instance($appointment->blocks_until)->utc()];
                $appointment->professional_id && $busy['professional'][$appointment->professional_id][] = $window;
                $appointment->resource_id && $busy['resource'][$appointment->resource_id][] = $window;
            });

        return $busy;
    }

    /**
     * The windows of a day that exceptions block for a professional, or null when the whole day is closed for them.
     *
     * @param  Collection<int, ScheduleException>  $exceptions
     * @return list<array{CarbonImmutable, CarbonImmutable}>|null
     */
    private function blockedWindows(Collection $exceptions, Professional $professional, CarbonImmutable $day, string $timezone): ?array
    {
        $windows = [];

        foreach ($exceptions as $exception) {
            $applies = ($exception->professional_id === null || $exception->professional_id === $professional->id)
                && $day->toDateString() >= $exception->date_from->toDateString() && $day->toDateString() <= $exception->date_to->toDateString();

            if (! $applies) {
                continue;
            }

            if ($exception->starts_at === null || $exception->ends_at === null) {
                return null;
            }

            $windows[] = [$day->setTimeFromTimeString($exception->starts_at)->utc(), $day->setTimeFromTimeString($exception->ends_at)->utc()];
        }

        return $windows;
    }

    /** @param  list<array{CarbonImmutable, CarbonImmutable}>  $windows */
    private function overlaps(array $windows, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($windows as [$from, $to]) {
            if ($start < $to && $from < $end) {
                return true;
            }
        }

        return false;
    }
}
