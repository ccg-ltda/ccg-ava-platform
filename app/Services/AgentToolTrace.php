<?php

namespace App\Services;

use App\Models\AgentToolCall;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The only writer of the trace of the assistant's business tools (see the migration `create_agent_tool_calls_table`). It records facts, never reasoning, and
 * never claims more than it knows:
 *  1. `start` writes the row BEFORE the tool runs, as `started` ("arrived, outcome unknown");
 *  2. the controller may `note` what it knows before it changes anything (the digest of its idempotency key), so the row can be reconciled later;
 *  3. `finish` turns the row into `succeeded`, `rejected`, `failed` or `uncertain` only AFTER the tool answered, i.e. after the operation was persisted
 *     (or refused). A process that dies in between leaves `started`, which is the truth: nobody recorded an outcome. `reconcile` settles those rows against
 *     the operation they were about when that can be proven, and otherwise marks them `uncertain`.
 * Tracing never blocks nor changes the tool: a failure to write the trace is logged with a fixed code and the tool goes on (a limit, documented in
 * RECOMENDACIONES.md). It holds no token, no message text and no personal data, and every query starts from a Workspace.
 */
class AgentToolTrace
{
    private ?bool $ready = null;

    /** The call being traced right now and what its tool reported, shared with the controllers (a form request is a copy of the request: attributes would be lost). */
    private ?AgentToolCall $current = null;

    /** @var array<string, mixed> */
    private array $facts = [];

    /** Whether the table exists (the code can reach an environment before its migration): until then nothing is traced and nothing else changes. */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('agent_tool_calls');
    }

    public function start(Request $request): ?AgentToolCall
    {
        $this->current = null;
        $this->facts = [];
        $tool = $this->tool($request);

        if ($tool === null || ! $this->ready()) {
            return null;
        }

        try {
            $execution = $request->attributes->get('execution');
            $chatbot = $request->attributes->get('chatbot');

            return $this->current = AgentToolCall::create([
                'workspace_id' => $request->attributes->get('workspace')->id,
                'chatbot_id' => $chatbot->id,
                'conversation_id' => $this->conversationId($request, $execution),
                'execution_ref' => $execution?->correlation_id,
                'tool' => $tool,
                'kind' => config('chatbots.tools')[$tool]['kind'],
                'auth' => $execution ? 'execution' : 'chatbot',
                'status' => AgentToolCall::STARTED,
            ]);
        } catch (Throwable $e) {
            $this->unavailable($e);

            return null;
        }
    }

    /**
     * Adds what the tool knows before it acts (for example the digest of its idempotency key), written at once so a crash cannot lose it.
     *
     * @param  array<string, mixed>  $facts
     */
    public function note(array $facts): void
    {
        if (! $this->current) {
            return;
        }

        try {
            $this->current->update(array_intersect_key($facts, array_flip(['idempotency_ref', 'conversation_id'])));
        } catch (Throwable $e) {
            $this->unavailable($e);
        }
    }

    /**
     * What the tool reports about the thing it touched, set by the controller only AFTER the operation was persisted (which appointment or order, whether this
     * was a retry, the state it really has, and whether a message to the customer is merely planned). Used only if the call succeeds.
     *
     * @param  array<string, mixed>  $facts
     */
    public function report(array $facts): void
    {
        $this->facts = $facts;
    }

    /**
     * Writes the outcome. `$response` is what the tool answered (null when it threw); `$exception` is what it threw. What a successful tool reports about
     * the thing it touched comes from `report` (set by the controller after the operation was persisted).
     */
    public function finish(?AgentToolCall $call, Request $request, ?Response $response, ?Throwable $exception, float $startedAt): void
    {
        if (! $call) {
            return;
        }

        try {
            [$http, $body] = $this->outcome($response, $exception);
            $reason = $this->code($body['reason'] ?? null) ?? ($http >= 300 ? $this->statusReason($http) : null);
            $status = match (true) {
                $http >= 200 && $http < 300 => AgentToolCall::SUCCEEDED,
                $http === 409 && $reason === 'uncertain' => AgentToolCall::UNCERTAIN,
                $http >= 500 => AgentToolCall::FAILED,
                default => AgentToolCall::REJECTED,
            };
            $succeeded = $status === AgentToolCall::SUCCEEDED;
            $facts = $succeeded && $call === $this->current ? $this->facts : [];
            $unknownAppointment = $status === AgentToolCall::UNCERTAIN ? ((int) ($body['appointment_id'] ?? 0) ?: null) : null;

            $call->update([
                'status' => $status,
                'http_status' => $http,
                'reason' => $succeeded ? null : $reason,
                'resource_type' => $facts['resource_type'] ?? ($unknownAppointment ? 'appointment' : null),
                'resource_id' => $facts['resource_id'] ?? $unknownAppointment,
                'replayed' => (bool) ($facts['replayed'] ?? false),
                'result_state' => $facts['result_state'] ?? null,
                'notification' => $facts['notification'] ?? 'none',
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->unavailable($e);
        } finally {
            $this->current = null;
            $this->facts = [];
        }
    }

    /**
     * Settles the calls that never recorded an outcome (the process ended between the operation and the write). An order request is settled by what the
     * database proves (an order with that request's digest exists, or does not); everything else cannot be proven and becomes `uncertain`.
     *
     * @return array{succeeded: int, failed: int, uncertain: int}
     */
    public function reconcile(int $olderThanMinutes = 5): array
    {
        $counts = ['succeeded' => 0, 'failed' => 0, 'uncertain' => 0];

        if (! $this->ready()) {
            return $counts;
        }

        AgentToolCall::where('status', AgentToolCall::STARTED)->where('created_at', '<', now()->subMinutes($olderThanMinutes))->orderBy('id')->each(function (AgentToolCall $call) use (&$counts) {
            $byDigest = $call->tool === 'orders.store' && $call->idempotency_ref;
            $order = $byDigest ? Order::where('workspace_id', $call->workspace_id)->where('idempotency_key', $call->idempotency_ref)->first() : null;

            if ($order) {
                $call->update(['status' => AgentToolCall::SUCCEEDED, 'reason' => 'reconciled', 'resource_type' => 'order', 'resource_id' => $order->id, 'result_state' => $order->status, 'finished_at' => now()]);
                $counts['succeeded']++;
            } elseif ($byDigest) {
                $call->update(['status' => AgentToolCall::FAILED, 'reason' => 'no_result', 'finished_at' => now()]);
                $counts['failed']++;
            } else {
                $call->update(['status' => AgentToolCall::UNCERTAIN, 'reason' => 'no_outcome', 'finished_at' => now()]);
                $counts['uncertain']++;
            }
        });

        return $counts;
    }

    /** The tool of this request (route name without "agent."), or null when it is not one of the traced tools. */
    private function tool(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();
        $tool = str_starts_with($name, 'agent.') ? substr($name, 6) : null;

        // The tool names contain a dot, so they are read as keys of the array, not as a config path.
        return $tool !== null && isset(config('chatbots.tools')[$tool]) ? $tool : null;
    }

    private function conversationId(Request $request, mixed $execution): ?int
    {
        if ($execution) {
            return $execution->conversation_id;
        }

        $channel = $request->input('channel');
        $contact = $request->input('contact_id');

        return is_string($channel) && is_string($contact) ? $request->attributes->get('chatbot')->conversations()->where('channel', $channel)->where('contact_id', $contact)->value('id') : null;
    }

    /** @return array{0: int, 1: array<string, mixed>} the HTTP status and the decoded JSON body (only fixed fields are ever read from it) */
    private function outcome(?Response $response, ?Throwable $exception): array
    {
        if ($exception) {
            return [$exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 500), []];
        }

        return [$response->getStatusCode(), $response instanceof JsonResponse ? (array) $response->getData(true) : []];
    }

    /** The fixed code for a refusal that carries none of its own (a validation error, an aborted request). */
    private function statusReason(int $http): string
    {
        return match (true) {
            $http === 422 => 'invalid',
            $http === 403 => 'forbidden',
            $http === 404 => 'not_found',
            $http === 409 => 'conflict',
            $http >= 500 => 'exception',
            default => 'rejected',
        };
    }

    /** Only a short fixed code is ever stored: anything else (free text, provider text) becomes null. */
    private function code(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z_]{1,32}$/', $value) ? $value : null;
    }

    private function unavailable(Throwable $e): void
    {
        Log::warning('agent_trace_unavailable', ['exception' => $e::class]);
    }
}
