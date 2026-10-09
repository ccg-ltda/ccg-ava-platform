<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the Workspace reads and can do on its conversations: the inbox (list, search, filter by who answers, counts), a
 * conversation with its messages and the actions the current user has on it. Every query starts from `scope()`, which is
 * bound to one Workspace (and optionally one chatbot and channel), so an id of another Workspace does not exist here.
 * The abilities are only what the interface offers: the server checks the same rules again on every action.
 */
class ConversationInbox
{
    private const LIST_LIMIT = 50;

    private const MESSAGE_LIMIT = 200;

    public function __construct(
        private readonly ChannelCatalog $channels,
        private readonly ConversationControl $control,
        private readonly ChannelDelivery $delivery,
        private readonly ConversationAgents $agents,
        private readonly DemoConversations $demo,
    ) {}

    /** Whether the channel keeps conversations in Ava (config/chatbots.php `conversations`). */
    public function supports(string $channel): bool
    {
        return (bool) config("chatbots.channels.{$channel}.conversations", false);
    }

    /** @return Builder<Conversation> the conversations of the Workspace, optionally of one chatbot and one channel */
    public function scope(Workspace $workspace, ?Chatbot $chatbot = null, ?string $channel = null): Builder
    {
        return Conversation::query()->where('conversations.workspace_id', $workspace->id)
            ->whereIn('conversations.channel', collect($this->channels->all())->filter(fn (array $c) => $c['conversations'] ?? false)->pluck('key')->all())
            ->when($chatbot, fn (Builder $q) => $q->where('conversations.chatbot_id', $chatbot->id))
            ->when($channel, fn (Builder $q) => $q->where('conversations.channel', $channel));
    }

    /** The conversation within the scope, or a 404: another Workspace's, chatbot's or channel's id does not exist from here. */
    public function find(Builder $scope, int $id): Conversation
    {
        return (clone $scope)->with(['chatbot', 'assignedUser'])->findOrFail($id);
    }

    /** @return array<string, int> how many conversations are in each state (every state is present) */
    public function counts(Builder $scope): array
    {
        $counts = (clone $scope)->selectRaw('conversations.handling, count(*) as total')->groupBy('conversations.handling')->pluck('total', 'handling');

        return collect(Conversation::HANDLING)->mapWithKeys(fn (string $handling) => [$handling => (int) ($counts[$handling] ?? 0)])->all();
    }

    /**
     * The newest conversations, optionally of one state and filtered by a text found in the contact's name or number.
     * Each carries a short preview of its last message.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Builder $scope, ?string $handling, string $search, WorkspaceSetting $settings): array
    {
        $query = (clone $scope)->with(['chatbot:id,name', 'assignedUser:id,name'])
            ->when(in_array($handling, Conversation::HANDLING, true), fn (Builder $q) => $q->where('conversations.handling', $handling))
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')->limit(self::LIST_LIMIT);

        if ($search !== '') {
            // "!" is the escape character so the text cannot act as a LIKE wildcard.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw("lower(conversations.contact_id) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(conversations.contact_name, '')) like ? escape '!'", [$like]));
        }

        $conversations = $query->get();
        $last = Message::whereIn('id', Message::selectRaw('max(id)')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id'))->get()->keyBy('conversation_id');

        return $conversations->map(fn (Conversation $conversation) => [
            'id' => $conversation->id,
            'contactId' => $conversation->contact_id,
            'contactName' => $conversation->contact_name,
            'channel' => $this->channel($conversation),
            'chatbot' => ['id' => $conversation->chatbot->id, 'name' => $conversation->chatbot->name],
            'handling' => $conversation->handling,
            'demo' => $conversation->isDemo(),
            'assignedName' => $conversation->assignedUser?->name,
            'lastAt' => $conversation->last_message_at ? $settings->formatDate($conversation->last_message_at).' '.$settings->formatTime($conversation->last_message_at) : null,
            'preview' => $this->preview($last->get($conversation->id)),
        ])->all();
    }

    /**
     * One conversation with its newest messages (oldest first), who has it and what the current user may do with it.
     * `$foreign` is a Workspace seen read only: nothing can be done there.
     *
     * @param  list<string>  $permissions  the permissions of the user in the ACTIVE Workspace
     * @return array<string, mixed>
     */
    public function detail(Conversation $conversation, WorkspaceSetting $settings, User $user, array $permissions, bool $foreign): array
    {
        $newest = $conversation->messages()->with('senderUser:id,name')->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGE_LIMIT + 1)->get();
        $delivery = $this->delivery->availability($conversation);

        return [
            'id' => $conversation->id,
            'contactId' => $conversation->contact_id,
            'contactName' => $conversation->contact_name,
            'channel' => $this->channel($conversation),
            'chatbot' => ['id' => $conversation->chatbot->id, 'name' => $conversation->chatbot->name],
            'handling' => $conversation->handling,
            'demo' => $conversation->isDemo(),
            'assignedUser' => $conversation->assignedUser ? ['id' => $conversation->assignedUser->id, 'name' => $conversation->assignedUser->name, 'isMe' => $conversation->assignedUser->is($user)] : null,
            'handoffReason' => $conversation->handoff_reason,
            'handoffAt' => $conversation->handoff_requested_at ? $settings->formatDate($conversation->handoff_requested_at).' '.$settings->formatTime($conversation->handoff_requested_at) : null,
            'delivery' => $delivery,
            // The demo bench: only on a demo conversation, in a demo environment, for who manages conversations.
            'simulate' => ! $foreign && $conversation->isDemo() && $this->demo->enabled() && in_array('manage-conversations', $permissions, true),
            'can' => $foreign ? $this->nothing() : $this->abilities($conversation, $user, $permissions, $delivery['ok']),
            'truncated' => $newest->count() > self::MESSAGE_LIMIT,
            'items' => $newest->take(self::MESSAGE_LIMIT)->reverse()->values()->map(fn (Message $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'sender' => $message->sender,
                'senderName' => $message->sender === 'agent' ? $message->senderUser?->name : null,
                'type' => $message->type,
                'body' => $message->body,
                'mediaMime' => $message->media_mime,
                'status' => $message->status,
                'simulated' => $message->simulated,
                'failureReason' => $message->status === 'failed' ? $message->failure_reason : null,
                'day' => $settings->formatDate($message->sent_at),
                'time' => $settings->formatTime($message->sent_at),
            ])->all(),
        ];
    }

    /** The agents a manager can assign a conversation to (empty for anyone who cannot assign). */
    public function assignableAgents(Workspace $workspace, array $permissions, bool $foreign): array
    {
        return ! $foreign && in_array('manage-conversations', $permissions, true)
            ? $this->agents->of($workspace)->map(fn (User $agent) => ['id' => $agent->id, 'name' => $agent->name])->all()
            : [];
    }

    /** @return array{take: bool, reply: bool, release: bool, resolve: bool, assign: bool} */
    private function abilities(Conversation $conversation, User $user, array $permissions, bool $deliverable): array
    {
        $reply = in_array(ConversationAgents::PERMISSION, $permissions, true);
        $manage = in_array('manage-conversations', $permissions, true);
        $handling = $conversation->handling;
        $operator = $this->control->isOperator($conversation, $user, $manage);

        return [
            'take' => $reply && in_array($handling, [Conversation::AI, Conversation::PENDING], true),
            'reply' => $reply && $handling === Conversation::HUMAN && $operator && $deliverable,
            'release' => $reply && (($handling === Conversation::HUMAN && $operator) || $handling === Conversation::PENDING),
            'resolve' => $reply && $handling !== Conversation::RESOLVED && ($handling !== Conversation::HUMAN || $operator),
            'assign' => $manage && $handling !== Conversation::RESOLVED,
        ];
    }

    /** @return array{take: false, reply: false, release: false, resolve: false, assign: false} */
    private function nothing(): array
    {
        return ['take' => false, 'reply' => false, 'release' => false, 'resolve' => false, 'assign' => false];
    }

    /** @return array{key: string, label: string} */
    private function channel(Conversation $conversation): array
    {
        return ['key' => $conversation->channel, 'label' => $this->channels->get($conversation->channel)['label']];
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
