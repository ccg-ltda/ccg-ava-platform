<?php

namespace App\Http\Controllers;

use App\Models\AgentToolCall;
use App\Services\ActivityStepReporter;
use App\Services\ChannelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where an automation engine (n8n today, Lava Studio later) REPORTS the API calls it made on its own for a conversation, one step per call. The chatbot, and through
 * it the Workspace, comes from the token; the caller only names the channel and the contact. Calling it twice with the same `step_ref` updates the same step
 * (request first, answer later) and never creates a second one. See ActivityStepReporter.
 */
class AgentActivityController extends Controller
{
    public function __construct(private readonly ActivityStepReporter $reporter, private readonly ChannelCatalog $channels) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in(collect($this->channels->all())->filter(fn (array $channel) => $channel['conversations'] ?? false)->pluck('key')->all())],
            'contact_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/'],
            'engine' => ['required', 'string', Rule::in(array_keys((array) config('chatbots.engines')))],
            'step_ref' => ['required', 'string', 'regex:/^[A-Za-z0-9:._-]{8,120}$/'],
            'execution_id' => ['nullable', 'string', 'max:64'],
            'ava_execution_id' => ['nullable', 'string', 'max:64'],
            'tool' => ['required', 'string', 'max:80'],
            'tool_label' => ['nullable', 'string', 'max:80'],
            'trigger_message_external_id' => ['nullable', 'string', 'max:128'],
            'method' => ['nullable', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'get', 'post', 'put', 'patch', 'delete', 'head', 'options'])],
            'endpoint' => ['nullable', 'string', 'max:2048'],
            'request_headers' => ['nullable', 'array', 'max:60'],
            'request_headers.*' => ['nullable'],
            'request_query' => ['nullable', 'array', 'max:60'],
            'request_body' => ['nullable'],
            'status' => ['nullable', 'string', Rule::in(ActivityStepReporter::STATUSES)],
            'http_status' => ['nullable', 'integer', 'between:100,599'],
            'response_headers' => ['nullable', 'array', 'max:60'],
            'response_headers.*' => ['nullable'],
            'response_body' => ['nullable'],
            'error_class' => ['nullable', 'string', 'max:120'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
        ]);

        $chatbot = $request->attributes->get('chatbot');
        $conversation = $chatbot->conversations()->where('channel', $data['channel'])->where('contact_id', $data['contact_id'])->first();

        abort_unless($conversation, 404, 'No existe una conversación de ese contacto en este canal. Reporta primero el mensaje.');

        ['call' => $call, 'created' => $created] = $this->reporter->report($chatbot, $conversation, $data);

        return response()->json([
            'id' => $call->id,
            'step_ref' => $call->external_ref,
            'status' => $call->status,
            'created' => $created,
            'finished' => $call->status !== AgentToolCall::STARTED,
        ], $created ? 201 : 200)->header('Cache-Control', 'no-store');
    }
}
