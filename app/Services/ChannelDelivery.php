<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Integrations\SendsMessages;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gets a message written by a human agent to the contact, by the channel the conversation came from (`delivery` in
 * config/chatbots.php). It never reports more than it knows: `sent` means the channel accepted the message (or, for the
 * web widget, that it is waiting for the visitor to fetch it), `failed` carries the reason, and a channel that has no way
 * to carry the message is reported as such before anything is written.
 */
class ChannelDelivery
{
    public const PULL = 'pull';

    public const INTEGRATION = 'integration';

    public function __construct(private readonly IntegrationRegistry $types) {}

    /**
     * Whether an agent's message can leave Ava on this conversation's channel right now.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function availability(Conversation $conversation): array
    {
        if ($conversation->isDemo()) {
            return ['ok' => true, 'message' => null];
        }

        $label = config("chatbots.channels.{$conversation->channel}.label", $conversation->channel);

        // Same switches as every other path (agent API, widget, webhook, executions): a deactivated chatbot or a channel that is off is silent.
        if (! $conversation->chatbot->is_active) {
            return ['ok' => false, 'message' => 'El chatbot está desactivado: actívalo para responder desde Ava.'];
        }

        if ($this->channel($conversation) === null) {
            return ['ok' => false, 'message' => "El canal {$label} está apagado o sin cuenta configurada para este chatbot."];
        }

        return match (config("chatbots.channels.{$conversation->channel}.delivery")) {
            self::PULL => ['ok' => true, 'message' => null],
            self::INTEGRATION => $this->integrationIssue($conversation, $label),
            default => ['ok' => false, 'message' => "Ava todavía no puede enviar respuestas por {$label}."],
        };
    }

    /** Hands a pending message to its channel and records the outcome on it. A message that is not pending is left alone. */
    public function deliver(Message $message): void
    {
        if ($message->status !== Message::PENDING) {
            return;
        }

        $conversation = $message->conversation()->with('chatbot')->first();
        $issue = $this->availability($conversation);

        if (! $issue['ok']) {
            $this->fail($message, $issue['message']);

            return;
        }

        // A demo conversation has no real channel: the message is only marked as sent in Ava, and it says so (`simulated`).
        if ($conversation->isDemo() || config("chatbots.channels.{$conversation->channel}.delivery") === self::PULL) {
            $message->update(['status' => 'sent']);

            return;
        }

        try {
            $integration = $this->integration($conversation);
            // `ava:{id}` comes back in the channel's delivery states, which lets an unconfirmed message be recognized later.
            $result = $this->types->get($integration->type)->send($integration, $conversation->contact_id, (string) $message->body, "ava:{$message->id}");
        } catch (Throwable $e) {
            // Only the class is logged: the text of an exception may carry the message itself or a credential.
            Log::error('channel_delivery_error', ['exception' => $e::class, 'message' => $message->id]);
            $this->unconfirm($message, 'No se pudo confirmar el envío: pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo.');

            return;
        }

        match (true) {
            $result->ok => $message->update(['status' => 'sent', 'external_id' => $result->externalId, 'failure_reason' => null]),
            $result->uncertain => $this->unconfirm($message, $result->reason),
            default => $this->fail($message, $result->reason),
        };
    }

    /** The channel may or may not have taken the message: kept as such, and never sent again by Ava on its own. */
    public function unconfirm(Message $message, ?string $reason): void
    {
        $message->update(['status' => Message::UNCONFIRMED, 'failure_reason' => mb_substr($reason ?? 'No se pudo confirmar el envío.', 0, 255)]);
    }

    public function fail(Message $message, ?string $reason): void
    {
        $message->update(['status' => 'failed', 'failure_reason' => mb_substr($reason ?? 'No se pudo enviar el mensaje.', 0, 255)]);
    }

    /** @return array{ok: bool, message: ?string} */
    private function integrationIssue(Conversation $conversation, string $label): array
    {
        $integration = $this->integration($conversation);

        return match (true) {
            $integration === null => ['ok' => false, 'message' => "El canal {$label} está apagado o sin cuenta configurada para este chatbot."],
            ! $this->types->get($integration->type) instanceof SendsMessages => ['ok' => false, 'message' => "Ava todavía no puede enviar respuestas por {$label}."],
            default => ['ok' => true, 'message' => null],
        };
    }

    /** The active integration of the conversation's channel, always through its own chatbot (so its own Workspace). */
    private function integration(Conversation $conversation): ?Integration
    {
        $channel = $this->channel($conversation);

        return $channel?->integration?->is_active ? $channel->integration : null;
    }

    /** The conversation's channel of its own chatbot, only while it is switched on. */
    private function channel(Conversation $conversation): ?ChatbotChannel
    {
        return $conversation->chatbot->channels()->where('channel', $conversation->channel)->where('is_active', true)->with('integration')->first();
    }
}
