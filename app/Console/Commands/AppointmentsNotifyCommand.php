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

        if ($result['failed'] > 0) {
            // Every step was attempted; the exit code tells the scheduler's monitor that something did not work (what failed is in the log with a fixed code).
            $this->components->error("Pasos con error: {$result['failed']}. Los demás se ejecutaron; revisa el registro (appointments_notify_*).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
