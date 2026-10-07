<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ViewsWorkspace;
use App\Services\ChannelCatalog;
use App\Services\ChatbotService;
use App\Services\ConversationService;
use App\Services\WorkspaceScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operation page of a chatbot's channel: the conversations the automation reported and their messages, read only
 * (the replies are sent by n8n, not from here). Needs `view-conversations`; the chatbot and the conversation are looked
 * up inside the Workspace being shown, so an id of another Workspace (or of another chatbot or channel) is a 404. An
 * administrator of the platform may look at one other Workspace with `?workspace=`, like the rest of the module.
 */
class ConversationController extends Controller
{
    use ViewsWorkspace;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChatbotService $chatbots,
        private readonly ChannelCatalog $channels,
        WorkspaceScope $scope,
    ) {
        $this->scope = $scope;
    }

    public function index(Request $request, int $chatbot, string $channel): Response
    {
        [$viewing, $foreign] = $this->viewing($request);
        $chatbot = $viewing->chatbots()->findOrFail($chatbot);

        abort_unless($this->conversations->supports($channel), 404);

        $settings = $viewing->settingsOrDefault();
        $search = $this->text($request->query('q'));
        $selected = $request->query('c');
        $conversation = is_string($selected) && ctype_digit($selected) ? $this->conversations->find($chatbot, $channel, (int) $selected) : null;

        // A malformed or foreign `c` is not "no selection": it does not exist.
        abort_if($selected !== null && $conversation === null, 404);

        $definition = $this->channels->get($channel);
        $state = collect($this->chatbots->channelStates($chatbot))->firstWhere('key', $channel);

        return Inertia::render('Conversations/Index', [
            'chatbot' => ['id' => $chatbot->id, 'name' => $chatbot->name, 'isActive' => $chatbot->is_active, 'avatarUrl' => $this->chatbots->form($chatbot, $foreign)['avatarUrl']],
            'channel' => [
                'key' => $channel,
                'label' => $definition['label'],
                'state' => $state['state'],
                'connection' => $state['connection'],
                'account' => $this->chatbots->channelAccount($chatbot, $channel),
            ],
            'conversations' => $this->conversations->list($chatbot, $channel, $search, $settings),
            'selected' => $conversation ? [
                'id' => $conversation->id,
                'contactId' => $conversation->contact_id,
                'contactName' => $conversation->contact_name,
            ] + $this->conversations->messages($conversation, $settings) : null,
            'filters' => ['q' => $search],
            'reportEndpoint' => url('/api/agent/messages'),
            'canConfigure' => ! $foreign && in_array('manage-settings', $request->attributes->get('workspace_permissions', []), true),
            'scope' => $this->scopeProps($request, $viewing, $foreign, 'manage-chatbots'),
        ]);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }
}
