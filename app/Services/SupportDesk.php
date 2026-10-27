<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Support for the clients, inside the module Conversaciones of the administrative Workspace (`DESARROLLO_DEV`). There is no second system:
 *  - the conversation is a normal `web` conversation of a chatbot of the administrative Workspace, so CCG's team finds, takes, answers and resolves it in the inbox it already uses;
 *  - the DESTINATION is decided here, on the server, and nothing the browser sends can change it: the administrative Workspace by its code, and in it the chatbot of CCG's public
 *    chat when that channel is configured and live (so the person has ONE conversation whichever door they use), otherwise a dedicated chatbot (`support.desk_chatbot_name`,
 *    created inactive the first time it is needed: it never answers, only people do);
 *  - the conversation of a person is keyed `c{workspace id}u{user id}` (the same key the public widget uses for a signed-in member): who wrote and from which Workspace is read
 *    back from it, never from anything the browser says. Only a member with the role `admin` of an active CLIENT Workspace can use it (CCG's own team answers, it does not ask);
 *  - writing never claims more than it knows: a new message leaves the conversation «pending» (waiting for a person) and the person sees «En espera» until somebody of the team
 *    takes it, then «Atiende {name}». Nothing is called a transfer to a person that has not happened.
 */
class SupportDesk
{
    /** The role of a Workspace administrator in `workspace_user.role` (the role the system seeds with the whole catalog of its Workspace). */
    public const ADMIN_ROLE = 'admin';

    /**
     * The shape of the conversation key of a signed-in client (`c{workspace}u{user}`). It is RESERVED: the public widget refuses it as a visitor's session id, so nobody can
     * create or read such a conversation by choosing a session id, and a conversation with this key can only have been created by the signed-in panel or the signed context token.
     */
    public const RESERVED_KEY = '/^c\d+u\d+(x\d+)?$/';

    public function __construct(
        private readonly SupportChannel $support,
        private readonly ConversationService $conversations,
        private readonly ConversationControl $control,
        private readonly MessageAttachments $attachments,
    ) {}

    /** Whether the signed-in member of the request may use the support desk: an administrator of an active client Workspace. */
    public function eligible(Request $request): bool
    {
        $workspace = $request->attributes->get('workspace');

        return $request->user() !== null
            && $workspace instanceof Workspace
            && ! $workspace->isAdministrative()
            && $request->attributes->get('workspace_role') === self::ADMIN_ROLE;
    }

    /** The prop shared with every page: null for who may not use it (the button is not drawn), otherwise whether the desk can receive requests right now. */
    public function forRequest(Request $request): ?array
    {
        if (! $this->eligible($request)) {
            return null;
        }

        return ['state' => $this->available() ? 'ready' : 'unavailable', 'poll' => (int) config('support.desk_poll_seconds'), 'attachments' => MessageAttachments::limits()];
    }

    /** Whether the administrative Workspace that receives the requests exists and is active (reads only workspaces: every page can ask). */
    public function available(): bool
    {
        return $this->adminWorkspace() !== null;
    }

    private function adminWorkspace(): ?Workspace
    {
        return Workspace::available()->where('workspaces.code', Workspace::normalizeCode((string) config('workspace.admin_code')))->first();
    }

    /** The chatbot of the administrative Workspace that receives the requests, or null when there is none to receive them. */
    public function destination(): ?Chatbot
    {
        $admin = $this->adminWorkspace();

        if (! $admin) {
            return null;
        }

        // CCG's public chat, when it is configured and live, keeps one conversation per person across the public widget and this panel.
        $key = $this->support->configuredKey();

        if ($key !== null && $this->support->problem() === null && ($channel = ChatbotChannel::with('chatbot')->where('public_key', $key)->first())) {
            return $channel->chatbot;
        }

        $name = (string) config('support.desk_chatbot_name');

        try {
            // Created without events: the creation is not an action of the client administrator who happened to need it first, and must not be audited as theirs.
            return Chatbot::withoutEvents(fn () => $admin->chatbots()->firstOrCreate(['name' => $name], ['description' => 'Recibe las solicitudes de soporte de los clientes. No responde con IA: las atiende el equipo.', 'is_active' => false]));
        } catch (Throwable $e) {
            Log::error('support_desk_destination_failed', ['exception' => $e::class]);

            return null;
        }
    }

    /** The key of a person's conversation: derived on the server from the session, never read from the request. */
    public static function contactId(User $user, Workspace $workspace): string
    {
        return 'c'.$workspace->id.'u'.$user->id;
    }

    /** Whether the conversation is a client's support request: identified (`c{w}u{u}`), `web`, and in the administrative Workspace. */
    public function isRequest(Conversation $conversation): bool
    {
        return $conversation->channel === 'web'
            && preg_match(self::RESERVED_KEY, (string) $conversation->contact_id) === 1
            && $conversation->chatbot->workspace->isAdministrative();
    }

    /**
     * The conversation of this person and what the team has done with it, for the panel. Reading marks what the team wrote as delivered.
     *
     * @return array{state: string, label: string, agent: ?string, messages: list<array<string, mixed>>}
     */
    public function thread(User $user, Workspace $workspace, ?int $session = null, bool $seen = false): array
    {
        $conversation = $this->find($user, $workspace);

        // The session this browser was showing ended (the person, the team or the clock closed it): it is told so, once, and the next message starts a NEW one.
        if ($session !== null && ($conversation === null || $conversation->id !== $session) && ($ended = $this->ended($user, $workspace, $session))) {
            return ['state' => 'closed', 'label' => SupportSessions::notice($ended->closed_reason), 'agent' => null, 'session' => $ended->id, 'reason' => $ended->closed_reason, 'messages' => $this->messages($ended)];
        }

        if (! $conversation) {
            return ['state' => 'none', 'label' => 'Aún no has enviado ninguna solicitud.', 'agent' => null, 'session' => null, 'messages' => []];
        }

        // What the team wrote is «delivered» once the person's panel has it, and «read» only when the panel is open and VISIBLE (`seen`): a background tab or a poll proves nothing.
        $team = $conversation->messages()->where('direction', 'out')->where('sender', 'agent');
        (clone $team)->where('status', 'sent')->update(['status' => 'delivered', 'delivered_at' => now()]);

        if ($seen) {
            (clone $team)->whereIn('status', ['sent', 'delivered'])->update(['status' => 'read', 'read_at' => now(), 'delivered_at' => DB::raw('coalesce(delivered_at, CURRENT_TIMESTAMP)')]);
        }
        $conversation->load('assignedUser:id,name');

        [$state, $label] = match ($conversation->handling) {
            Conversation::HUMAN => ['attending', 'Te atiende '.($conversation->assignedUser?->name ?? 'una persona del equipo').'.'],
            Conversation::RESOLVED => ['resolved', 'El equipo marcó esta conversación como resuelta. Si escribes de nuevo, se reabre.'],
            default => ['waiting', 'Recibido. Esperando a que una persona del equipo tome tu solicitud: aún nadie la ha atendido.'],
        };

        return [
            'state' => $state,
            'label' => $label,
            'agent' => $conversation->handling === Conversation::HUMAN ? $conversation->assignedUser?->name : null,
            'session' => $conversation->id,
            'messages' => $this->messages($conversation),
        ];
    }

    /**
     * Stores the message in the person's conversation (created on the first one) and, if nobody of the team has it, leaves it waiting for a person.
     * A repeated `messageId` (a double tap, a retried request) is stored once.
     *
     * @param  list<UploadedFile>  $files  screenshots, videos or PDFs: checked, stored and tied to this message in one step (a message never claims a file that was not saved)
     * @return array{duplicate: bool}
     *
     * @throws ValidationException when the desk cannot receive requests or a file is refused
     */
    public function send(User $user, Workspace $workspace, string $text, string $messageId, array $files = [], ?int $session = null): array
    {
        $chatbot = $this->destination();

        // The browser was showing a session that has ended since: nothing is added to it and nothing is lost silently, the person is told and starts a new one.
        // The open session is looked up FIRST: one that went quiet is closed here, so this message can never revive it (it starts the person's new conversation).
        $current = $this->find($user, $workspace);

        if ($session !== null && ($current?->id !== $session)) {
            throw ValidationException::withMessages(['message' => 'Esta conversación ya fue finalizada. Inicia una nueva para escribir al equipo.']);
        }

        if ($chatbot === null) {
            throw ValidationException::withMessages(['message' => 'El soporte no está disponible en este momento. Usa el correo de soporte.']);
        }

        $checked = $this->attachments->inspect($files); // refused before anything is written
        $written = [];

        try {
            $recorded = DB::transaction(function () use ($chatbot, $workspace, $user, $text, $messageId, $checked, &$written) {
                $first = $checked[0]['kind'] ?? null;
                $type = $text !== '' || $first === null ? 'text' : ['image' => 'image', 'video' => 'video', 'pdf' => 'document'][$first];
                $recorded = $this->conversations->record($chatbot, 'web', [
                    'contact_id' => self::contactId($user, $workspace),
                    'contact_name' => mb_substr("{$workspace->name} · {$user->name}", 0, 100),
                    'direction' => 'in', 'type' => $type, 'body' => $text !== '' ? $text : null, 'media_mime' => $checked[0]['mime'] ?? null, 'sent_at' => now(),
                    'external_id' => 's:'.$messageId,
                ]);

                // A repeated message id (a double tap, a retry) is stored once, files included.
                if ($recorded['created'] && $checked !== []) {
                    $written = $this->attachments->put($recorded['conversation'], $checked);
                    $this->attachments->attach($recorded['message'], $written, $user);
                }

                return $recorded;
            });
        } catch (Throwable $e) {
            $this->attachments->forget($written); // the message was rolled back with its rows: no file is left behind

            throw $e;
        }

        $conversation = $recorded['conversation'];

        if (! $recorded['created']) {
            return ['duplicate' => true];
        }

        $conversation->load('chatbot.workspace');

        if ($conversation->messages()->count() === 1) {
            $this->support->notify('new_conversation', $conversation);
        }

        // A support request is for people: it never waits on an assistant. «Pending» means exactly that — a person has been asked for, nobody has taken it yet.
        if ($conversation->handling === Conversation::AI) {
            $this->control->requestHuman($conversation, 'Solicitud de soporte desde el panel de un cliente.');
        }

        return ['duplicate' => false];
    }

    /**
     * Who wrote and from which Workspace, for the inbox of the team, read back from the conversation key. Null when it is not a client's request.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array{workspaceName: ?string, workspaceCode: ?string, userName: ?string}>
     */
    public function origins(Collection $conversations): array
    {
        $requests = $conversations->filter(fn (Conversation $c) => $this->isRequest($c));
        $pairs = $requests->mapWithKeys(function (Conversation $c) {
            preg_match('/^c(\d+)u(\d+)(?:x\d+)?$/', $c->contact_id, $m);

            return [$c->id => [(int) $m[1], (int) $m[2]]];
        });
        $workspaces = Workspace::whereIn('id', $pairs->pluck(0)->unique())->get(['id', 'name', 'code'])->keyBy('id');
        $users = User::whereIn('id', $pairs->pluck(1)->unique())->get(['id', 'name'])->keyBy('id');

        return $pairs->map(fn (array $pair) => [
            'workspaceName' => $workspaces->get($pair[0])?->name,
            'workspaceCode' => $workspaces->get($pair[0])?->code,
            'userName' => $users->get($pair[1])?->name,
        ])->all();
    }

    /** An attachment of THIS person's own conversation, or a 404: the id alone gives access to nothing. */
    public function attachmentFor(User $user, Workspace $workspace, int $id): MessageAttachment
    {
        $conversation = $this->find($user, $workspace);
        abort_if($conversation === null, 404);

        return MessageAttachment::where('conversation_id', $conversation->id)->where('workspace_id', $conversation->workspace_id)->findOrFail($id);
    }

    /** Ends the person's OPEN session (their own: the key comes from the session, not from the request). Returns the thread, which then says it ended. */
    public function finish(User $user, Workspace $workspace, int $session): array
    {
        $conversation = $this->find($user, $workspace);

        if ($conversation !== null && $conversation->id === $session) {
            app(SupportSessions::class)->close($conversation, 'client', $user);
        }

        return $this->thread($user, $workspace, $session);
    }

    /** A session of THIS person that already ended, or null: its archived key carries the person's own, so another person's id gives nothing. */
    private function ended(User $user, Workspace $workspace, int $session): ?Conversation
    {
        return Conversation::whereKey($session)->where('contact_id', self::contactId($user, $workspace).'x'.$session)->whereNotNull('closed_at')->first();
    }

    /** The person's OPEN session (a finished one is never found: its key was archived). One that went quiet is closed here, so a stopped scheduler never keeps it alive. */
    private function find(User $user, Workspace $workspace): ?Conversation
    {
        $chatbot = $this->destination();
        $conversation = $chatbot?->conversations()->where('channel', 'web')->where('contact_id', self::contactId($user, $workspace))->whereNull('closed_at')->first();

        return $conversation && app(SupportSessions::class)->closeIfIdle($conversation) ? null : $conversation;
    }

    /** @return list<array<string, mixed>> */
    private function messages(Conversation $conversation): array
    {
        return $conversation->messages()->with(['senderUser:id,name', 'attachments'])->whereIn('sender', ['contact', 'agent'])->orderByDesc('id')->limit(100)->get()->reverse()->values()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'mine' => $m->sender === 'contact',
                'author' => $m->sender === 'agent' ? ($m->senderUser?->name ?? 'Soporte') : null,
                'text' => (string) $m->body,
                'attachments' => $m->attachments->map(fn (MessageAttachment $a) => MessageAttachments::present($a, route('support.attachments', $a->id, false)))->all(),
                'at' => $m->sent_at?->toIso8601String(),
            ])->all();
    }
}
