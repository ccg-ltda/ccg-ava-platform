<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\OperationalAlert;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the free trials whose end date passed to `expired`. By then the server already refuses the paid modules and new AI executions on every request
 * (`Subscription::withdrawsAccess`); this makes the state explicit, audited and visible. It is idempotent, never grants, renews or extends a trial, and deletes
 * nothing: the Workspace keeps all its data and a confirmed payment reopens it. It prints ids and counts only.
 */
class SubscriptionsExpireTrialsCommand extends Command
{
    protected $signature = 'subscriptions:expire-trials';

    protected $description = 'Move free trials that already ended to expired (idempotent; no data is touched)';

    public function handle(SubscriptionService $subscriptions, OperationalAlert $alerts): int
    {
        if (! Schema::hasTable('subscriptions') || ! Subscription::termsReady()) {
            $this->components->info('Aún no existen las pruebas gratuitas en esta base de datos: nada que vencer.');

            return self::SUCCESS;
        }

        $moved = $subscriptions->expireTrials();

        if ($moved === 0) {
            $this->components->info('No hay pruebas vencidas por cerrar.');

            return self::SUCCESS;
        }

        $alerts->raise('trials_expired', ['count' => $moved]);
        $this->components->info("{$moved} prueba(s) pasaron a vencidas: el acceso de pago quedó restringido y los datos se conservan.");

        return self::SUCCESS;
    }
}
