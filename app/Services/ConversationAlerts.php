<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Llegó algo nuevo» for the team: which inbound messages of the ACTIVE Workspace arrived after a cursor. The cursor is the id of the newest message the browser has seen
 * (messages are never reused or reordered, so it is reliable); a page that starts without one only learns where «now» is and announces nothing from the past. A reconnect after
 * a failure keeps its cursor, so it neither repeats nor loses events, and two tabs each ask for their own cursor (nothing is stored on the server per person).
 *
 * What it says is deliberately thin: that a conversation is new or a message arrived, from which channel and whether it is a support request, and the id of the conversation to open.
 * Never the text, the contact, a phone number or a file. Only messages written BY A CONTACT count (an agent's own reply, the assistant's answer and delivery-state updates are not
 * «new» for the team), simulated demo messages never do, and everything starts from the Workspace of the session, so nothing of another Workspace can show up.
 */
class ConversationAlerts
{
    public const LIMIT = 100;

    public function __construct(private readonly ConversationInbox $inbox, private readonly ModuleAccess $modules) {}

    /** The prop shared with every page: null for who cannot read conversations (nothing runs), otherwise how often to ask. */
    public function forRequest(Request $request): ?array
    {
        $workspace = $request->attributes->get('workspace');

        if (! $request->user() || ! $workspace instanceof Workspace || ! $this->allowed($request, $workspace)) {
            return null;
        }

        return ['poll' => (int) config('support.alerts_poll_seconds'), 'pending' => $this->pending($workspace, $request->user())];
    }

    public function allowed(Request $request, Workspace $workspace): bool
    {
        return in_array('view-conversations', $request->attributes->get('workspace_permissions', []), true) && in_array('conversations', $this->modules->forWorkspaceInRequest($request, $workspace), true);
    }

    /**
     * «Pendiente de respuesta»: how many CONVERSATIONS wait for a person's answer. A conversation counts when it is open (not closed or resolved, not a demo), a person is the one
     * who must answer (`pending`, nobody has taken it; or `human`, and it is MINE) and the last thing said in it was written by the contact. So it goes down by itself when someone
     * answers, takes it elsewhere or closes it, never because of a refresh or a visit, never counts the assistant's or the team's own messages, never counts a retry twice (it counts
     * conversations, not events) and never counts what the assistant is still answering.
     */
    public function pending(Workspace $workspace, User $user): int
    {
        return Conversation::query()->where('conversations.workspace_id', $workspace->id)
            ->whereIn('conversations.channel', collect(app(ChannelCatalog::class)->all())->filter(fn (array $c) => $c['conversations'] ?? false)->pluck('key')->all())
            ->whereNull('conversations.demo_key')->whereNull('conversations.closed_at')
            ->where(fn ($q) => $q->where('conversations.handling', Conversation::PENDING)->orWhere(fn ($mine) => $mine->where('conversations.handling', Conversation::HUMAN)->where('conversations.assigned_user_id', $user->id)))
            ->whereExists(fn ($last) => $last->select(DB::raw(1))->from('messages as m')
                ->whereColumn('m.conversation_id', 'conversations.id')->where('m.sender', 'contact')->where('m.simulated', false)
                ->whereRaw('m.id = (select max(x.id) from messages x where x.conversation_id = conversations.id and x.sender in (\'contact\', \'agent\', \'ai\'))'))
            ->count();
    }

    /** The id of the newest handoff notification visible to this user (0 when none): where the «needs your attention» cursor starts. */
    public function latestHandoff(Workspace $workspace, User $user, array $permissions): int
    {
        if (! Notifications::ready()) {
            return 0;
        }

        return (int) app(Notifications::class)->visible($workspace, $user, $permissions)->where('type', 'conversation_pending')->max('workspace_notifications.id');
    }

    /**
     * «A conversation needs your attention»: the handoff notifications (a real transition to a person, created once) after a cursor. Ids only, never content.
     *
     * @return array{cursor: int, events: list<array{conversationId: int, kind: string}>}
     */
    public function handoffsSince(Workspace $workspace, User $user, array $permissions, int $after): array
    {
        $latest = $this->latestHandoff($workspace, $user, $permissions);

        // The administrative Workspace keeps its own alerts (support requests and messages, see since()): a second toast for the same event would be a duplicate.
        if ($after > $latest || ! Notifications::ready() || $workspace->isAdministrative()) {
            return ['cursor' => $latest, 'events' => []];
        }

        $rows = app(Notifications::class)->visible($workspace, $user, $permissions)->where('type', 'conversation_pending')->where('workspace_notifications.id', '>', $after)
            ->orderBy('workspace_notifications.id')->limit(self::LIMIT)->get(['workspace_notifications.id', 'workspace_notifications.resource_id']);

        return ['cursor' => (int) ($rows->last()->id ?? $latest), 'events' => $rows->map(fn ($n) => ['conversationId' => (int) $n->resource_id, 'kind' => 'handoff'])->values()->all()];
    }

    /** The id of the newest message of the Workspace (0 when there is none). */
    public function latest(Workspace $workspace): int
    {
        return (int) DB::table('messages')->where('workspace_id', $workspace->id)->max('id');
    }

    /**
     * @return array{cursor: int, events: list<array{conversationId: int, kind: string, channel: string, support: bool, count: int}>}
     */
    public function since(Workspace $workspace, int $after): array
    {
        $latest = $this->latest($workspace);

        // A cursor from the future (the database was restored, or the id is forged) is not a reason to wait: start again from now.
        if ($after > $latest) {
            return ['cursor' => $latest, 'events' => []];
        }

        $rows = DB::table('messages')
            ->join('conversations', fn ($join) => $join->on('conversations.id', '=', 'messages.conversation_id')->on('conversations.workspace_id', '=', 'messages.workspace_id'))
            ->where('messages.workspace_id', $workspace->id)->where('messages.id', '>', $after)
            ->where('messages.direction', 'in')->where('messages.sender', 'contact')->where('messages.simulated', false)
            ->whereIn('conversations.channel', collect(app(ChannelCatalog::class)->all())->filter(fn (array $c) => $c['conversations'] ?? false)->pluck('key')->all())
            ->orderBy('messages.id')->limit(self::LIMIT)
            ->when(! $workspace->isAdministrative(), fn ($q) => $q->where('conversations.handling', '!=', Conversation::AI)) // a client's AI-handled message is not an alert: only what needs a person is
            ->get(['messages.id', 'messages.conversation_id', 'conversations.channel', 'conversations.contact_id', 'conversations.chatbot_id']);

        // Where to resume: the last row read, or the newest message if nothing qualified (so a long run of the team's own replies is not re-scanned every time).
        $cursor = $rows->count() === self::LIMIT ? (int) $rows->last()->id : $latest;
        $firsts = DB::table('messages')->where('workspace_id', $workspace->id)->whereIn('conversation_id', $rows->pluck('conversation_id')->unique())->groupBy('conversation_id')->selectRaw('conversation_id, min(id) as first_id')->pluck('first_id', 'conversation_id');
        $support = app(SupportDesk::class);
        $isSupport = Conversation::with('chatbot.workspace')->whereIn('id', $rows->pluck('conversation_id')->unique())->get()->mapWithKeys(fn (Conversation $c) => [$c->id => $support->isRequest($c)]);

        $events = $rows->groupBy('conversation_id')->map(function ($messages, $conversationId) use ($firsts, $isSupport) {
            $new = $messages->contains(fn ($message) => (int) $message->id === (int) $firsts[$conversationId]);

            return [
                'conversationId' => (int) $conversationId,
                'kind' => $new ? 'conversation' : 'message',
                'channel' => (string) $messages->first()->channel,
                'support' => (bool) ($isSupport[$conversationId] ?? false),
                'count' => $messages->count(),
            ];
        })->values()->all();

        return ['cursor' => $cursor, 'events' => $events];
    }
}
