<?php

namespace App\Jobs;

use App\Payments\Gateways\PaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One reconciliation step of one open payment transaction: asks the provider, never charges. Unique per transaction, so a slow provider never piles up checks. */
class ReconcilePaymentTransaction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $transactionId) {}

    public function uniqueId(): string
    {
        return (string) $this->transactionId;
    }

    public function handle(PaymentService $payments): void
    {
        $payments->reconcile($this->transactionId);
    }
}
