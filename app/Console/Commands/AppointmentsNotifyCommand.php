<?php

namespace App\Console\Commands;

use App\Booking\AppointmentNotifier;
use Illuminate\Console\Command;

/**
 * Plans the reminders that are due soon, puts right what a crash left half done and queues the messages that are due. Scheduled every minute;
 * running it by hand or twice at once is safe (every step is idempotent and every hand-over is an atomic claim).
 */
class AppointmentsNotifyCommand extends Command
{
    protected $signature = 'appointments:notify';

    protected $description = 'Plan and queue the confirmations and reminders of appointments (idempotent; scheduled every minute)';

    public function handle(AppointmentNotifier $notifier): int
    {
        $result = $notifier->run();
        $this->components->info("Planificados: {$result['planned']}. En cola: {$result['queued']}. Recuperados: {$result['recovered']}.");

        return self::SUCCESS;
    }
}
