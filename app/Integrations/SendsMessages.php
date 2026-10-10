<?php

namespace App\Integrations;

use App\Models\Integration;

/** An integration type that can deliver a text message to a contact of its channel (see `delivery` in config/chatbots.php). */
interface SendsMessages
{
    /**
     * Sends `$text` to the contact `$to` with the Workspace's account. Never throws for a refused or failed delivery.
     * `$reference` is Ava's own tag for the message: when the channel can echo it back with the delivery states, a message
     * whose outcome stayed uncertain can be matched later without sending it twice.
     */
    public function send(Integration $integration, string $to, string $text, ?string $reference = null): SendResult;
}
