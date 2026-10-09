<?php

namespace App\Services;

use App\Exceptions\ConversationStateException;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * What the demo bench does on a DEMO conversation from the inbox: a contact writes, the "AI" answers, the assistant asks
 * for a person, a delivery state changes. It goes through the same services as real traffic (ConversationService for the
 * messages, ConversationControl for who answers), so the rules under test are the real ones; the only difference is that
 * every message is marked `simulated` and nothing leaves Ava. The simulated "AI" is a fixed text, not a model.
 */
class DemoSimulator
{
    public const AI_REPLY = 'Respuesta simulada de la IA (texto fijo de la demo, no la genera ningún modelo).';

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationControl $control,
    ) {}

    /** The contact writes. A resolved conversation reopens with the AI, exactly as with a real message. */
    public function incoming(Conversation $conversation, string $text): Message
    {
        $this->assertDemo($conversation);

        return $this->conversations->record($conversation->chatbot, $conversation->channel, [
            'contact_id' => $conversation->contact_id, 'direction' => 'in', 'type' => 'text', 'body' => $text, 'sent_at' => now(), 'simulated' => true,
        ])['message'];
    }

    /** The AI answers, but only if it has the conversation: the check and the write share one lock, like the web widget. */
    public function aiReply(Conversation $conversation): Message
    {
        $this->assertDemo($conversation);

        return DB::transaction(function () use ($conversation) {
            $locked = Conversation::with('chatbot')->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->aiMayReply()) {
                throw new ConversationStateException('La IA no puede responder: la conversación está '.mb_strtolower(Conversation::LABELS[$locked->handling]).'.');
            }

            return $this->conversations->record($locked->chatbot, $locked->channel, [
                'contact_id' => $locked->contact_id, 'direction' => 'out', 'type' => 'text', 'body' => self::AI_REPLY, 'status' => 'sent', 'sent_at' => now(), 'simulated' => true,
            ])['message'];
        });
    }

    /** The assistant asks for a person: the conversation waits for an agent. */
    public function handoff(Conversation $conversation): Conversation
    {
        $this->assertDemo($conversation);

        return $this->control->requestHuman($conversation, 'Solicitud de agente simulada.');
    }

    /** Changes the delivery state of a simulated outgoing message (what a channel's status webhook would report). */
    public function status(Conversation $conversation, Message $message, string $status): Message
    {
        $this->assertDemo($conversation);

        if ($message->conversation_id !== $conversation->id || $message->direction !== 'out' || ! $message->simulated) {
            throw new ConversationStateException('Solo se simulan estados de mensajes salientes simulados.');
        }

        $message->update(['status' => $status, 'failure_reason' => $status === 'failed' ? 'Fallo simulado: no se envió nada a ningún proveedor.' : null]);

        return $message;
    }

    private function assertDemo(Conversation $conversation): void
    {
        abort_unless($conversation->isDemo(), 404);
    }
}
