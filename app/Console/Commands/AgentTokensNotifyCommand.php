<?php

namespace App\Console\Commands;

use App\Services\AgentTokenNotices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent:tokens:notify')]
#[Description('Warn the team before a chatbot access token expires (idempotent: one notification per token and threshold). Revokes and changes nothing.')]
class AgentTokensNotifyCommand extends Command
{
    public function handle(AgentTokenNotices $notices): int
    {
        $created = $notices->sweep();

        $this->components->info("{$created} aviso(s) de vencimiento de tokens creado(s).");

        return self::SUCCESS;
    }
}
