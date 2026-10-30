<?php

namespace App\Console\Commands;

use App\Webhooks\WebhookMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('webhooks:sweep')]
#[Description('Re-dispatch webhook deliveries that are due (a lost queue job never loses an event) and release attempts stuck in «sending». Safe to repeat: attempts are claimed atomically.')]
class WebhooksSweepCommand extends Command
{
    public function handle(WebhookMaintenance $maintenance): int
    {
        $result = $maintenance->sweep();

        $this->components->info("{$result['redispatched']} entrega(s) de webhooks reenviada(s) a la cola; {$result['released']} intento(s) perdido(s) liberado(s).");

        return self::SUCCESS;
    }
}
