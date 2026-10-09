<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ViewsWorkspace;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Services\ChannelCatalog;
use App\Services\ChatbotService;
use App\Services\ConversationInbox;
use App\Services\DemoConversations;
use App\Services\WorkspaceScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inbox of the Workspace's conversations, for every chatbot and channel (`conversations.index`) or for one channel
 * of one chatbot, which also shows the technical connection (`chatbots.conversations`). It is one page: list, filters by
 * who answers, the chat and the actions of human attention (see ConversationActionController). Needs
 * `view-conversations`; every lookup starts from the Workspace being shown, so an id of another Workspace, chatbot or
 * channel is a 404. An administrator of the platform may look at one other Workspace with `?workspace=` (read only).
 */
class ConversationController extends Controller
{
    use ViewsWorkspace;

    public function __construct(
        private readonly ConversationInbox $inbox,
        private readonly ChatbotService $chatbots,
        private readonly ChannelCatalog $channels,
        private readonly DemoConversations $demo,
        WorkspaceScope $scope,
    ) {
        $this->scope = $scope;
    }

    public function index(Request $request, ?int $chatbot = null, ?string $channel = null): Response
    {
        [$viewing, $foreign] = $this->viewing($request);
        $bot = $chatbot !== null ? $viewing->chatbots()->findOrFail($chatbot) : null;

        abort_unless($channel === null || $this->inbox->supports($channel), 404);

        // On the shared inbox the chatbot and the channel are optional filters; an unknown one is simply not applied.
        $filterBot = $bot ?? $this->filterChatbot($request, $viewing);
        $filterChannel = $channel ?? $this->filterChannel($request);
        $scope = $this->inbox->scope($viewing, $filterBot, $filterChannel);

        $settings = $viewing->settingsOrDefault();
        $permissions = $request->attributes->get('workspace_permissions', []);
        $selected = $request->query('c');
        $conversation = is_string($selected) && ctype_digit($selected) ? $this->inbox->find($scope, (int) $selected) : null;

        // A malformed or foreign `c` is not "no selection": it does not exist.
        abort_if($selected !== null && $conversation === null, 404);

        $status = $this->status($request);
        $search = $this->text($request->query('q'));

        return Inertia::render('Conversations/Index', [
            'chatbot' => $bot ? ['id' => $bot->id, 'name' => $bot->name, 'isActive' => $bot->is_active, 'avatarUrl' => $this->chatbots->form($bot, $foreign)['avatarUrl']] : null,
            'channel' => $bot && $channel ? $this->channelProps($bot, $channel) : null,
            'conversations' => $this->inbox->list($scope, $status, $search, $settings),
            'counts' => $this->inbox->counts($scope),
            'selected' => $conversation ? $this->inbox->detail($conversation, $settings, $request->user(), $permissions, $foreign) : null,
            'agents' => $conversation ? $this->inbox->assignableAgents($viewing, $permissions, $foreign) : [],
            'filters' => ['q' => $search, 'status' => $status ?? 'all', 'channel' => $filterChannel, 'chatbot' => $filterBot?->id],
            'options' => $bot ? null : [
                'channels' => collect($this->channels->all())->filter(fn (array $c) => $c['conversations'] ?? false)->map(fn (array $c) => ['value' => $c['key'], 'label' => $c['label']])->values()->all(),
                'chatbots' => $viewing->chatbots()->orderBy('name')->get(['id', 'name'])->map(fn (Chatbot $c) => ['value' => $c->id, 'label' => $c->name])->all(),
            ],
            // The demo tools: only in a demo environment, and managed only from the administrative Workspace itself.
            'demo' => $this->demo->enabled() ? ['canManage' => ! $foreign && $viewing->isAdministrative() && in_array('manage-conversations', $permissions, true)] + $this->demo->counts($viewing) : null,
            'reportEndpoint' => url('/api/agent/messages'),
            'canConfigure' => ! $foreign && in_array('manage-settings', $permissions, true),
            'scope' => $this->scopeProps($request, $viewing, $foreign, 'manage-chatbots'),
        ]);
    }

    /** @return array<string, mixed> */
    private function channelProps(Chatbot $chatbot, string $channel): array
    {
        $state = collect($this->chatbots->channelStates($chatbot))->firstWhere('key', $channel);

        return [
            'key' => $channel,
            'label' => $this->channels->get($channel)['label'],
            'state' => $state['state'],
            'connection' => $state['connection'],
            'account' => $this->chatbots->channelAccount($chatbot, $channel),
        ];
    }

    private function status(Request $request): ?string
    {
        $status = $request->query('status');

        return is_string($status) && in_array($status, Conversation::HANDLING, true) ? $status : null;
    }

    private function filterChannel(Request $request): ?string
    {
        $channel = $request->query('channel');

        return is_string($channel) && $this->inbox->supports($channel) ? $channel : null;
    }

    private function filterChatbot(Request $request, $workspace): ?Chatbot
    {
        $id = $request->query('chatbot');

        return is_string($id) && ctype_digit($id) ? $workspace->chatbots()->find((int) $id) : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }
}
