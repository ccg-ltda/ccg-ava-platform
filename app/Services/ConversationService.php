<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WorkspaceSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The conversations of ONE chatbot on a channel. Ava does not talk to the channel: the automation (n8n) tells Ava each
 * message and each delivery state (`record`, `updateStatus`), and the Workspace reads them (`list`, `messages`).
 * Everything starts from the chatbot, which belongs to one Workspace, so no query can reach another Workspace's data.
 */
class ConversationService
{
    private const LIST_LIMIT = 50;

    private const MESSAGE_LIMIT = 200;

    public function __construct(private readonly ChannelCatalog $channels) {}

    /** Whether the channel keeps conversations in Ava (config/chatbots.php `conversations`). */
    public function supports(string $channel): bool
    {
        return (bool) ($this->channels->get($channel)['conversations'] ?? false);
    }

    /**
     * Stores a message of a contact (creating the conversation on the first one). A message whose `external_id` is
     * already stored is not stored twice (the automation may retry); its delivery state is refreshed instead.
     *
     * @param  array{contact_id: string, contact_name?: ?string, direction: string, type: string, body?: ?string, media_mime?: ?string, external_id?: ?string, status?: ?string, sent_at: CarbonInterface}  $data
     * @return array{message: Message, created: bool}
     */
    public function record(Chatbot $chatbot, string $channel, array $data): array
    {
        return DB::transaction(function () use ($chatbot, $channel, $data) {
            $conversation = $chatbot->conversations()->firstOrCreate(
                ['channel' => $channel, 'contact_id' => $data['contact_id']],
                ['workspace_id' => $chatbot->workspace_id],
            );

            if (filled($data['external_id'] ?? null) && $existing = $conversation->messages()->where('external_id', $data['external_id'])->first()) {
                if (filled($data['status'] ?? null)) {
                    $existing->update(['status' => $data['status']]);
                }

                return ['message' => $existing, 'created' => false];
            }

            $message = $conversation->messages()->create([
                'workspace_id' => $chatbot->workspace_id,
                'direction' => $data['direction'],
                'type' => $data['type'],
                'body' => $data['body'] ?? null,
                'media_mime' => $data['media_mime'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'status' => $data['direction'] === 'out' ? ($data['status'] ?? null) : null,
                'sent_at' => $data['sent_at'],
            ]);

            // The name a contact has today; the list is ordered by the newest message, wherever it came from.
            $conversation->forceFill([
                'contact_name' => filled($data['contact_name'] ?? null) ? $data['contact_name'] : $conversation->contact_name,
                'last_message_at' => max($conversation->last_message_at, $data['sent_at']),
            ])->save();

            return ['message' => $message, 'created' => true];
        });
    }

    /** Updates the delivery state of an outgoing message the automation already reported; false when there is none. */
    public function updateStatus(Chatbot $chatbot, string $channel, string $contactId, string $externalId, string $status): bool
    {
        $message = Message::where('external_id', $externalId)->where('direction', 'out')
            ->whereHas('conversation', fn ($q) => $q->where('chatbot_id', $chatbot->id)->where('channel', $channel)->where('contact_id', $contactId))
            ->first();

        $message?->update(['status' => $status]);

        return $message !== null;
    }

    /**
     * The newest conversations of the chatbot on the channel, optionally filtered by a text found in the contact's
     * name or number. Each carries a short preview of its last message.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Chatbot $chatbot, string $channel, string $search, WorkspaceSetting $settings): array
    {
        $query = $chatbot->conversations()->where('channel', $channel)->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::LIST_LIMIT);

        if ($search !== '') {
            // "!" is the escape character so the text cannot act as a LIKE wildcard.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw("lower(contact_id) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(contact_name, '')) like ? escape '!'", [$like]));
        }

        $conversations = $query->get();
        $last = Message::whereIn('id', Message::selectRaw('max(id)')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id'))->get()->keyBy('conversation_id');

        return $conversations->map(fn (Conversation $conversation) => [
            'id' => $conversation->id,
            'contactId' => $conversation->contact_id,
            'contactName' => $conversation->contact_name,
            'lastAt' => $conversation->last_message_at ? $settings->formatDate($conversation->last_message_at).' '.$settings->formatTime($conversation->last_message_at) : null,
            'preview' => $this->preview($last->get($conversation->id)),
        ])->all();
    }

    /**
     * The newest messages of a conversation, oldest first.
     *
     * @return array{items: list<array<string, mixed>>, truncated: bool}
     */
    public function messages(Conversation $conversation, WorkspaceSetting $settings): array
    {
        $newest = $conversation->messages()->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGE_LIMIT + 1)->get();
        $truncated = $newest->count() > self::MESSAGE_LIMIT;

        return [
            'truncated' => $truncated,
            'items' => $newest->take(self::MESSAGE_LIMIT)->reverse()->values()->map(fn (Message $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'type' => $message->type,
                'body' => $message->body,
                'mediaMime' => $message->media_mime,
                'status' => $message->status,
                'day' => $settings->formatDate($message->sent_at),
                'time' => $settings->formatTime($message->sent_at),
            ])->all(),
        ];
    }

    /** The conversation of this chatbot and channel, or a 404: another chatbot's or Workspace's id does not exist from here. */
    public function find(Chatbot $chatbot, string $channel, int $id): Conversation
    {
        return $chatbot->conversations()->where('channel', $channel)->findOrFail($id);
    }

    private function preview(?Message $message): ?string
    {
        if (! $message) {
            return null;
        }

        $label = ['audio' => 'Audio', 'image' => 'Imagen', 'video' => 'Video', 'document' => 'Documento', 'sticker' => 'Sticker', 'location' => 'Ubicación', 'other' => 'Mensaje'][$message->type] ?? null;
        $text = filled($message->body) ? mb_substr(preg_replace('/\s+/', ' ', $message->body), 0, 80) : null;

        return ($message->direction === 'out' ? 'Tú: ' : '').($label ? ($text ? "{$label} · {$text}" : $label) : $text);
    }
}
