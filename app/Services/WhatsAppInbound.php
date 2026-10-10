<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What Meta's WhatsApp webhook tells Ava (messages and delivery states of a number), already authenticated by the
 * controller. The number's `phone_number_id` is the only thing taken from the payload, and only to LOOK UP the
 * integration that Ava itself holds for it: the chatbot, the Workspace and the channel come from those records, and a
 * number that is unknown, ambiguous (two integrations claim it) or switched off is ignored, never guessed. Messages go
 * through the same `ConversationService::record` as every other source (so a repeated event stores nothing twice) and
 * only a NEW message of the contact can start an execution.
 */
class WhatsAppInbound
{
    private const MEDIA = ['audio', 'image', 'video', 'document', 'sticker', 'location'];

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatbotExecutions $executions,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? null;

                if (($change['field'] ?? null) !== 'messages' || ! is_array($value) || ! is_string($phoneId = $value['metadata']['phone_number_id'] ?? null)) {
                    continue;
                }

                if ($chatbot = $this->chatbotFor($phoneId)) {
                    $this->messages($chatbot, $value);
                    $this->statuses($chatbot, $value);
                }
            }
        }
    }

    /** The chatbot that answers for a WhatsApp number, found through the Workspace's own integration; null unless exactly one is live. */
    private function chatbotFor(string $phoneId): ?Chatbot
    {
        $integrations = Integration::where('type', 'whatsapp')->where('is_active', true)->where('config->phone_number_id', $phoneId)->get();

        if ($integrations->count() !== 1) {
            if ($integrations->count() > 1) {
                Log::warning('whatsapp_webhook_ambiguous_number', ['integrations' => $integrations->pluck('id')->all()]);
            }

            return null;
        }

        $channel = ChatbotChannel::with('chatbot.workspace.organization')->where('integration_id', $integrations->first()->id)->where('channel', 'whatsapp')->where('is_active', true)->first();
        $chatbot = $channel?->chatbot;

        return $chatbot && $chatbot->is_active && $chatbot->workspace->is_active && $chatbot->workspace->organization->is_active ? $chatbot : null;
    }

    /** @param  array<string, mixed>  $value */
    private function messages(Chatbot $chatbot, array $value): void
    {
        $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($contact) => [(string) ($contact['wa_id'] ?? '') => $contact['profile']['name'] ?? null]);

        foreach ($value['messages'] ?? [] as $message) {
            $from = (string) ($message['from'] ?? '');
            $id = $message['id'] ?? null;

            if (! preg_match('/^[A-Za-z0-9._-]{3,64}$/', $from) || ! is_string($id) || $id === '' || strlen($id) > 128) {
                continue;
            }

            $type = $message['type'] ?? 'other';
            $type = $type === 'text' || in_array($type, self::MEDIA, true) ? $type : 'other';
            $body = $type === 'text' ? ($message['text']['body'] ?? null) : ($message[$message['type'] ?? '']['caption'] ?? null);
            $name = $names->get($from);

            $result = $this->conversations->record($chatbot, 'whatsapp', [
                'contact_id' => $from,
                'contact_name' => is_string($name) ? mb_substr($name, 0, 100) : null,
                'direction' => 'in',
                'type' => $type,
                'body' => is_string($body) ? mb_substr($body, 0, 4096) : null,
                'external_id' => $id,
                'sent_at' => $this->moment($message['timestamp'] ?? null),
            ]);

            // A repeated event (Meta retries) is stored once and must not make the chatbot answer twice.
            if ($result['created']) {
                $this->start($result['conversation'], $result['message']);
            }
        }
    }

    private function start(Conversation $conversation, Message $message): void
    {
        try {
            $this->executions->schedule($conversation->load('chatbot.workspace.organization'), $message);
        } catch (Throwable $e) {
            // The message is already stored: a problem starting the workflow must not make Meta resend the event. Only the
            // class is logged: the exception text may carry the message itself (a failed query lists its values).
            Log::error('chatbot_execution_schedule_failed', ['exception' => $e::class, 'conversation' => $conversation->id]);
        }
    }

    /** @param  array<string, mixed>  $value */
    private function statuses(Chatbot $chatbot, array $value): void
    {
        foreach ($value['statuses'] ?? [] as $status) {
            $state = $status['status'] ?? null;

            if (in_array($state, Message::STATUSES, true) && is_string($status['id'] ?? null) && is_string($status['recipient_id'] ?? null)) {
                $this->settleUncertain($chatbot, $status, $state);
                $this->conversations->updateStatus($chatbot, 'whatsapp', $status['recipient_id'], $status['id'], $state);
            }
        }
    }

    /**
     * Ava tags every message it sends with `ava:{message id}` and Meta echoes the tag in the delivery states
     * (`biz_opaque_callback_data`). A message whose send ended uncertain (no Meta id was ever recorded) is recognized
     * by it and takes Meta's id and state, so nobody has to resend it. Only a message of THIS chatbot, to THIS recipient,
     * with no Meta id yet and still pending or unconfirmed is touched; the tag alone proves nothing else.
     *
     * @param  array<string, mixed>  $status
     */
    private function settleUncertain(Chatbot $chatbot, array $status, string $state): void
    {
        $tag = $status['biz_opaque_callback_data'] ?? null;

        if (! is_string($tag) || ! preg_match('/^ava:(\d{1,18})$/', $tag, $match)) {
            return;
        }

        $message = Message::whereKey((int) $match[1])->where('direction', 'out')->whereNull('external_id')->whereIn('status', [Message::PENDING, Message::UNCONFIRMED])
            ->whereHas('conversation', fn ($q) => $q->where('chatbot_id', $chatbot->id)->where('channel', 'whatsapp')->where('contact_id', $status['recipient_id']))
            ->first();

        if (! $message) {
            return;
        }

        $message->update(['external_id' => $status['id'], 'status' => $state, 'failure_reason' => $state === 'failed' ? 'Meta informó que no pudo entregarlo.' : null]);
        ChatbotExecution::where('reply_message_id', $message->id)->whereIn('delivery', [ChatbotExecution::DELIVERY_SENDING, ChatbotExecution::DELIVERY_UNCERTAIN])
            ->update(['delivery' => $state === 'failed' ? ChatbotExecution::DELIVERY_FAILED : ChatbotExecution::DELIVERY_ACCEPTED]);
    }

    private function moment(mixed $value): Carbon
    {
        $moment = is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : now();

        return $moment->greaterThan(now()) ? now() : $moment;
    }
}
