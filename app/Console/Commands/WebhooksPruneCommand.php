<?php

namespace App\Console\Commands;

use App\Webhooks\WebhookMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('webhooks:prune')]
#[Description('Delete webhook deliveries past the retention window (config/webhooks.php). Touches only the delivery history, never an endpoint or business data.')]
class WebhooksPruneCommand extends Command
{
    public function handle(WebhookMaintenance $maintenance): int
    {
        $this->components->info($maintenance->prune().' entrega(s) antigua(s) de webhooks eliminada(s).');

        return self::SUCCESS;
    }
}
