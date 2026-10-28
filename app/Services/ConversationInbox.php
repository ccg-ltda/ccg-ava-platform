<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Privacy\Mask;
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
        private readonly ConversationOperations $operations,
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
    public function list(Builder $scope, ?string $handling, string $search, WorkspaceSetting $settings, bool $mask = false): array
    {
        $query = (clone $scope)->with(['chatbot:id,name,workspace_id', 'chatbot.workspace:id,code', 'assignedUser:id,name'])
            ->when(in_array($handling, Conversation::HANDLING, true), fn (Builder $q) => $q->where('conversations.handling', $handling))
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')->limit(self::LIST_LIMIT);

        if ($search !== '') {
            // "!" is the escape character so the text cannot act as a LIKE wildcard.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw("lower(conversations.contact_id) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(conversations.contact_name, '')) like ? escape '!'", [$like]));
        }

        $conversations = $query->get();
        $origins = app(SupportDesk::class)->origins($conversations);
        $last = Message::whereIn('id', Message::selectRaw('max(id)')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id'))->get()->keyBy('conversation_id');

        return $conversations->map(fn (Conversation $conversation) => [
            'id' => $conversation->id,
            'contactId' => $mask ? Mask::identifier($conversation->contact_id) : $conversation->contact_id,
            'contactName' => $mask ? null : $conversation->contact_name,
            'channel' => $this->channel($conversation),
            'chatbot' => ['id' => $conversation->chatbot->id, 'name' => $conversation->chatbot->name],
            'handling' => $conversation->handling,
            'demo' => $conversation->isDemo(),
            'assignedName' => $conversation->assignedUser?->name,
            // A client's request for support: who wrote and from which Workspace (read back from the conversation key), so it is never mistaken for a conversation of one of CCG's own customers.
            'support' => $origins[$conversation->id] ?? null,
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
    public function detail(Conversation $conversation, WorkspaceSetting $settings, User $user, array $permissions, bool $foreign, bool $mask = false): array
    {
        $newest = $conversation->messages()->with(['senderUser:id,name', 'attachments'])->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGE_LIMIT + 1)->get();
        $delivery = $this->delivery->availability($conversation);

        return [
            'id' => $conversation->id,
            'contactId' => $mask ? Mask::identifier($conversation->contact_id) : $conversation->contact_id,
            'contactName' => $mask ? null : $conversation->contact_name,
            'channel' => $this->channel($conversation),
            'chatbot' => ['id' => $conversation->chatbot->id, 'name' => $conversation->chatbot->name],
            'handling' => $conversation->handling,
            'demo' => $conversation->isDemo(),
            'support' => app(SupportDesk::class)->origins(collect([$conversation]))[$conversation->id] ?? null,
            'assignedUser' => $conversation->assignedUser ? ['id' => $conversation->assignedUser->id, 'name' => $conversation->assignedUser->name, 'isMe' => $conversation->assignedUser->is($user)] : null,
            'handoffReason' => $conversation->handoff_reason,
            // A finished support session: why, who (null = the clock) and when. The messages and files stay readable here for CCG.
            'closed' => $conversation->closed_at ? ['reason' => $conversation->closed_reason, 'label' => SupportSessions::REASONS[$conversation->closed_reason] ?? 'Finalizada', 'by' => $conversation->closed_by_user_id ? User::whereKey($conversation->closed_by_user_id)->value('name') : null, 'at' => $settings->formatDate($conversation->closed_at).' '.$settings->formatTime($conversation->closed_at)] : null,
            // The collected data and what was already done (read only; the agent changes it through conversations.context.store). Never for a Workspace seen read only.
            'context' => $foreign || $mask ? null : app(ConversationContext::class)->forDisplay($conversation),
            'handoffAt' => $conversation->handoff_requested_at ? $settings->formatDate($conversation->handoff_requested_at).' '.$settings->formatTime($conversation->handoff_requested_at) : null,
            'delivery' => $delivery,
            // The demo bench: only on a demo conversation, in a demo environment, for who manages conversations.
            'simulate' => ! $foreign && $conversation->isDemo() && $this->demo->enabled() && in_array('use-conversation-demo', $permissions, true),
            'can' => $foreign ? $this->nothing() : $this->abilities($conversation, $user, $permissions, $delivery['ok']),
            // What was done from this conversation (appointments, orders): never on another Workspace seen read only, and only what the user may open.
            'operations' => $foreign ? [] : $this->operations->for($conversation, $permissions, $settings),
            // The tools the assistant ran for this conversation, as traced (see AgentToolTrace): operational facts only.
            'tools' => $foreign ? [] : $this->operations->tools($conversation, $settings),
            // «Esta respuesta no me gustó»: only for who may change the assistant, in the Workspace of the session and while its plan includes Asistentes. The blocks give the starting text of the proposal.
            'feedback' => ! $foreign && ChatbotFeedback::ready() && in_array('edit-chatbots', $permissions, true) && app(ModuleAccess::class)->allowsAll($conversation->chatbot->workspace, ['assistants'])
                ? ['blocks' => collect(app(ChatbotInstructions::class)->blocks($conversation->chatbot))->whereIn('key', ChatbotInstructions::blocksOf(app(ChatbotInstructions::class)->mode($conversation->chatbot)))->map(fn (array $block) => ['key' => $block['key'], 'label' => $block['label'], 'text' => $block['text']])->values()->all()]
                : null,
            'truncated' => $newest->count() > self::MESSAGE_LIMIT,
            'items' => $newest->take(self::MESSAGE_LIMIT)->reverse()->values()->map(fn (Message $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'sender' => $message->sender,
                'senderName' => $message->sender === 'agent' ? $message->senderUser?->name : null,
                'type' => $message->type,
                'body' => $message->body,
                'mediaMime' => $message->media_mime,
                // Files sent with the message (support requests). Each url is a route that checks the conversation and the Workspace on every read; a Workspace seen read only gets none.
                'attachments' => $foreign ? [] : $message->attachments->map(fn ($a) => MessageAttachments::present($a, route('conversations.attachments', ['conversation' => $conversation->id, 'attachment' => $a->id], false)))->all(),
                'status' => $message->status,
                // The time of each state is its own: sent (`time`), delivered and read are the channel's confirmations (null when the channel gave none).
                'deliveredTime' => $message->delivered_at ? $settings->formatTime($message->delivered_at) : null,
                'readTime' => $message->read_at ? $settings->formatTime($message->read_at) : null,
                'simulated' => $message->simulated,
                'failureReason' => in_array($message->status, ['failed', Message::UNCONFIRMED], true) ? $message->failure_reason : null,
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
        // Each action has its own permission: holding one never allows another.
        $can = fn (string $permission) => in_array($permission, $permissions, true);
        $manage = $can('manage-conversations');
        $handling = $conversation->handling;
        $operator = $this->control->isOperator($conversation, $user, $manage);

        return [
            'take' => $can('take-conversations') && in_array($handling, [Conversation::AI, Conversation::PENDING], true),
            'reply' => $can(ConversationAgents::PERMISSION) && $handling === Conversation::HUMAN && $operator && $deliverable,
            // A support request has no assistant behind it to hand it back to.
            'release' => $can('release-conversations') && ! app(SupportDesk::class)->isRequest($conversation) && $this->control->aiAvailable($conversation) && (($handling === Conversation::HUMAN && $operator) || $handling === Conversation::PENDING),
            'resolve' => $can('resolve-conversations') && $handling !== Conversation::RESOLVED && ($handling !== Conversation::HUMAN || $operator),
            'assign' => $manage && $handling !== Conversation::RESOLVED,
            // Recording or correcting the collected data: only the agent who has the conversation (or a manager), like writing to the contact.
            'context' => $can(ConversationAgents::PERMISSION) && $handling === Conversation::HUMAN && $operator,
        ];
    }

    /** @return array{take: false, reply: false, release: false, resolve: false, assign: false, context: false} */
    private function nothing(): array
    {
        return ['take' => false, 'reply' => false, 'release' => false, 'resolve' => false, 'assign' => false, 'context' => false];
    }

    /** @return array{key: string, label: string} */
    private function channel(Conversation $conversation): array
    {
        // A client's request for support travels over the web transport but is NOT a web conversation: it is shown (and filtered by people) as Soporte. The signal is the server's
        // own (SupportDesk::isRequest: the reserved key only the signed-in panel can create, in the administrative Workspace), never the channel name.
        if (app(SupportDesk::class)->isRequest($conversation)) {
            return ['key' => 'support', 'label' => 'Soporte'];
        }

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
