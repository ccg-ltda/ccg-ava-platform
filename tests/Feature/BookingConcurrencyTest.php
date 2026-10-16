<?php

namespace Tests\Feature;

use App\Audit\AuditLogger;
use App\Booking\AppointmentNotifier;
use App\Booking\AppointmentService;
use App\Booking\AvailabilityCalculator;
use App\Booking\BookingException;
use App\Booking\Locations;
use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Two requests must never confirm the same time. In PostgreSQL the guarantee is the exclusion constraint on `appointments` (the row lock only
 * orders the requests; the constraint is what refuses the one that slips through). SQLite has neither, so these tests run ONLY on PostgreSQL
 * and say so when skipped. A real multi-process run is not possible inside a test transaction: it was run by hand and is described in RECOMENDACIONES.md.
 */
class BookingConcurrencyTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Service $service;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The no-overlap guarantee is a PostgreSQL exclusion constraint: run this test against PostgreSQL.');
        }

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('CLINICA', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations']);
        $this->service = $this->makeService($this->workspace, ['name' => 'Consulta', 'duration_minutes' => 30]);
        $this->professional = $this->makeProfessional($this->workspace, [$this->service]);
    }

    private function row(string $start, string $end, string $status = 'confirmed', ?int $professionalId = null, string $provider = 'internal'): array
    {
        $customer = $this->workspace->bookingCustomers()->firstOrCreate(['phone' => '573001112233'], ['name' => 'Laura']);

        return [
            'workspace_id' => $this->workspace->id, 'service_id' => $this->service->id, 'professional_id' => $professionalId ?? $this->professional->id, 'customer_id' => $customer->id,
            'service_name' => 'Consulta', 'starts_at' => $start, 'ends_at' => $end, 'blocks_until' => $end, 'status' => $status, 'provider' => $provider,
            'sync_status' => 'none', 'channel' => 'admin', 'reschedule_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function refused(array $row): bool
    {
        try {
            DB::transaction(fn () => DB::table('appointments')->insert($row));

            return false;
        } catch (QueryException $exception) {
            return $exception->getCode() === '23P01';
        }
    }

    public function test_the_database_refuses_an_overlapping_active_appointment_of_the_same_professional(): void
    {
        DB::table('appointments')->insert($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00'));

        $this->assertTrue($this->refused($this->row('2026-10-13 10:15:00', '2026-10-13 10:45:00')), 'overlapping start');
        $this->assertTrue($this->refused($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00', 'requested')), 'the same time, even if only requested');
        $this->assertTrue($this->refused($this->row('2026-10-13 09:45:00', '2026-10-13 10:15:00')), 'overlapping end');

        // What does not occupy the same time is accepted: back to back, another professional, a cancelled one, another agenda.
        $other = $this->makeProfessional($this->workspace, [$this->service], 'Luis')->id;
        $this->assertFalse($this->refused($this->row('2026-10-13 10:30:00', '2026-10-13 11:00:00')));
        $this->assertFalse($this->refused($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00', 'confirmed', $other)));
        $this->assertFalse($this->refused($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00', 'cancelled')));
        $this->assertFalse($this->refused($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00', 'confirmed', null, 'booker')));
    }

    public function test_a_booking_that_passes_every_application_check_is_still_refused_by_the_database(): void
    {
        // A calculator that keeps answering with what it saw first: it models the second request that read the agenda BEFORE the first one wrote.
        $stale = new class extends AvailabilityCalculator
        {
            private ?array $seen = null;

            public function slots(Workspace $workspace, Service $service, ?int $professionalId, CarbonImmutable $from, CarbonImmutable $to, ?int $ignoreAppointmentId = null, bool $ignorePolicy = false, ?int $locationId = null): array
            {
                return $this->seen ??= parent::slots($workspace, $service, $professionalId, $from, $to, $ignoreAppointmentId, $ignorePolicy, $locationId);
            }
        };
        $racing = new AppointmentService($stale, app(AuditLogger::class), app(Locations::class), app(AppointmentNotifier::class));
        $startsAt = CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC');

        $first = $racing->book($this->workspace, $this->service, $this->professional->id, $startsAt, ['name' => 'Primera', 'phone' => '+573001112233', 'email' => null], 'admin');
        $this->assertSame(Appointment::CONFIRMED, $first['appointment']->status);

        // Same stale view: the application would write it. The constraint says no, and the caller gets the ordinary "unavailable" answer.
        try {
            $racing->book($this->workspace, $this->service, $this->professional->id, $startsAt, ['name' => 'Segunda', 'phone' => '+573009998888', 'email' => null], 'admin');
            $this->fail('A second appointment was written over the first one.');
        } catch (BookingException $exception) {
            $this->assertSame(BookingException::SLOT_UNAVAILABLE, $exception->reason);
        }

        $this->assertSame(1, Appointment::where('professional_id', $this->professional->id)->whereIn('status', Appointment::BLOCKING)->count());
    }

    public function test_the_constraint_holds_inside_a_location_too(): void
    {
        $north = $this->workspace->locations()->create(['name' => 'Norte']);
        $south = $this->workspace->locations()->create(['name' => 'Sur']);
        DB::table('appointments')->insert($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00') + ['location_id' => $north->id, 'location_name' => 'Norte']);

        // A professional cannot be in two places at once: the other location does not make the overlap acceptable.
        $this->assertTrue($this->refused($this->row('2026-10-13 10:00:00', '2026-10-13 10:30:00') + ['location_id' => $south->id, 'location_name' => 'Sur']));
    }
}
