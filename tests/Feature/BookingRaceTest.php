<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The real race for the same professional and time: several PHP PROCESSES (each with its own connection) try to book at the same instant through the real AppointmentService.
 * It needs PostgreSQL and a DISPOSABLE database whose name ends in `_testing` (the suite guard refuses anything else), because it commits (no wrapping transaction, or the
 * workers would not see the data) and rebuilds the schema. On SQLite it is skipped and says so. Run it with:
 *   APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=<disposable>_testing FILESYSTEM_DISK=local CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
 *   php vendor/bin/phpunit tests/Feature/BookingRaceTest.php
 * Never against `ccg_ava`.
 */
class BookingRaceTest extends TestCase
{
    use BuildsBookings;
    use DatabaseMigrations;

    private Workspace $workspace;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race needs PostgreSQL (separate processes, one disposable *_testing database).');
        }

        $this->seedRoleCatalog();
        // Real "now": the workers are separate processes and do not share a frozen clock.
        $this->workspace = $this->bookingWorkspace('CARRERA', ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $this->service = $this->makeService($this->workspace, ['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 10]);
    }

    /** A weekday at least three days ahead, at 15:00 UTC (inside 09:00-17:00 whichever the Workspace time zone is, for Bogotá and UTC). */
    private function slot(int $minutes = 0): CarbonImmutable
    {
        $day = CarbonImmutable::now('UTC')->addDays(3)->setTime(15, 0);

        while ($day->isWeekend()) {
            $day = $day->addDay();
        }

        return $day->addMinutes($minutes);
    }

    /**
     * Starts one worker per [professional, start] together and returns each one's outcome.
     *
     * @param  list<array{0: Professional, 1: CarbonImmutable}>  $attempts
     * @return list<array{ok: bool, error?: string}>
     */
    private function race(array $attempts): array
    {
        $barrier = sys_get_temp_dir().'/booking-race-'.bin2hex(random_bytes(6));
        $processes = [];

        foreach ($attempts as [$professional, $start]) {
            $command = [PHP_BINARY, base_path('tests/Support/booking-race-worker.php'), (string) $this->workspace->id, (string) $this->service->id, (string) $professional->id, $start->toIso8601String(), $barrier];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        usleep(1_500_000); // let every worker boot and wait at the barrier
        touch($barrier);   // go

        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim((string) collect(explode("\n", trim($output)))->last()), true);
            $this->assertIsArray($decoded, "worker output: {$output} / {$errors}");
            $results[] = $decoded;
        }

        @unlink($barrier);

        return $results;
    }

    public function test_eight_processes_booking_the_same_professional_and_time_confirm_exactly_one(): void
    {
        $professional = $this->makeProfessional($this->workspace, [$this->service]);

        $results = $this->race(array_fill(0, 8, [$professional, $this->slot()]));

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(7, collect($results)->where('error', 'conflict')->count(), 'the others get a controlled conflict, not an error: '.json_encode($results));
        $this->assertSame(1, Appointment::where('professional_id', $professional->id)->count());
    }

    public function test_overlapping_starts_of_the_same_professional_confirm_only_one_and_the_rest_after_it_is_respected(): void
    {
        $professional = $this->makeProfessional($this->workspace, [$this->service]);

        // 15:00, 15:15 and 15:30 for a 30-minute service with a 10-minute rest: 15:00 and 15:15 overlap, 15:30 collides with the rest of 15:00 (until 15:40) too.
        $results = $this->race([[$professional, $this->slot()], [$professional, $this->slot(15)], [$professional, $this->slot(30)], [$professional, $this->slot()]]);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(1, Appointment::where('professional_id', $professional->id)->count());
    }

    public function test_different_professionals_at_the_same_time_do_not_block_each_other(): void
    {
        $ana = $this->makeProfessional($this->workspace, [$this->service], 'Ana');
        $luis = $this->makeProfessional($this->workspace, [$this->service], 'Luis');

        $results = $this->race([[$ana, $this->slot()], [$luis, $this->slot()]]);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertSame(2, Appointment::count());
    }
}
