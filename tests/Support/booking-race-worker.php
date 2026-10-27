<?php

/*
 * One competing booking of tests/Feature/BookingRaceTest.php, run as its OWN PHP process with its own database connection (so the race is real, not simulated inside one
 * transaction). Usage: php booking-race-worker.php <workspace id> <service id> <professional id> <ISO start> <barrier file>
 * It waits until the barrier file exists (so all the workers start together), books through the real AppointmentService and prints ONE json line: {"ok":true} or
 * {"ok":false,"error":"<class>"}. It refuses to run unless the database is a disposable one (name ending in _testing).
 */

use App\Booking\AppointmentService;
use App\Booking\BookingException;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (config('database.default') !== 'pgsql' || ! str_ends_with($database, '_testing')) {
    fwrite(STDERR, "Refusing to run: [{$database}] is not a disposable PostgreSQL database (_testing).\n");
    exit(2);
}

[, $workspaceId, $serviceId, $professionalId, $start, $barrier] = $argv + array_fill(0, 6, null);

$deadline = microtime(true) + 20;

while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        echo json_encode(['ok' => false, 'error' => 'barrier-timeout'])."\n";
        exit(0);
    }

    usleep(200);
}

try {
    $workspace = Workspace::findOrFail((int) $workspaceId);
    $service = Service::findOrFail((int) $serviceId);
    $professional = Professional::findOrFail((int) $professionalId);
    app(AppointmentService::class)->book($workspace, $service, $professional->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Cliente '.getmypid(), 'phone' => '+57300'.random_int(1000000, 9999999), 'email' => null], 'admin');
    echo json_encode(['ok' => true])."\n";
} catch (BookingException $exception) {
    echo json_encode(['ok' => false, 'error' => 'conflict'])."\n";
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'error' => $exception::class.': '.mb_substr($exception->getMessage(), 0, 120)])."\n";
}
