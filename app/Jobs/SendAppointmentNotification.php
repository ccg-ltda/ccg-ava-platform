<?php

namespace App\Jobs;

use App\Booking\AppointmentNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends ONE planned confirmation or reminder. It runs once only: a retry after WhatsApp may already have accepted the message would send it
 * twice, so a crash leaves the notification `uncertain` instead (the provider failures that provably sent nothing are retried by the notifier
 * itself, with a limit, by planning the next attempt).
 */
class SendAppointmentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId) {}

    public function handle(AppointmentNotifier $notifier): void
    {
        $notifier->send($this->notificationId);
    }

    public function failed(Throwable $e): void
    {
        app(AppointmentNotifier::class)->interrupted($this->notificationId);
    }
}
