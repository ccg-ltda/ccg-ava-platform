<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public const RESERVED_KEY = '/^c\d+u\d+$/';

    public function __construct(
        private readonly SupportChannel $support,
        private readonly ConversationService $conversations,
        private readonly ConversationControl $control,
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

        return ['state' => $this->available() ? 'ready' : 'unavailable', 'poll' => (int) config('support.desk_poll_seconds')];
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
    public function thread(User $user, Workspace $workspace): array
    {
        $conversation = $this->find($user, $workspace);

        if (! $conversation) {
            return ['state' => 'none', 'label' => 'Aún no has enviado ninguna solicitud.', 'agent' => null, 'messages' => []];
        }

        $conversation->messages()->where('direction', 'out')->where('sender', 'agent')->where('status', 'sent')->update(['status' => 'delivered']);
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
            'messages' => $this->messages($conversation),
        ];
    }

    /**
     * Stores the message in the person's conversation (created on the first one) and, if nobody of the team has it, leaves it waiting for a person.
     * A repeated `messageId` (a double tap, a retried request) is stored once.
     *
     * @return array{duplicate: bool}
     *
     * @throws ValidationException when the desk cannot receive requests
     */
    public function send(User $user, Workspace $workspace, string $text, string $messageId): array
    {
        $chatbot = $this->destination();

        if ($chatbot === null) {
            throw ValidationException::withMessages(['message' => 'El soporte no está disponible en este momento. Usa el correo de soporte.']);
        }

        $recorded = $this->conversations->record($chatbot, 'web', [
            'contact_id' => self::contactId($user, $workspace),
            'contact_name' => mb_substr("{$workspace->name} · {$user->name}", 0, 100),
            'direction' => 'in', 'type' => 'text', 'body' => $text, 'sent_at' => now(),
            'external_id' => 's:'.$messageId,
        ]);
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
            preg_match('/^c(\d+)u(\d+)$/', $c->contact_id, $m);

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

    private function find(User $user, Workspace $workspace): ?Conversation
    {
        $chatbot = $this->destination();

        return $chatbot?->conversations()->where('channel', 'web')->where('contact_id', self::contactId($user, $workspace))->first();
    }

    /** @return list<array<string, mixed>> */
    private function messages(Conversation $conversation): array
    {
        return $conversation->messages()->with('senderUser:id,name')->whereIn('sender', ['contact', 'agent'])->orderByDesc('id')->limit(100)->get()->reverse()->values()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'mine' => $m->sender === 'contact',
                'author' => $m->sender === 'agent' ? ($m->senderUser?->name ?? 'Soporte') : null,
                'text' => (string) $m->body,
                'at' => $m->sent_at?->toIso8601String(),
            ])->all();
    }
}
