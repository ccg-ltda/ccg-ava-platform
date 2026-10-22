<?php

namespace App\Jobs;

use App\Services\CatalogReminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends ONE reminder of the Catálogo. A single attempt per job: the service itself plans the next attempt of a failure that provably sent nothing, and a worker that
 * dies mid-send leaves the reminder `uncertain` instead of resending it (a retry could duplicate an email the server already accepted).
 */
class SendCatalogReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $reminderId) {}

    public function handle(CatalogReminders $reminders): void
    {
        $reminders->send($this->reminderId);
    }

    public function failed(Throwable $e): void
    {
        app(CatalogReminders::class)->interrupted($this->reminderId);
    }
}
