<?php

namespace App\Console\Commands;

use App\Services\ChatbotExecutions;
use Illuminate\Console\Command;

/**
 * Picks up what a crash or a lost queue left half done in `chatbot_executions`, and retries a failed execution on request.
 * It never repeats a send: a reply whose delivery was cut off is settled as uncertain, not resent. Scheduled every minute.
 */
class ExecutionsRecoverCommand extends Command
{
    protected $signature = 'executions:recover {--retry= : id of a FAILED execution to run again from the start (only one that stored no reply)}';

    protected $description = 'Recover chatbot executions left half done by a crash, without ever resending a message';

    public function handle(ChatbotExecutions $executions): int
    {
        if ($this->option('retry') !== null) {
            if (! $executions->retryFailed((int) $this->option('retry'))) {
                $this->components->error('Esa ejecución no existe, no está fallida o ya guardó una respuesta: no se puede repetir.');

                return self::FAILURE;
            }

            $this->components->info('Ejecución puesta en cola de nuevo.');

            return self::SUCCESS;
        }

        $counts = $executions->recover();
        $this->components->info("En cola de nuevo: {$counts['requeued']}. Entregas retomadas: {$counts['resumed']}. Envíos marcados sin confirmar: {$counts['uncertain']}.");

        return self::SUCCESS;
    }
}
