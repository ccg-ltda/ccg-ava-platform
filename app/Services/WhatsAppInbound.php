<?php

namespace App\Services;

use App\Booking\AppointmentNotifier;
use App\Booking\AppointmentReplies;
use App\Jobs\SendAgentMessage;
use App\Models\AppointmentReply;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Services\Concerns\StartsChannelConversations;
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
    use StartsChannelConversations;

    private const MEDIA = ['audio', 'image', 'video', 'document', 'sticker', 'location'];

    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  ?Integration  $only  the integration whose webhook received the event: events about any other number are ignored
     */
    public function handle(array $payload, ?Integration $only = null): void
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

                if ($only !== null && $phoneId !== (string) ($only->config['phone_number_id'] ?? '')) {
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

        return $this->chatbotForIntegration($integrations->first(), 'whatsapp');
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

            // A button the contact pressed (a template's quick reply or an interactive reply) is the contact's own message: its title is the text.
            $button = $this->buttonReply($message);
            $type = $button !== null ? 'text' : ($message['type'] ?? 'other');
            $type = $type === 'text' || in_array($type, self::MEDIA, true) ? $type : 'other';
            $body = $button['text'] ?? ($type === 'text' ? ($message['text']['body'] ?? null) : ($message[$message['type'] ?? '']['caption'] ?? null));
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

            // A button of an appointment reminder is an ANSWER to Agenda: it is applied (once, however many times Meta repeats the event) and only «Reprogramar» goes on to the workflow.
            if ($button !== null && AppointmentReplies::isOurs($button['payload'])) {
                $this->appointmentAnswer($chatbot, $from, $id, $button['payload'], $result);

                continue;
            }

            // A repeated event (Meta retries) is stored once and must not make the chatbot answer twice.
            if ($result['created']) {
                $this->startExecution($result['conversation'], $result['message']);
            }
        }
    }

    /** @param  array<string, mixed>  $message  @return array{payload: string, text: string}|null */
    private function buttonReply(array $message): ?array
    {
        $found = match ($message['type'] ?? null) {
            'button' => [$message['button']['payload'] ?? null, $message['button']['text'] ?? null],
            'interactive' => [$message['interactive']['button_reply']['id'] ?? null, $message['interactive']['button_reply']['title'] ?? null],
            default => null,
        };

        return $found !== null && is_string($found[0]) && $found[0] !== '' && strlen($found[0]) <= 256
            ? ['payload' => $found[0], 'text' => is_string($found[1]) ? mb_substr($found[1], 0, 200) : $found[0]]
            : null;
    }

    /** @param  array{message: Message, created: bool, conversation: Conversation}  $recorded */
    private function appointmentAnswer(Chatbot $chatbot, string $from, string $externalId, string $payload, array $recorded): void
    {
        try {
            $replies = app(AppointmentReplies::class);
            $answer = $replies->handle($chatbot, $from, $externalId, $payload, $recorded['conversation']->id);
        } catch (Throwable $e) {
            // Only the class: the exception text may carry the customer's data. Meta will retry the event, and the repeat guard makes that safe.
            Log::error('appointment_reply_failed', ['exception' => $e::class, 'conversation' => $recorded['conversation']->id]);

            return;
        }

        if ($answer === null) {
            return;
        }

        if ($recorded['created'] && in_array($answer['result'], [AppointmentReply::APPLIED, AppointmentReply::ALREADY_DONE], true)) {
            $recorded['message']->update(['body' => $replies->conversationText($answer['action'], $answer['appointment'])]);
        }

        if ($answer['action'] === 'reschedule' && $answer['result'] === AppointmentReply::APPLIED) {
            $this->startExecution($recorded['conversation'], $recorded['message']->refresh());

            return;
        }

        $this->acknowledge($recorded['conversation'], $replies->acknowledgement($answer['action'], $answer['result'], $answer['appointment']));
    }

    /**
     * The acknowledgement the customer gets for a button: one text message in the same conversation, from the system, handed to the channel like an agent's message (one attempt,
     * never resent). It is created only when `AppointmentReplies::handle` accepted a NEW answer, so a repeated webhook event never sends it twice.
     */
    private function acknowledge(Conversation $conversation, ?string $text): void
    {
        if ($text === null) {
            return;
        }

        try {
            $message = $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id, 'direction' => 'out', 'sender' => 'system', 'type' => 'text', 'body' => $text, 'status' => Message::PENDING, 'sent_at' => now(),
            ]);
            $conversation->forceFill(['last_message_at' => $message->sent_at])->save();
            SendAgentMessage::dispatchAfterResponse($message->id);
        } catch (Throwable $e) {
            Log::error('appointment_reply_ack_failed', ['exception' => $e::class, 'conversation' => $conversation->id]);
        }
    }

    /** @param  array<string, mixed>  $value */
    private function statuses(Chatbot $chatbot, array $value): void
    {
        foreach ($value['statuses'] ?? [] as $status) {
            $state = $status['status'] ?? null;

            if (in_array($state, Message::STATUSES, true) && is_string($status['id'] ?? null) && is_string($status['recipient_id'] ?? null)) {
                $this->settleUncertain($chatbot, $status, $state);
                $handled = $this->conversations->updateStatus($chatbot, 'whatsapp', $status['recipient_id'], $status['id'], $state);

                // A confirmation or reminder of an appointment (sent by Ava, not part of any conversation): matched by Meta's id or by Ava's own tag, inside this Workspace only.
                if (! $handled) {
                    app(AppointmentNotifier::class)->applyStatus($chatbot->workspace_id, $status);
                }
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
