<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Integrations\SendsMessages;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
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
            $result = $this->types->get($integration->type)->send($integration, $conversation->contact_id, (string) $message->body);
        } catch (Throwable $e) {
            report($e);
            $this->fail($message, 'No se pudo confirmar el envío.');

            return;
        }

        $result->ok
            ? $message->update(['status' => 'sent', 'external_id' => $result->externalId, 'failure_reason' => null])
            : $this->fail($message, $result->reason);
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
        $channel = $conversation->chatbot->channels()->where('channel', $conversation->channel)->where('is_active', true)->with('integration')->first();

        return $channel?->integration?->is_active ? $channel->integration : null;
    }
}
