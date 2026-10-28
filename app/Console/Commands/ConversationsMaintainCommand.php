<?php

namespace App\Console\Commands;

use App\Services\ConversationAutoClose;
use App\Services\SupportSessions;
use Illuminate\Console\Command;

/**
 * Housekeeping of conversations, scheduled every minute: closes the support sessions that went quiet (server clock, see SupportSessions) and settles the outgoing messages that
 * stayed «Enviando» because nobody confirmed them. It sends nothing, deletes nothing and is safe to repeat.
 */
class ConversationsMaintainCommand extends Command
{
    protected $signature = 'conversations:maintain';

    protected $description = 'Close idle support sessions and settle outgoing messages that were never confirmed';

    public function handle(SupportSessions $sessions): int
    {
        $closed = $sessions->closeIdle() + app(ConversationAutoClose::class)->closeIdle();
        $settled = $sessions->expirePending();
        $this->components->info("Sesiones de soporte cerradas por inactividad: {$closed}. Mensajes sin confirmar: {$settled}.");

        return self::SUCCESS;
    }
}
