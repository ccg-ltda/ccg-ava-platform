<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookingRecord;
use App\Models\Workspace;
use App\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reservations of a Workspace from EVERY system, counted once: the reservations reported from Booker or Lava Studio (`booking_records`) plus the appointments of
 * Ava's own agenda. An Ava appointment that is also a reported record (Ava booked it in Booker and Booker reported it back) is counted only through the record.
 * `source` separates Booker, Lava Studio and Ava; the consolidated total is their sum. It works for a Workspace that never uses Ava's agenda.
 *
 * Mapping of an appointment's state: requested -> booked, confirmed, completed, no_show, cancelled; `failed` (the agenda refused it) is not a reservation and is left out.
 *
 * @phpstan-type Filters array{locations?: ?list<int>, service?: ?string, professional?: ?string, status?: ?string, source?: ?string}
 */
class ConsolidatedBookings
{
    private const APPOINTMENT_STATUS = ['requested' => 'booked', 'confirmed' => 'confirmed', 'completed' => 'completed', 'no_show' => 'no_show', 'cancelled' => 'cancelled'];

    public const LABELS = ['booked' => 'Reservada', 'confirmed' => 'Confirmada', 'completed' => 'Atendida', 'no_show' => 'No asistió', 'cancelled' => 'Cancelada'];

    public const SOURCE_LABELS = ['booker' => 'Booker', 'lava' => 'Lava Studio', 'ava' => 'Agenda de Ava'];

    /**
     * @param  Filters  $filters
     * @return array<string, mixed>
     */
    public function summary(Workspace $workspace, ReportPeriod $period, array $filters = []): array
    {
        $records = fn (): Builder => $this->records($workspace, $period, $filters);
        $appointments = fn (): Builder => $this->appointments($workspace, $period, $filters);

        $bySource = [];
        $byStatus = [];

        foreach ($records()->selectRaw('source, status, count(*) as total')->groupBy('source', 'status')->get() as $row) {
            $bySource[$row->source] = ($bySource[$row->source] ?? 0) + (int) $row->total;
            $byStatus[$row->status] = ($byStatus[$row->status] ?? 0) + (int) $row->total;
        }

        foreach ($appointments()->selectRaw('provider, status, count(*) as total')->groupBy('provider', 'status')->get() as $row) {
            $source = $row->provider === 'internal' ? 'ava' : $row->provider;
            $status = self::APPOINTMENT_STATUS[$row->status] ?? null;

            if ($status !== null) {
                $bySource[$source] = ($bySource[$source] ?? 0) + (int) $row->total;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + (int) $row->total;
            }
        }

        return [
            'total' => array_sum($bySource),
            'bySource' => collect(self::SOURCE_LABELS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => (int) ($bySource[$key] ?? 0)])->values()->all(),
            'byStatus' => collect(self::LABELS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'value' => (int) ($byStatus[$key] ?? 0)])->values()->all(),
            'byLocation' => $this->withLocationNames($workspace, $this->ranking($records(), $appointments(), 'coalesce(location_ref, cast(location_id as varchar))', 'location_name')),
            'byService' => $this->ranking($records(), $appointments(), 'service_name', 'service_name'),
            'byProfessional' => $this->ranking($records(), $appointments(), 'professional_name', 'professional_name'),
            'byOrigin' => $this->ranking($records(), $appointments(), 'origin', 'origin_channel'),
            'recordedFromExternal' => (int) $records()->count(),
        ];
    }

    /**
     * A record stores the id of an Ava location (or the source's own reference): the id is shown as the location's name.
     *
     * @param  list<array{key: string, label: string, value: int}>  $rows
     * @return list<array{key: string, label: string, value: int}>
     */
    private function withLocationNames(Workspace $workspace, array $rows): array
    {
        $names = $workspace->locations()->pluck('name', 'id');

        return array_map(fn (array $row) => ctype_digit($row['label']) && $names->has((int) $row['label']) ? ['label' => $names[(int) $row['label']]] + $row : $row, $rows);
    }

    /** @param  Filters  $filters */
    private function records(Workspace $workspace, ReportPeriod $period, array $filters): Builder
    {
        return BookingRecord::query()->where('workspace_id', $workspace->id)
            ->where('starts_at', '>=', $period->startUtc())->where('starts_at', '<', $period->endExclusiveUtc())
            ->when(($filters['locations'] ?? null) !== null, fn ($q) => $q->whereIn('location_id', $filters['locations']))
            ->when($filters['service'] ?? null, fn ($q, $v) => $q->where('service_name', $v))
            ->when($filters['professional'] ?? null, fn ($q, $v) => $q->where('professional_name', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v));
    }

    /** @param  Filters  $filters */
    private function appointments(Workspace $workspace, ReportPeriod $period, array $filters): Builder
    {
        $source = $filters['source'] ?? null;
        $status = $filters['status'] ?? null;

        return Appointment::query()->where('workspace_id', $workspace->id)
            ->where('starts_at', '>=', $period->startUtc())->where('starts_at', '<', $period->endExclusiveUtc())
            ->whereIn('status', array_keys(self::APPOINTMENT_STATUS))
            // Counted through its record when the source reported it back: never twice.
            ->whereNotIn('id', BookingRecord::query()->where('workspace_id', $workspace->id)->whereNotNull('appointment_id')->select('appointment_id'))
            ->when(($filters['locations'] ?? null) !== null, fn ($q) => $q->whereIn('location_id', $filters['locations']))
            ->when($filters['service'] ?? null, fn ($q, $v) => $q->where('service_name', $v))
            ->when($filters['professional'] ?? null, fn ($q, $v) => $q->where('professional_name', $v))
            ->when($status, fn ($q) => $q->whereIn('status', array_keys(array_filter(self::APPOINTMENT_STATUS, fn ($mapped) => $mapped === $status))))
            ->when($source, fn ($q) => $source === 'ava' ? $q->where('provider', 'internal') : $q->where('provider', $source));
    }

    /**
     * Top values of one dimension across both tables, cancelled reservations left out (like the agenda ranking).
     *
     * @return list<array{key: string, label: string, value: int}>
     */
    private function ranking(Builder $records, Builder $appointments, string $recordExpression, string $appointmentColumn): array
    {
        $totals = [];

        foreach ($records->where('status', '!=', 'cancelled')->selectRaw("{$recordExpression} as label, count(*) as total")->groupBy('label')->get() as $row) {
            $totals[(string) $row->label] = ($totals[(string) $row->label] ?? 0) + (int) $row->total;
        }

        foreach ($appointments->where('status', '!=', 'cancelled')->selectRaw("{$appointmentColumn} as label, count(*) as total")->groupBy('label')->get() as $row) {
            $totals[(string) $row->label] = ($totals[(string) $row->label] ?? 0) + (int) $row->total;
        }

        unset($totals['']);
        arsort($totals);

        return collect($totals)->take(8)->map(fn (int $value, string $label) => ['key' => $label, 'label' => $label, 'value' => $value])->values()->all();
    }
}
