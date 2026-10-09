<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Where messages enter the conversations of ONE chatbot on a channel. The automation (n8n) tells Ava each message and
 * each delivery state (`record`, `updateStatus`), the web widget is recorded by Ava itself, and the automation asks
 * `authorizeReply` before it answers. Reading them is `ConversationInbox`; who answers is `ConversationControl`.
 * Everything starts from the chatbot, which belongs to one Workspace, so no query can reach another Workspace's data.
 */
class ConversationService
{
    public function __construct(private readonly ConversationControl $control) {}

    /**
     * Stores a message (creating the conversation on the first one). A message whose `external_id` is already stored
     * is not stored twice (the automation may retry); its delivery state is refreshed instead. A message of the
     * contact on a resolved conversation opens it again with the AI.
     *
     * @param  array{contact_id: string, contact_name?: ?string, direction: string, type: string, body?: ?string, media_mime?: ?string, external_id?: ?string, status?: ?string, sent_at: CarbonInterface, sender?: ?string, simulated?: bool}  $data
     * @return array{message: Message, created: bool, conversation: Conversation}
     */
    public function record(Chatbot $chatbot, string $channel, array $data): array
    {
        return DB::transaction(function () use ($chatbot, $channel, $data) {
            $conversation = $chatbot->conversations()->firstOrCreate(
                ['channel' => $channel, 'contact_id' => $data['contact_id']],
                ['workspace_id' => $chatbot->workspace_id],
            );
            // The same row lock that control changes take, so a message never lands in the middle of one.
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->first();

            if (filled($data['external_id'] ?? null) && $existing = $conversation->messages()->where('external_id', $data['external_id'])->first()) {
                if (filled($data['status'] ?? null)) {
                    $existing->update(['status' => $data['status']]);
                }

                return ['message' => $existing, 'created' => false, 'conversation' => $conversation];
            }

            $incoming = $data['direction'] === 'in';

            $message = $conversation->messages()->create([
                'workspace_id' => $chatbot->workspace_id,
                'direction' => $data['direction'],
                'sender' => $data['sender'] ?? ($incoming ? 'contact' : 'ai'),
                'type' => $data['type'],
                'body' => $data['body'] ?? null,
                'media_mime' => $data['media_mime'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'status' => $incoming ? null : ($data['status'] ?? null),
                'simulated' => (bool) ($data['simulated'] ?? false),
                'sent_at' => $data['sent_at'],
            ]);

            // The name a contact has today; the list is ordered by the newest message, wherever it came from.
            $conversation->forceFill([
                'contact_name' => filled($data['contact_name'] ?? null) ? $data['contact_name'] : $conversation->contact_name,
                'last_message_at' => max($conversation->last_message_at, $data['sent_at']),
            ])->save();

            if ($incoming) {
                $this->control->reopen($conversation);
            }

            return ['message' => $message, 'created' => true, 'conversation' => $conversation];
        });
    }

    /** Updates the delivery state of an outgoing message that is already stored; false when there is none. */
    public function updateStatus(Chatbot $chatbot, string $channel, string $contactId, string $externalId, string $status): bool
    {
        $message = Message::where('external_id', $externalId)->where('direction', 'out')
            ->whereHas('conversation', fn ($q) => $q->where('chatbot_id', $chatbot->id)->where('channel', $channel)->where('contact_id', $contactId))
            ->first();

        $message?->update(['status' => $status]);

        return $message !== null;
    }

    /**
     * What the automation needs to know about who answers: `ai_allowed` is true only while the AI has the conversation.
     * `version` is the counter of changes of control the automation saw when it began to prepare its reply; if control
     * changed since, the reply is stale and not allowed even if the AI has the conversation again.
     *
     * @return array{mode: string, ai_allowed: bool, version: int}
     */
    public function control(Conversation $conversation, ?int $seenVersion = null): array
    {
        return [
            'mode' => $conversation->handling,
            'ai_allowed' => $conversation->aiMayReply() && ($seenVersion === null || $seenVersion === $conversation->handling_version),
            'version' => $conversation->handling_version,
        ];
    }

    /** The control state of a contact's conversation, as it is right now. A contact with no conversation yet is the AI's. */
    public function currentControl(Chatbot $chatbot, string $channel, string $contactId, ?int $seenVersion = null): array
    {
        $conversation = $chatbot->conversations()->where('channel', $channel)->where('contact_id', $contactId)->first();

        return $conversation
            ? $this->control($conversation, $seenVersion)
            : ['mode' => Conversation::AI, 'ai_allowed' => $seenVersion === null || $seenVersion === 0, 'version' => 0];
    }
}
