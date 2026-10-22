<?php

namespace App\Console\Commands;

use App\Services\CatalogReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Hands the due reminders of the Catálogo to the queue and puts right what a crash left half done. Scheduled every minute; running it by hand or twice at once is
 * safe (every hand-over is an atomic claim of the row). It prints counts only.
 */
class CatalogRemindersSendCommand extends Command
{
    protected $signature = 'catalog:send-reminders';

    protected $description = 'Queue the due reminders of the Catálogo and recover the stuck ones (idempotent; scheduled every minute)';

    public function handle(CatalogReminders $reminders): int
    {
        if (! Schema::hasTable('catalog_reminders')) {
            $this->components->info('Aún no existen los recordatorios del catálogo en esta base de datos: nada que enviar.');

            return self::SUCCESS;
        }

        $recovered = $reminders->recoverStuck();
        $queued = $reminders->dispatchDue();
        $this->components->info("En cola: {$queued}. Recuperados: {$recovered}.");

        return self::SUCCESS;
    }
}
