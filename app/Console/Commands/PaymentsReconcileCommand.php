<?php

namespace App\Console\Commands;

use App\Jobs\ReconcilePaymentTransaction;
use App\Payments\Gateways\PaymentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:reconcile')]
#[Description('Ask the payment providers about the open payment transactions that are due (never charges), and expire the ones the customer abandoned. Safe to repeat.')]
class PaymentsReconcileCommand extends Command
{
    public function handle(PaymentService $payments): int
    {
        $ids = $payments->dueIds();

        foreach ($ids as $id) {
            ReconcilePaymentTransaction::dispatch($id);
        }

        $this->components->info(count($ids).' pago(s) pendiente(s) enviado(s) a verificar.');

        return self::SUCCESS;
    }
}
