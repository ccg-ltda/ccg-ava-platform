<?php

namespace App\Services;

use App\Models\Conversation;
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

        return ['poll' => (int) config('support.alerts_poll_seconds')];
    }

    public function allowed(Request $request, Workspace $workspace): bool
    {
        return in_array('view-conversations', $request->attributes->get('workspace_permissions', []), true) && $this->modules->allows($workspace, 'conversations');
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
