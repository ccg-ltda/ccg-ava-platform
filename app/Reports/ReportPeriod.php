<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * The range a report covers: whole days in the Workspace's timezone, ending today, plus the granularity charts group
 * by. Every future data source reads its range from here, so all of Reportes agrees on the same period.
 */
final class ReportPeriod
{
    public function __construct(
        public readonly string $key,
        public readonly string $granularity,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    public static function make(string $key, ?string $granularity, string $timezone): self
    {
        $definition = config("reports.periods.{$key}");
        $granularity = in_array($granularity, $definition['granularities'], true) ? $granularity : $definition['granularities'][0];
        $to = CarbonImmutable::now($timezone)->endOfDay();

        return new self($key, $granularity, $to->subDays($definition['days'] - 1)->startOfDay(), $to, $timezone);
    }

    /** @return list<string> */
    public function granularities(): array
    {
        return config("reports.periods.{$this->key}.granularities");
    }

    /**
     * Chart buckets of the period: [start of bucket, label key]. Empty buckets are included so a chart shows zeros
     * instead of gaps.
     *
     * @return list<CarbonImmutable>
     */
    public function buckets(): array
    {
        $step = fn (CarbonImmutable $moment) => match ($this->granularity) {
            'month' => $moment->addMonthNoOverflow(),
            'week' => $moment->addWeek(),
            default => $moment->addDay(),
        };

        $buckets = [];

        for ($cursor = $this->bucketStart($this->from); $cursor <= $this->to; $cursor = $step($cursor)) {
            $buckets[] = $cursor;
        }

        return $buckets;
    }

    public function bucketStart(CarbonImmutable $moment): CarbonImmutable
    {
        $local = $moment->setTimezone($this->timezone);

        return match ($this->granularity) {
            'month' => $local->startOfMonth(),
            'week' => $local->startOfWeek(),
            default => $local->startOfDay(),
        };
    }
}
