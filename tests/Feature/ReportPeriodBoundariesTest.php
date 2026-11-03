<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Workspace;
use App\Reports\ReportPeriod;
use App\Services\EconomicMetrics;
use App\Services\OperationsMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The periods of Reportes are whole days of the Workspace's time zone, while every timestamp is stored in UTC. A period boundary must be converted to UTC and applied as a
 * half-open interval [start, end): a record one second before the start belongs to the previous day, one exactly at the start to the day that begins there, and one exactly at
 * the end to the next. Uses America/Bogota (UTC-5, no daylight saving) so local midnight is 05:00 UTC and any missing conversion shifts a record by five hours.
 */
class ReportPeriodBoundariesTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const ZONE = 'America/Bogota';

    private Workspace $ws;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC')); // Thursday 07:00 in Bogota
        $this->seedRoleCatalog();
        $this->ws = $this->bookingWorkspace('LIM', ['dashboard', 'reports', 'bookings', 'orders', 'product_catalog', 'customers', 'service_catalog', 'professionals']);
        $this->ws->settings()->update(['timezone' => self::ZONE]);
        $this->ws = $this->ws->fresh();
    }

    private function appointment(string $startsAtUtc, string $status = Appointment::COMPLETED, ?int $price = 100): Appointment
    {
        $service = $this->ws->services()->first() ?? $this->ws->services()->create(['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 1]);
        $customer = $this->ws->bookingCustomers()->create(['name' => 'C'.(++$this->n), 'phone' => '+57300000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT)]);
        $start = CarbonImmutable::parse($startsAtUtc, 'UTC');

        return $this->ws->appointments()->create([
            'service_id' => $service->id, 'customer_id' => $customer->id, 'service_name' => 'Consulta', 'professional_name' => 'Ana', 'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
            'blocks_until' => $start->addMinutes(30), 'status' => $status, 'provider' => 'internal', 'sync_status' => 'none', 'channel' => 'admin', 'origin_channel' => 'admin',
            'token_hash' => hash('sha256', 'b'.$this->n), 'price_amount' => $price, 'price_currency' => $price === null ? null : 'COP',
        ]);
    }

    private function order(string $createdAtUtc, string $status = 'delivered', int $total = 1000): void
    {
        $order = $this->ws->orders()->create(['number' => ++$this->n + 5000, 'customer_name' => 'C'.$this->n, 'status' => $status, 'delivery_method' => 'pickup', 'total' => $total, 'currency' => 'COP', 'status_changed_at' => $createdAtUtc]);
        $order->forceFill(['created_at' => $createdAtUtc])->save();
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::make('7d', 'day', self::ZONE);
    }

    public function test_the_period_boundaries_are_local_midnights_expressed_in_utc(): void
    {
        $period = $this->period();

        $this->assertSame('2026-10-09 05:00:00', $period->startUtc()->toDateTimeString(), 'local midnight of the first day is 05:00 UTC, not 00:00');
        $this->assertSame('2026-10-16 05:00:00', $period->endExclusiveUtc()->toDateTimeString(), 'the end is the first instant of the next local day');
    }

    public function test_appointments_just_before_at_and_after_each_boundary_fall_in_the_right_period(): void
    {
        $this->appointment('2026-10-09 04:59:59'); // 23:59:59 of Oct 8 locally: before the period
        $this->appointment('2026-10-09 05:00:00'); // exactly local midnight of Oct 9: first instant of the period
        $this->appointment('2026-10-16 04:59:59'); // 23:59:59 of Oct 15 locally: last second of the period
        $this->appointment('2026-10-16 05:00:00'); // exactly local midnight of Oct 16: after the period
        $this->appointment('2026-10-12 12:00:00'); // in the middle

        $kpis = app(OperationsMetrics::class)->bookings($this->ws, $this->period())['kpis'];

        $this->assertSame(3, $kpis['total']);
        $this->assertSame(3, $kpis['completed']);
        $this->assertSame(300.0, $kpis['recordedValue']);
        $this->assertSame(0, $kpis['upcoming'], 'a completed appointment is never «upcoming»');
    }

    public function test_upcoming_counts_only_what_starts_after_the_period_ends(): void
    {
        $this->appointment('2026-10-16 04:59:59', Appointment::CONFIRMED); // inside (last second)
        $this->appointment('2026-10-16 05:00:00', Appointment::CONFIRMED); // first instant after

        $kpis = app(OperationsMetrics::class)->bookings($this->ws, $this->period())['kpis'];

        $this->assertSame(1, $kpis['upcoming']);
        $this->assertSame(1, $kpis['total']);
    }

    public function test_orders_and_customers_use_the_same_half_open_utc_interval(): void
    {
        $this->order('2026-10-09 04:59:59');
        $this->order('2026-10-09 05:00:00');
        $this->order('2026-10-16 04:59:59');
        $this->order('2026-10-16 05:00:00');
        foreach (['2026-10-09 04:59:59' => 'Antes', '2026-10-09 05:00:00' => 'Primero', '2026-10-16 04:59:59' => 'Ultimo', '2026-10-16 05:00:00' => 'Despues'] as $at => $name) {
            $customer = $this->ws->customers()->create(['first_name' => $name, 'phone' => '+5730099'.str_pad((string) (++$this->n), 5, '0', STR_PAD_LEFT), 'source' => 'manual', 'marketing_consent' => 'unknown']);
            $customer->forceFill(['created_at' => $at])->save();
        }

        $orders = app(OperationsMetrics::class)->orders($this->ws, $this->period())['kpis'];
        $customers = app(OperationsMetrics::class)->customers($this->ws, $this->period())['kpis'];

        $this->assertSame(2, $orders['total']);
        $this->assertSame(2000.0, $orders['sales']);
        $this->assertSame(2, $customers['new']);
    }

    public function test_the_economic_figures_agree_with_the_operational_ones_on_the_boundaries(): void
    {
        $this->appointment('2026-10-09 04:59:59', Appointment::COMPLETED, 7);
        $this->appointment('2026-10-09 05:00:00', Appointment::COMPLETED, 100);
        $this->appointment('2026-10-16 04:59:59', Appointment::COMPLETED, 200);
        $this->appointment('2026-10-16 05:00:00', Appointment::COMPLETED, 9);
        $this->appointment('2026-10-02 05:00:00', Appointment::COMPLETED, 50); // exactly the start of the previous period (7 days before)
        $this->appointment('2026-10-09 04:59:59', Appointment::COMPLETED, 25); // last second of the previous period

        $period = $this->period();
        $economic = app(EconomicMetrics::class)->bookings($this->ws, $period, $this->ws->settingsOrDefault())['kpis'];
        $operational = app(OperationsMetrics::class)->bookings($this->ws, $period)['kpis'];

        $this->assertEquals(300, $economic['income']['value']);
        $this->assertSame(2, $economic['count']['value']);
        $this->assertSame($operational['recordedValue'], (float) $economic['income']['value'], 'the same appointments, the same money, in both sections');
        $this->assertEquals(82, $economic['income']['previous'], '7 + 50 + 25: the previous period is [Oct 2 05:00, Oct 9 05:00) and the boundary record counts once');
        $this->assertSame(3, $economic['count']['previous'], 'three records in the previous period');
    }

    public function test_week_and_month_buckets_start_at_the_local_boundary_not_the_utc_one(): void
    {
        $week = ReportPeriod::make('30d', 'week', self::ZONE);
        $month = ReportPeriod::make('12m', 'month', self::ZONE);

        // Sunday Oct 11 23:59:59 in Bogota is still the week of Monday Oct 5; one second later it is the week of Oct 12.
        $this->assertSame('2026-10-05', $week->bucketStart(CarbonImmutable::parse('2026-10-12 04:59:59', 'UTC'))->toDateString());
        $this->assertSame('2026-10-12', $week->bucketStart(CarbonImmutable::parse('2026-10-12 05:00:00', 'UTC'))->toDateString());
        // October 31 23:59:59 in Bogota is October; one second later is November.
        $this->assertSame('2026-10-01', $month->bucketStart(CarbonImmutable::parse('2026-11-01 04:59:59', 'UTC'))->toDateString());
        $this->assertSame('2026-11-01', $month->bucketStart(CarbonImmutable::parse('2026-11-01 05:00:00', 'UTC'))->toDateString());
    }

    public function test_the_utc_zone_has_no_offset_and_matches_the_business_day(): void
    {
        $period = ReportPeriod::make('7d', 'day', 'UTC');

        $this->assertSame('2026-10-09 00:00:00', $period->startUtc()->toDateTimeString());
        $this->assertSame('2026-10-16 00:00:00', $period->endExclusiveUtc()->toDateTimeString());
    }
}
