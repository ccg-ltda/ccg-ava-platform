<?php

namespace App\Services;

use App\Models\AgentToolCall;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WorkspaceSetting;
use Illuminate\Support\Collection;

/**
 * The technical activity of ONE conversation (the «Actividad técnica» side panel): which business tools the assistant really called through Ava (the trace written by
 * AgentToolTrace, one row per call) and how each run of its workflow ended (ChatbotExecutions), newest first and one page at a time. Read only, and only operational facts:
 * fixed codes, states, ids, durations; never message text, prompts, request or response bodies, tokens or personal data (none of that is stored by the trace).
 *
 * Every query starts from the conversation AND its Workspace, so another conversation's rows can never appear. It shows only what was recorded: a call that never reached Ava
 * (an external API the workflow called on its own) is not here, and a call that died before its outcome stays `started` («sin resultado registrado»).
 */
class ConversationActivity
{
    public const PER_PAGE = 15;

    public function __construct(private readonly AgentToolTrace $trace) {}

    /**
     * @param  array{status?: ?string}  $filters
     * @return array{ready: bool, runs: list<array<string, mixed>>, calls: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(Conversation $conversation, WorkspaceSetting $settings, array $filters = [], int $page = 1, bool $withBodies = false): array
    {
        $when = fn ($date) => $date ? $settings->formatDate($date).' '.$settings->formatTime($date) : null;

        // The demonstration conversation answers with its fixed, flagged script (nothing real is mixed in, and no other conversation ever gets it).
        if ($conversation->demo_key === DemoConversations::ACTIVITY_KEY && in_array(app()->environment(), config('demo.environments'), true)) {
            return DemoTechnicalActivity::for($conversation, $settings, $filters);
        }

        if (! $this->trace->ready()) {
            return ['ready' => false, 'simulated' => false, 'runs' => [], 'calls' => [], 'meta' => $this->meta(0, 1, 1)];
        }

        $paginator = AgentToolCall::query()->where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        $executions = ChatbotExecution::query()->where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)->orderByDesc('id')->limit(10)->get();
        $labels = (array) config('chatbots.tools');
        $page = $paginator->getCollection();
        $runs = ChatbotExecution::query()->where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)
            ->whereIn('correlation_id', $page->pluck('execution_ref')->filter()->unique()->all())->get()->keyBy('correlation_id');
        $steps = $this->stepNumbers($conversation, $page);
        $triggers = $withBodies ? $this->triggerMessages($conversation, $page, $runs) : [];

        $calls = $page->map(function (AgentToolCall $call) use ($when, $runs, $labels, $steps, $triggers) {
            $run = $call->execution_ref ? $runs->get($call->execution_ref) : null;
            $trigger = $triggers[$call->id] ?? null;

            return [
                'id' => $call->id,
                'step' => $steps[$call->id] ?? null,
                'source' => $call->source,
                'tool' => $call->tool,
                'label' => $call->tool_label ?? ($labels[$call->tool]['label'] ?? $call->tool),
                'kind' => $call->kind,
                'auth' => $call->auth,
                'engine' => $this->engine($call),
                'engineLabel' => $this->engineLabel($call),
                'method' => $call->method,
                'endpoint' => $call->endpoint,
                'status' => $call->status,
                'statusLabel' => $call->source === 'reported' && $call->status === AgentToolCall::STARTED ? 'Pendiente' : config("chatbots.tool_statuses.{$call->status}"),
                'httpStatus' => $call->http_status,
                'reason' => $call->reason,
                'durationMs' => $call->duration_ms,
                'replayed' => $call->replayed,
                'resourceType' => $call->resource_type,
                'resourceId' => $call->resource_id,
                'resultState' => $call->result_state,
                'notification' => $call->notification,
                'executionRef' => $call->execution_ref ? substr($call->execution_ref, 0, 8) : null,
                'executionId' => $call->execution_id,
                'run' => $run ? ['status' => $run->status, 'attempts' => $run->attempts] : null,
                'trigger' => $trigger ? ['id' => $trigger->id, 'text' => $trigger->body !== null ? mb_strimwidth($trigger->body, 0, 160, '…') : null, 'type' => $trigger->type] : null,
                'when' => $when($call->started_at ?? $call->created_at),
                'summary' => $this->summary($call, $call->tool_label ?? ($labels[$call->tool]['label'] ?? $call->tool)),
            ];
        })->all();

        return [
            'ready' => true,
            'simulated' => false,
            'runs' => $executions->map(fn (ChatbotExecution $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'attempts' => $run->attempts,
                'outcome' => $run->outcome_code,
                'control' => $run->control_result,
                'delivery' => $run->delivery,
                'workflow' => $run->workflow_key,
                'ref' => substr((string) $run->correlation_id, 0, 8),
                'when' => $when($run->started_at ?? $run->created_at),
                'durationMs' => $run->started_at && $run->finished_at ? (int) $run->started_at->diffInMilliseconds($run->finished_at) : null,
            ])->all(),
            'calls' => $calls,
            'meta' => $this->meta($paginator->total(), $paginator->currentPage(), $paginator->lastPage(), (int) $paginator->firstItem(), (int) $paginator->lastItem()),
        ];
    }

    /**
     * The full request and answer of ONE step (already redacted when stored). A field nobody reported is null and the screen says "no reportado": nothing is filled in.
     *
     * @return array<string, mixed>
     */
    public function detail(Conversation $conversation, int $callId, WorkspaceSetting $settings, bool $withBodies = false): array
    {
        $call = AgentToolCall::query()->where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)->findOrFail($callId);
        $at = fn ($date) => $date ? $settings->formatDate($date).' '.$settings->formatTime($date) : null;
        $decode = fn (?string $json) => $json === null ? null : (json_decode($json, true) ?? $json);
        $run = $call->execution_ref ? ChatbotExecution::where('workspace_id', $call->workspace_id)->where('correlation_id', $call->execution_ref)->first() : null;
        $trigger = $withBodies ? ($this->triggerMessages($conversation, collect([$call]), $run ? collect([$run->correlation_id => $run]) : collect())[$call->id] ?? null) : null;
        $hide = fn ($value) => $withBodies ? $value : null;

        return [
            'id' => $call->id,
            'source' => $call->source,
            'tool' => $call->tool_label ?? $call->tool,
            'engine' => $this->engineLabel($call),
            'executionId' => $call->execution_id,
            'correlationId' => $call->execution_ref,
            'status' => $call->status,
            'statusLabel' => $call->source === 'reported' && $call->status === AgentToolCall::STARTED ? 'Pendiente' : config("chatbots.tool_statuses.{$call->status}"),
            'truncated' => $call->truncated,
            'bodiesRestricted' => ! $withBodies,
            'bodiesPurgedAt' => $at($call->bodies_purged_at),
            'request' => [
                'message' => $trigger ? ['id' => $trigger->id, 'text' => $trigger->body, 'type' => $trigger->type] : null,
                'method' => $call->method,
                'endpoint' => $call->endpoint,
                'headers' => $hide($decode($call->request_headers)),
                'query' => $hide($decode($call->request_query)),
                'body' => $hide($decode($call->request_body)),
                'at' => $at($call->started_at ?? $call->created_at),
            ],
            'response' => [
                'httpStatus' => $call->http_status,
                'durationMs' => $call->duration_ms,
                'headers' => $hide($decode($call->response_headers)),
                'body' => $hide($decode($call->response_body)),
                'errorClass' => $call->error_class,
                'errorMessage' => $hide($call->error_message),
                'reason' => $call->reason,
                'at' => $at($call->finished_at),
            ],
        ];
    }

    /** The engine that made the call: the one it declared, or n8n for a run Ava itself started; null when nobody said. */
    private function engine(AgentToolCall $call): ?string
    {
        return $call->engine ?? ($call->auth === 'execution' ? 'n8n' : null);
    }

    private function engineLabel(AgentToolCall $call): string
    {
        $engine = $this->engine($call);

        return $engine === null ? 'No informado' : (string) (config("chatbots.engines.{$engine}") ?? $engine);
    }

    /**
     * Position of each call of the page inside its run (the engine's execution id, else Ava's correlation id), counted over the whole conversation so the number
     * does not change from page to page. Calls with neither are numbered as one group.
     *
     * @param  Collection<int, AgentToolCall>  $page
     * @return array<int, int>
     */
    private function stepNumbers(Conversation $conversation, Collection $page): array
    {
        $groups = fn (AgentToolCall $call) => $call->execution_id ?? $call->execution_ref ?? '-';
        $keys = $page->map($groups)->unique()->values()->all();
        $real = array_values(array_diff($keys, ['-']));
        $all = AgentToolCall::query()->where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)
            ->where(function ($query) use ($keys, $real) {
                $query->whereIn('execution_id', $real)->orWhereIn('execution_ref', $real);

                if (in_array('-', $keys, true)) {
                    $query->orWhere(fn ($q) => $q->whereNull('execution_id')->whereNull('execution_ref'));
                }
            })->orderBy('id')->limit(2000)->get(['id', 'execution_id', 'execution_ref']);

        $counter = [];
        $numbers = [];

        foreach ($all as $row) {
            $key = $groups($row);
            $numbers[$row->id] = $counter[$key] = ($counter[$key] ?? 0) + 1;
        }

        return $numbers;
    }

    /**
     * The inbound message that started each call: the one the engine named, else the message of the run Ava started.
     *
     * @param  Collection<int, AgentToolCall>  $page
     * @param  Collection<string, ChatbotExecution>  $runs
     * @return array<int, Message>
     */
    private function triggerMessages(Conversation $conversation, Collection $page, Collection $runs): array
    {
        $wanted = [];

        foreach ($page as $call) {
            $wanted[$call->id] = $call->trigger_message_id ?? ($call->execution_ref ? $runs->get($call->execution_ref)?->message_id : null);
        }

        $messages = Message::query()->where('workspace_id', $conversation->workspace_id)->where('conversation_id', $conversation->id)
            ->whereIn('id', array_values(array_filter($wanted)))->get()->keyBy('id');

        return collect($wanted)->map(fn ($id) => $id ? $messages->get($id) : null)->filter()->all();
    }

    /** One plain sentence for a person who does not need to know the infrastructure. */
    private function summary(AgentToolCall $call, string $label): string
    {
        $what = $call->kind === 'write' ? 'pidió a Ava hacer' : 'consultó';

        return match ($call->status) {
            AgentToolCall::SUCCEEDED => "El asistente {$what}: {$label}. Ava lo completó".($call->replayed ? ' (repetición: no se duplicó nada).' : '.'),
            AgentToolCall::REJECTED => "El asistente {$what}: {$label}. Ava lo rechazó por las reglas del negocio (no cambió nada).",
            AgentToolCall::FAILED => "El asistente {$what}: {$label}. Ava no pudo completarlo.",
            AgentToolCall::UNCERTAIN => "El asistente {$what}: {$label}. No se sabe si se completó; una persona debe verificarlo.",
            default => "El asistente {$what}: {$label}. No quedó registrado el resultado.",
        };
    }

    /** @return array<string, int> */
    private function meta(int $total, int $current, int $last, int $from = 0, int $to = 0): array
    {
        return ['current_page' => $current, 'last_page' => $last, 'from' => $from, 'to' => $to, 'total' => $total];
    }
}
