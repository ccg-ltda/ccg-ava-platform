<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Order;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The economic view of Reportes: the money tied to the two modules that generate it, Agenda (Reservas) and Pedidos, and nothing else.
 *
 * What counts, and what it is called. Ava does NOT record payments, so none of these figures is «cash received»: they are the value associated with
 *  - services that were REALLY carried out: appointments whose status is `completed` (never requested, confirmed, cancelled, no-show or failed), valued at the price
 *    the appointment recorded when it was booked, dated on the day of the appointment; and
 *  - orders that were REALLY delivered: status `delivered` (the final state of the flow; a cancelled or still-open order adds nothing), valued at the total the server
 *    computed, dated on the moment of delivery (`status_changed_at`, which for a final state is when it was delivered).
 * Each record is counted once (one row per appointment or order, no joins). A record without a price is not valued (it is counted apart so the page can say so), money in
 * more than one currency is never added (the total is withheld and flagged), and the period and the location filter are applied inside every query, always from ONE
 * Workspace. Like OperationsMetrics it has no presentation and no permission checks: the controller decides who may ask.
 */
class EconomicMetrics
{
    /**
     * @param  ?list<int>  $locationIds
     * @return array<string, mixed>
     */
    public function bookings(Workspace $workspace, ReportPeriod $period, WorkspaceSetting $settings, ?array $locationIds = null): array
    {
        $realized = fn (CarbonImmutable $from, CarbonImmutable $to) => Appointment::query()->where('workspace_id', $workspace->id)->where('status', Appointment::COMPLETED)
            ->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc())->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds));

        return $this->summary(
            fn (CarbonImmutable $from, CarbonImmutable $to) => $realized($from, $to)->whereNotNull('price_amount')->get(['starts_at as at', 'price_amount as amount', 'price_currency as currency']),
            fn (CarbonImmutable $from, CarbonImmutable $to) => $realized($from, $to)->whereNull('price_amount')->count(),
            $workspace,
            $period,
            $settings,
        );
    }

    /**
     * @param  ?list<int>  $locationIds
     * @return array<string, mixed>
     */
    public function orders(Workspace $workspace, ReportPeriod $period, WorkspaceSetting $settings, ?array $locationIds = null): array
    {
        $delivered = fn (CarbonImmutable $from, CarbonImmutable $to): Builder => Order::query()->where('workspace_id', $workspace->id)->where('status', 'delivered')
            ->whereRaw('coalesce(status_changed_at, updated_at) >= ? and coalesce(status_changed_at, updated_at) < ?', [$from->utc()->format('Y-m-d H:i:s'), $to->utc()->format('Y-m-d H:i:s')])
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds));

        return $this->summary(
            fn (CarbonImmutable $from, CarbonImmutable $to) => $delivered($from, $to)->selectRaw('coalesce(status_changed_at, updated_at) as at, total as amount, currency')->get(),
            fn () => 0, // an order always has its computed total: there is no unpriced order
            $workspace,
            $period,
            $settings,
        );
    }

    /**
     * The headline figures against the previous period of the same length, and the series of both on the same timeline.
     *
     * @param  callable(CarbonImmutable, CarbonImmutable): Collection<int, object>  $rows  the valued records between two moments
     * @param  callable(CarbonImmutable, CarbonImmutable): int  $unpriced  how many carried-out records have no value
     * @return array<string, mixed>
     */
    private function summary(callable $rows, callable $unpriced, Workspace $workspace, ReportPeriod $period, WorkspaceSetting $settings): array
    {
        $days = (int) config("reports.periods.{$period->key}.days");
        $beforeFrom = $period->from->subDays($days);
        $beforeTo = $period->from->subSecond(); // the label of the previous range; the query is half-open up to `$period->from`
        $now = $rows($period->from, $period->endExclusiveUtc());
        $before = $rows($beforeFrom, $period->from);
        $currencies = $now->pluck('currency')->merge($before->pluck('currency'))->unique()->values();
        $single = $currencies->count() <= 1;
        $sum = fn (Collection $items) => round((float) $items->sum('amount'), 2);
        $average = fn (Collection $items) => $items->isEmpty() ? null : round($sum($items) / $items->count(), 2);

        return [
            'kpis' => [
                'income' => $single ? $this->compare($sum($now), $sum($before)) : null,
                'count' => $this->compare($now->count(), $before->count()),
                'average' => $single ? ['value' => $average($now), 'previous' => $average($before)] : null,
                'withoutValue' => $unpriced($period->from, $period->endExclusiveUtc()),
                'currency' => $currencies->first() ?? $workspace->settingsOrDefault()->currency,
                'mixedCurrencies' => ! $single,
            ],
            'series' => $single ? $this->series($now, $before, $period, $days, $settings) : null,
            'previousRange' => ['from' => $settings->formatDate($beforeFrom), 'to' => $settings->formatDate($beforeTo)],
        ];
    }

    /**
     * One point per bucket of the period (empty ones at zero): the value and the number of records of this period, and the value of the previous period shifted forward
     * by its own length so both sit on the same timeline.
     *
     * @return array{total: float, points: list<array<string, mixed>>}
     */
    private function series(Collection $now, Collection $before, ReportPeriod $period, int $days, WorkspaceSetting $settings): array
    {
        $bucket = function (Collection $items, int $shiftDays) use ($period) {
            $totals = [];

            foreach ($items as $row) {
                $key = $period->bucketStart(CarbonImmutable::parse($row->at, 'UTC')->addDays($shiftDays))->toDateString();
                $totals[$key] = ['value' => ($totals[$key]['value'] ?? 0) + (float) $row->amount, 'count' => ($totals[$key]['count'] ?? 0) + 1];
            }

            return $totals;
        };
        [$current, $previous] = [$bucket($now, 0), $bucket($before, $days)];

        $points = collect($period->buckets())->map(fn (CarbonImmutable $start) => [
            'key' => $start->toDateString(),
            'label' => match ($period->granularity) {
                'month' => $start->locale('es')->isoFormat('MMM YYYY'),
                'week' => 'Semana del '.$settings->formatDate($start),
                default => $settings->formatDate($start),
            },
            'short' => $period->granularity === 'month' ? $start->locale('es')->isoFormat('MMM') : $start->format('d/m'),
            'values' => [
                'income' => round($current[$start->toDateString()]['value'] ?? 0, 2),
                'previous' => round($previous[$start->toDateString()]['value'] ?? 0, 2),
            ],
            'count' => $current[$start->toDateString()]['count'] ?? 0,
        ])->all();

        return ['total' => round(array_sum(array_column($current, 'value')), 2), 'points' => $points];
    }

    /** @return array{value: float|int, previous: float|int, change: ?int} change in whole percent, null without a base to compare */
    private function compare(float|int $value, float|int $previous): array
    {
        return ['value' => $value, 'previous' => $previous, 'change' => $previous > 0 ? (int) round(($value - $previous) / $previous * 100) : null];
    }
}
