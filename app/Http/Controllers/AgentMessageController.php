<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ChannelCatalog;
use App\Services\ConversationControl;
use App\Services\ConversationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Where the automation (n8n) tells Ava what happened on a channel: each message (`store`) and each delivery state
 * (`status`), and where it asks who answers a contact (`authorizeReply`) or asks for a person (`handoff`). The chatbot, and through it the Workspace, comes from the token (see AuthenticateAgent); the caller only
 * names the channel and the contact. A message is accepted only for a channel the chatbot currently has switched on.
 */
class AgentMessageController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ChannelCatalog $channels,
        private readonly ConversationControl $control,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in($this->conversationChannels())],
            'contact_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'direction' => ['required', Rule::in(Message::DIRECTIONS)],
            'type' => ['nullable', Rule::in(Message::TYPES)],
            'body' => ['nullable', 'string', 'max:4096'],
            'media_mime' => ['nullable', 'string', 'max:100', 'regex:/^[\w.+-]+\/[\w.+-]+$/'],
            'external_id' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', Rule::in(Message::STATUSES)],
            'timestamp' => ['nullable'],
        ]);

        $chatbot = $request->attributes->get('chatbot');
        $this->assertChannelActive($request, $data['channel']);

        $result = $this->conversations->record($chatbot, $data['channel'], [
            'contact_id' => $data['contact_id'],
            'contact_name' => $data['contact_name'] ?? null,
            'direction' => $data['direction'],
            'type' => $data['type'] ?? 'text',
            'body' => $data['body'] ?? null,
            'media_mime' => $data['media_mime'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'status' => $data['status'] ?? null,
            'sent_at' => $this->moment($data['timestamp'] ?? null),
        ]);

        // A message the automation already reported (a retry) must not make it answer a second time.
        $control = $this->conversations->control($result['conversation']);
        $control['ai_allowed'] = $control['ai_allowed'] && $result['created'] && $data['direction'] === 'in';

        return response()->json(['id' => $result['message']->id, 'created' => $result['created'], 'control' => $control], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store');
    }

    /**
     * "May I answer this contact?" The automation asks right before it sends an automatic reply. `version` is the one
     * it received when it reported the message it is answering: if control changed meanwhile, the reply is stale.
     */
    public function authorizeReply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in($this->conversationChannels())],
            'contact_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
            'version' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->assertChannelActive($request, $data['channel']);

        return response()->json(['control' => $this->conversations->currentControl($request->attributes->get('chatbot'), $data['channel'], $data['contact_id'], isset($data['version']) ? (int) $data['version'] : null)])
            ->header('Cache-Control', 'no-store');
    }

    /** The automation asks for a person: the conversation waits for an agent and the AI stops answering it. */
    public function handoff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in($this->conversationChannels())],
            'contact_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $chatbot = $request->attributes->get('chatbot');
        $this->assertChannelActive($request, $data['channel']);

        $conversation = $chatbot->conversations()->firstOrCreate(
            ['channel' => $data['channel'], 'contact_id' => $data['contact_id']],
            ['workspace_id' => $chatbot->workspace_id, 'contact_name' => $data['contact_name'] ?? null],
        );

        return response()->json(['control' => $this->conversations->control($this->control->requestHuman($conversation, $data['reason'] ?? null))])
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in($this->conversationChannels())],
            'contact_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
            'external_id' => ['required', 'string', 'max:128'],
            'status' => ['required', Rule::in(Message::STATUSES)],
        ]);

        $this->assertChannelActive($request, $data['channel']);

        $found = $this->conversations->updateStatus($request->attributes->get('chatbot'), $data['channel'], $data['contact_id'], $data['external_id'], $data['status']);

        abort_unless($found, 404, 'No hay un mensaje saliente con ese identificador.');

        return response()->json(['updated' => true])->header('Cache-Control', 'no-store');
    }

    /** @return list<string> the channels that keep conversations in Ava */
    private function conversationChannels(): array
    {
        return collect($this->channels->all())->filter(fn (array $channel) => $channel['conversations'] ?? false)->pluck('key')->all();
    }

    private function assertChannelActive(Request $request, string $channel): void
    {
        $active = $request->attributes->get('chatbot')->channels()->where('channel', $channel)->where('is_active', true)->exists();

        if (! $active) {
            throw ValidationException::withMessages(['channel' => 'Este chatbot no tiene activo ese canal.']);
        }
    }

    /** Accepts the channel's own timestamp (unix seconds) or an ISO 8601 date; never a moment in the future. */
    private function moment(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        try {
            $moment = is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages(['timestamp' => 'La fecha del mensaje no es válida.']);
        }

        return $moment->greaterThan(now()) ? now() : $moment;
    }
}
