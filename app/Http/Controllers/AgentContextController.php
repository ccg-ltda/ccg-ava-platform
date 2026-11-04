<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ChannelCatalog;
use App\Services\ConversationContext;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared context for an automation that runs on its own (a trigger inside n8n) instead of being started by Ava: it reads who has the conversation, the persisted
 * conversational state (facts with their source, steps, operations, the human handoff) and the recent history, and it may write what it learned back. The same
 * `ConversationContext` that fills the payload of an execution Ava starts, so both paths see one state. The conversation is looked up inside the chatbot of the token
 * (hence its Workspace); nothing is created here.
 */
class AgentContextController extends Controller
{
    public function __construct(private readonly ConversationContext $context, private readonly ConversationService $conversations, private readonly ChannelCatalog $channels) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate($this->identity() + ['limit' => ['nullable', 'integer', 'min:1', 'max:60'], 'after_id' => ['nullable', 'integer', 'min:0']]);
        $conversation = $this->find($request, $data);
        $limit = (int) ($data['limit'] ?? config('n8n.history_messages'));

        // `after_id` returns only what is newer than the last message the workflow processed (oldest first); without it, the latest `limit` messages.
        $afterId = isset($data['after_id']) ? (int) $data['after_id'] : null;
        $query = $conversation->messages()->whereNotNull('body')->where('sender', '!=', 'system');
        $rows = $afterId !== null ? $query->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get(['id', 'direction', 'sender', 'type', 'body', 'sent_at']) : $query->orderByDesc('id')->limit($limit)->get(['id', 'direction', 'sender', 'type', 'body', 'sent_at'])->reverse();
        $history = $rows->values()
            ->map(fn (Message $m) => ['id' => $m->id, 'sender' => $m->sender ?? ($m->direction === 'in' ? 'contact' : 'ai'), 'role' => $m->direction === 'in' ? 'user' : 'assistant', 'type' => $m->type, 'text' => $m->body, 'at' => $m->sent_at?->toIso8601String()])->all();

        return response()->json([
            'control' => $this->conversations->control($conversation),
            'context' => $this->context->forWorkflow($conversation),
            'history' => $history,
            'last_message_id' => $history === [] ? null : end($history)['id'],
        ])->header('Cache-Control', 'no-store');
    }

    /** Writes what the automation learned. Only the parts ConversationContext accepts are applied; malformed parts are ignored, never half-applied. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate($this->identity() + [
            'facts' => ['nullable', 'array', 'max:60'],
            'steps' => ['nullable', 'array', 'max:60'],
            'steps.*' => ['string', 'max:64'],
            'next_step' => ['nullable', 'string', 'max:64'],
            'operations' => ['nullable', 'array', 'max:40'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);
        $conversation = $this->find($request, $data);

        $this->context->apply($conversation, $data, 'ai');

        return response()->json(['context' => $this->context->forWorkflow($conversation->refresh())])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(collect($this->channels->all())->filter(fn (array $channel) => $channel['conversations'] ?? false)->pluck('key')->all())],
            'contact_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
        ];
    }

    private function find(Request $request, array $data)
    {
        $conversation = $request->attributes->get('chatbot')->conversations()->where('channel', $data['channel'])->where('contact_id', $data['contact_id'])->first();

        abort_unless($conversation, 404, 'No existe una conversación de ese contacto en este canal.');

        return $conversation;
    }
}
