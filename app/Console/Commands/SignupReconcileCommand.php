<?php

namespace App\Console\Commands;

use App\Services\SignupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconciliation of the public sign-up: lists the contracts whose payment was recorded but whose Workspace was never created (provisioning failed after the
 * payment) and, only with --retry, repeats the provisioning of each. It never confirms or invents a payment: a contract without a recorded payment is not
 * listed and not retried. It prints ids, dates and fixed codes only (no email, NIT, phone, name or reference).
 */
class SignupReconcileCommand extends Command
{
    protected $signature = 'signup:reconcile {--retry : repeat the provisioning of every paid contract that is stuck}';

    protected $description = 'List (and optionally retry) public sign-ups that were paid but not provisioned';

    public function handle(SignupService $signup): int
    {
        $stuck = $signup->stuck();

        if ($stuck->isEmpty()) {
            $this->components->info('No hay contrataciones pagadas sin aprovisionar.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($stuck as $request) {
            $line = "Solicitud {$request->id} (plan {$request->plan_id}, pago registrado {$request->paid_at->toDateTimeString()})";

            if (! $this->option('retry')) {
                $this->components->warn("{$line}: pagada y sin aprovisionar. Corrige la causa (plan activo y completo, NIT y nombre de Workspace libres) y ejecuta con --retry.");

                continue;
            }

            try {
                $signup->retryProvisioning($request);
                $this->components->info("{$line}: aprovisionada.");
            } catch (Throwable $e) {
                $failed++;
                $this->components->error($line.': sigue sin aprovisionar ('.$e::class.').');
            }
        }

        return $failed > 0 || ! $this->option('retry') ? self::FAILURE : self::SUCCESS;
    }
}
