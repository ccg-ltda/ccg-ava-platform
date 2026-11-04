<?php

namespace App\Services;

use App\Models\AgentToolCall;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Observability\ApiRedactor;

/**
 * Records the steps an automation engine (n8n, later Lava Studio) REPORTS about the API calls it makes by itself, so the technical activity of a conversation
 * shows them next to the tools Ava serves. One step = one row of `agent_tool_calls` with `source = reported`.
 *
 * - Idempotent: the engine sends a stable `step_ref` (for example `{executionId}:{node}:{runIndex}`). The first report creates the row, later reports with the
 *   same key UPDATE it (request at the start, answer at the end), so a retry never duplicates a step. A report that arrives after the step was finished never
 *   moves it back to `started` and never overwrites the answer already stored.
 * - Honest: Ava stores what was sent, redacted, and invents nothing. A step without an answer stays `started` («pendiente»).
 * - Isolated: the chatbot (and the Workspace) comes from the token; the conversation is looked up inside that chatbot, and a trigger message must belong to it.
 */
class ActivityStepReporter
{
    /** The statuses an engine may report; `started` means the call is still open. */
    public const STATUSES = [AgentToolCall::STARTED, AgentToolCall::SUCCEEDED, AgentToolCall::FAILED, AgentToolCall::REJECTED, AgentToolCall::UNCERTAIN];

    public function __construct(private readonly ApiRedactor $redactor, private readonly AgentToolTrace $trace) {}

    /**
     * @param  array<string, mixed>  $data  validated report (see AgentActivityController)
     * @return array{call: AgentToolCall, created: bool}
     */
    public function report(Chatbot $chatbot, Conversation $conversation, array $data): array
    {
        $existing = AgentToolCall::where('workspace_id', $chatbot->workspace_id)->where('external_ref', $data['step_ref'])->first();

        if ($existing && ($existing->conversation_id !== $conversation->id || $existing->chatbot_id !== $chatbot->id)) {
            abort(409, 'Esa clave de paso ya pertenece a otra conversación.');
        }

        $attributes = $this->attributes($chatbot, $conversation, $data);

        if (! $existing) {
            return ['call' => AgentToolCall::create($attributes), 'created' => true];
        }

        // A finished step is final: a replay of its start (or of the same finish) changes nothing.
        if ($existing->status !== AgentToolCall::STARTED) {
            return ['call' => $existing, 'created' => false];
        }

        $existing->update(array_filter($attributes, fn ($value) => $value !== null));

        return ['call' => $existing->refresh(), 'created' => false];
    }

    /** @return array<string, mixed> */
    private function attributes(Chatbot $chatbot, Conversation $conversation, array $data): array
    {
        $r = $this->redactor;
        $method = strtoupper((string) ($data['method'] ?? '')) ?: null;
        $startedAt = isset($data['started_at']) ? now()->parse($data['started_at']) : null;
        $finishedAt = isset($data['finished_at']) ? now()->parse($data['finished_at']) : null;
        $status = $data['status'] ?? AgentToolCall::STARTED;
        $http = $data['http_status'] ?? null;
        $duration = $data['duration_ms'] ?? ($startedAt && $finishedAt ? (int) $startedAt->diffInMilliseconds($finishedAt) : null);

        $request = array_key_exists('request_body', $data) ? $this->payload($data['request_body']) : null;
        $response = array_key_exists('response_body', $data) ? $this->payload($data['response_body']) : null;

        return array_filter([
            'workspace_id' => $chatbot->workspace_id,
            'chatbot_id' => $chatbot->id,
            'conversation_id' => $conversation->id,
            'execution_ref' => $this->executionRef($chatbot, $conversation, $data['ava_execution_id'] ?? null),
            'execution_id' => $data['execution_id'] ?? null,
            'external_ref' => $data['step_ref'],
            'source' => 'reported',
            'engine' => $data['engine'],
            'tool' => substr((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower($data['tool'])), 0, 40) ?: 'external',
            'tool_label' => $data['tool_label'] ?? $data['tool'],
            'kind' => in_array($method, [null, 'GET', 'HEAD'], true) ? 'read' : 'write',
            'auth' => 'chatbot',
            'status' => $status,
            'http_status' => $http,
            'reason' => $this->reason($status, $http),
            'trigger_message_id' => $this->triggerMessage($conversation, $data['trigger_message_external_id'] ?? null),
            'method' => $method,
            'endpoint' => isset($data['endpoint']) ? $this->endpoint($data['endpoint']) : null,
            'request_headers' => isset($data['request_headers']) ? json_encode($r->headers($data['request_headers'])) : null,
            'request_query' => isset($data['request_query']) ? json_encode($r->data($data['request_query']), JSON_UNESCAPED_UNICODE) : null,
            'request_body' => $request[0] ?? null,
            'response_headers' => isset($data['response_headers']) ? json_encode($r->headers($data['response_headers'])) : null,
            'response_body' => $response[0] ?? null,
            'truncated' => ($request[1] ?? false) || ($response[1] ?? false),
            'error_class' => isset($data['error_class']) ? substr($data['error_class'], 0, 120) : null,
            'error_message' => isset($data['error_message']) ? $r->message($data['error_message']) : null,
            'duration_ms' => $duration,
            'started_at' => $startedAt,
            'finished_at' => $status === AgentToolCall::STARTED ? null : ($finishedAt ?? now()),
        ], fn ($value) => $value !== null);
    }

    /**
     * Bodies arrive as JSON (an object/array) or as text; both go through the redactor and the cap of a step.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function payload(mixed $value): array
    {
        $raw = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->redactor->body($raw === false ? null : $raw, ApiRedactor::STEP_BYTES);
    }

    /** Scheme, host and path of the URL the engine called; the values of sensitive query parameters are masked. */
    private function endpoint(string $url): string
    {
        $parts = $this->redactor->url($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return substr(($scheme && $parts['host'] !== '' ? $scheme.'://' : '').$parts['host'].$parts['path'], 0, 512);
    }

    /** A fixed code per kind of outcome, never provider text. */
    private function reason(string $status, ?int $http): ?string
    {
        return match (true) {
            $status === AgentToolCall::SUCCEEDED, $status === AgentToolCall::STARTED => null,
            $http === null => 'no_response',
            default => $this->trace->reasonForStatus($http),
        };
    }

    /** Links the step to Ava's own run only when the engine names a correlation id that really belongs to this conversation. */
    private function executionRef(Chatbot $chatbot, Conversation $conversation, mixed $correlation): ?string
    {
        if (! is_string($correlation)) {
            return null;
        }

        return ChatbotExecution::where('workspace_id', $chatbot->workspace_id)->where('conversation_id', $conversation->id)->where('correlation_id', $correlation)->value('correlation_id');
    }

    private function triggerMessage(Conversation $conversation, mixed $externalId): ?int
    {
        return is_string($externalId) ? $conversation->messages()->where('external_id', $externalId)->value('id') : null;
    }
}
