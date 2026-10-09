<?php

namespace App\Integrations;

use App\Models\Integration;

/** An integration type that can deliver a text message to a contact of its channel (see `delivery` in config/chatbots.php). */
interface SendsMessages
{
    /** Sends `$text` to the contact `$to` with the Workspace's account. Never throws for a refused or failed delivery. */
    public function send(Integration $integration, string $to, string $text): SendResult;
}
