<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\ChannelDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers one message of a human agent to its channel. It runs once only: a retry after the channel may already have
 * accepted the message would send it twice, so a crash leaves the message `unconfirmed` (it may have gone out) instead.
 */
class SendAgentMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $messageId) {}

    public function handle(ChannelDelivery $delivery): void
    {
        if ($message = Message::find($this->messageId)) {
            $delivery->deliver($message);
        }
    }

    public function failed(Throwable $e): void
    {
        if (($message = Message::find($this->messageId)) && $message->status === Message::PENDING) {
            app(ChannelDelivery::class)->unconfirm($message, 'No se pudo confirmar el envío: pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo.');
        }
    }
}
