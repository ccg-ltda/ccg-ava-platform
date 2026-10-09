<?php

namespace App\Services;

use App\Exceptions\ConversationStateException;
use App\Jobs\SendAgentMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A human agent writes to the contact. The message is stored in the SAME conversation (no second one) as `pending` and
 * a job hands it to the channel; the stored state is what the channel really answered. Only the agent who has the
 * conversation (or a manager) can write, and only while a human has it: the check runs under the conversation's row
 * lock, so a message cannot slip in after control went back to the AI.
 */
class ConversationMessenger
{
    public function __construct(
        private readonly ConversationControl $control,
        private readonly ChannelDelivery $delivery,
    ) {}

    public function send(Conversation $conversation, User $agent, bool $manage, string $text): Message
    {
        $text = trim($text);

        if ($text === '') {
            throw new ConversationStateException('Escribe un mensaje.');
        }

        $message = DB::transaction(function () use ($conversation, $agent, $manage, $text) {
            $locked = Conversation::with('chatbot')->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->handling !== Conversation::HUMAN) {
                throw new ConversationStateException('Toma la conversación antes de responder.');
            }

            if (! $this->control->isOperator($locked, $agent, $manage)) {
                throw new ConversationStateException('Esta conversación la atiende otro agente.');
            }

            $issue = $this->delivery->availability($locked);

            if (! $issue['ok']) {
                throw new ConversationStateException($issue['message']);
            }

            $message = $locked->messages()->create([
                'workspace_id' => $locked->workspace_id,
                'direction' => 'out',
                'sender' => 'agent',
                'sender_user_id' => $agent->id,
                'type' => 'text',
                'body' => $text,
                'status' => Message::PENDING,
                'simulated' => $locked->isDemo(),
                'sent_at' => now(),
            ]);

            $locked->forceFill(['last_message_at' => $message->sent_at])->save();

            return $message;
        });

        SendAgentMessage::dispatch($message->id);

        return $message->refresh();
    }
}
