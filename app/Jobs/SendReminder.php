<?php

namespace App\Jobs;

use App\Reminders\ReminderDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt to send one reminder. The job only carries the id: the dispatcher claims the row atomically, so a duplicated or late job can never send the same attempt twice. It
 * never retries by itself (`tries = 1`): the schedule of retries lives in the reminder row.
 */
class SendReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public readonly int $reminderId) {}

    public function handle(ReminderDispatcher $dispatcher): void
    {
        $dispatcher->send($this->reminderId);
    }
}
