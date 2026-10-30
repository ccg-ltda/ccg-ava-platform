<?php

namespace App\Jobs;

use App\Webhooks\WebhookSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt to deliver one webhook. The job only carries the delivery id: the sender claims the row atomically, so a duplicated or late job (a retry race, the
 * sweeper) can never send the same attempt twice. It never retries by itself (`tries = 1`): the schedule of retries lives in the delivery row.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(WebhookSender $sender): void
    {
        $sender->attempt($this->deliveryId);
    }
}
