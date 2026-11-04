<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Integration;
use App\Services\Concerns\StartsChannelConversations;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * What Meta's webhook tells Ava about Instagram Messaging (`object: instagram`) and Messenger (`object: page`), already authenticated by the controller (signature of the raw body).
 * The account id of each `entry` is the only thing taken from the payload, and only to LOOK UP the integration Ava itself holds for it: the chatbot, the Workspace and the channel come
 * from those records; an account that is unknown, claimed by two integrations or switched off is ignored, never guessed. Messages go through the same `ConversationService::record` as
 * every other source (the message id is the idempotency key, so a repeated delivery stores nothing twice) and only a NEW message of the contact can start an execution.
 *
 * Handled: text messages and the TYPE of an attachment (the file itself is not downloaded). Not handled yet, and not claimed: delivery and read receipts, reactions, postbacks and
 * echoes of messages the owner of the account sent (those are ignored so Ava never answers itself).
 */
class MetaMessagingInbound
{
    use StartsChannelConversations;

    /** Meta's attachment types and the message types Ava stores for them. */
    private const ATTACHMENTS = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'document', 'location' => 'location'];

    public function __construct(private readonly ConversationService $conversations) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload): void
    {
        $channel = match ($payload['object'] ?? null) {
            'instagram' => 'instagram',
            'page' => 'messenger',
            default => null,
        };

        if ($channel === null) {
            return;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $accountId = is_array($entry) && is_scalar($entry['id'] ?? null) ? (string) $entry['id'] : '';

            if ($accountId === '' || ! ($chatbot = $this->chatbotFor($channel, $accountId))) {
                continue;
            }

            foreach ($entry['messaging'] ?? [] as $event) {
                $this->message($chatbot, $channel, $accountId, is_array($event) ? $event : []);
            }
        }
    }

    /** The chatbot that answers for an account, through the Workspace's own integration; null unless exactly one is live. */
    private function chatbotFor(string $channel, string $accountId): ?Chatbot
    {
        $integrations = Integration::where('type', $channel)->where('is_active', true)->where('config->account_id', $accountId)->get();

        if ($integrations->count() !== 1) {
            if ($integrations->count() > 1) {
                Log::warning('meta_webhook_ambiguous_account', ['channel' => $channel, 'integrations' => $integrations->pluck('id')->all()]);
            }

            return null;
        }

        return $this->chatbotForIntegration($integrations->first(), $channel);
    }

    /** @param  array<string, mixed>  $event */
    private function message(Chatbot $chatbot, string $channel, string $accountId, array $event): void
    {
        $message = $event['message'] ?? null;
        $from = is_scalar($event['sender']['id'] ?? null) ? (string) $event['sender']['id'] : '';
        $id = is_array($message) ? ($message['mid'] ?? null) : null;

        // An echo is a message the owner of the account sent (from the app or through the API): never a message of a contact.
        if (! is_array($message) || ($message['is_echo'] ?? false) || $from === $accountId || ! preg_match('/^[A-Za-z0-9._:=-]{3,64}$/', $from) || ! is_string($id) || $id === '' || strlen($id) > 255) {
            return;
        }

        $text = is_string($message['text'] ?? null) ? $message['text'] : null;
        $type = $text !== null ? 'text' : (self::ATTACHMENTS[$message['attachments'][0]['type'] ?? ''] ?? 'other');

        $result = $this->conversations->record($chatbot, $channel, [
            'contact_id' => $from,
            'contact_name' => null,
            'direction' => 'in',
            'type' => $type,
            'body' => $text !== null ? mb_substr($text, 0, 4096) : null,
            'external_id' => $id,
            'sent_at' => $this->moment($event['timestamp'] ?? null),
        ]);

        if ($result['created']) {
            $this->startExecution($result['conversation'], $result['message']);
        }
    }

    /** Meta sends the time in milliseconds; an absent or future one is «now». */
    private function moment(mixed $value): Carbon
    {
        $seconds = is_numeric($value) ? ((float) $value > 1e11 ? (int) ((float) $value / 1000) : (int) $value) : null;
        $moment = $seconds !== null ? Carbon::createFromTimestamp($seconds) : now();

        return $moment->greaterThan(now()) ? now() : $moment;
    }
}
