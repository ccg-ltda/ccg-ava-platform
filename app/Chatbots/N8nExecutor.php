<?php

namespace App\Chatbots;

use App\Integrations\SafeHttpTarget;
use App\Integrations\UnsafeTarget;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Runs a chatbot's workflow in the platform's n8n through its production webhook. Ava calls n8n and the answer comes
 * back in that same response, so there is no callback to protect: what is accepted is the response to a request Ava
 * itself made for ONE execution, and it must echo that execution's correlation id.
 *
 * The request is signed (`X-Ava-Signature`: HMAC-SHA256 of `timestamp.body` with the shared secret) so the workflow can
 * refuse anything that does not come from Ava, and carries the correlation id (`X-Ava-Execution`) so n8n can ignore a
 * repeated delivery. No Workspace credential, Meta token or secret of any kind is ever part of the payload.
 */
class N8nExecutor implements ChatbotExecutor
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly SafeHttpTarget $target) {}

    public function run(ResolvedWorkflow $workflow, string $correlationId, array $payload): ExecutionOutcome
    {
        $base = config('n8n.execution_url');
        $secret = config('n8n.secret');

        if (! filled($base) || ! filled($secret)) {
            return ExecutionOutcome::permanent('not_configured');
        }

        $url = rtrim($base, '/').'/'.ltrim($workflow->path, '/');

        // The platform's n8n carries conversations: outside local development only over TLS.
        if (app()->isProduction() && ! str_starts_with(strtolower($url), 'https://')) {
            return ExecutionOutcome::permanent('insecure_target');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $headers = [
            'Accept' => 'application/json',
            'X-Ava-Execution' => $correlationId,
            'X-Ava-Timestamp' => $timestamp,
            'X-Ava-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
        ];

        try {
            $response = $this->target->client($this->target->resolve($url), (int) config('n8n.timeout'), $headers)->withBody($body, 'application/json')->post($url);
        } catch (UnsafeTarget) {
            return ExecutionOutcome::permanent('unsafe_target');
        } catch (ConnectionException) {
            return ExecutionOutcome::temporary('unreachable');
        } catch (Throwable) {
            return ExecutionOutcome::temporary('request_failed');
        }

        $status = $response->status();

        if (in_array($status, [408, 425, 429], true) || $status >= 500) {
            return ExecutionOutcome::temporary('n8n_unavailable');
        }

        if (! $response->successful()) {
            return ExecutionOutcome::permanent('n8n_rejected');
        }

        // An answer is a short JSON object; anything bigger is not from a workflow of ours.
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            return ExecutionOutcome::permanent('invalid_response');
        }

        return $this->validated($correlationId, $response->json());
    }

    /** The answer must be an object that echoes this execution and carries a usable reply and/or a transfer request. */
    private function validated(string $correlationId, mixed $json): ExecutionOutcome
    {
        if (! is_array($json) || ($json['execution_id'] ?? null) !== $correlationId) {
            return ExecutionOutcome::permanent('invalid_response');
        }

        $reply = $json['reply'] ?? null;
        $handoff = ($json['handoff'] ?? false) === true;

        if ($reply !== null && ! is_string($reply)) {
            return ExecutionOutcome::permanent('invalid_response');
        }

        $reply = is_string($reply) ? mb_substr(trim($reply), 0, (int) config('n8n.max_reply')) : null;

        if (($reply === null || $reply === '') && ! $handoff) {
            return ExecutionOutcome::permanent('invalid_response');
        }

        return ExecutionOutcome::answered($reply === '' ? null : $reply, $handoff);
    }
}
