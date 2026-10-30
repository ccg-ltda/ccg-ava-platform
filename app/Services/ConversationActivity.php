<?php

namespace App\Services;

use App\Models\AgentToolCall;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\WorkspaceSetting;

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
    public function page(Conversation $conversation, WorkspaceSetting $settings, array $filters = [], int $page = 1): array
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
        $byRef = $executions->keyBy('correlation_id');
        $labels = (array) config('chatbots.tools');

        $calls = $paginator->getCollection()->map(function (AgentToolCall $call) use ($when, $byRef, $labels) {
            $run = $call->execution_ref ? $byRef->get($call->execution_ref) : null;

            return [
                'id' => $call->id,
                'tool' => $call->tool,
                'label' => $labels[$call->tool]['label'] ?? $call->tool,
                'kind' => $call->kind,
                'auth' => $call->auth,
                'status' => $call->status,
                'statusLabel' => config("chatbots.tool_statuses.{$call->status}"),
                'httpStatus' => $call->http_status,
                'reason' => $call->reason,
                'durationMs' => $call->duration_ms,
                'replayed' => $call->replayed,
                'resourceType' => $call->resource_type,
                'resourceId' => $call->resource_id,
                'resultState' => $call->result_state,
                'notification' => $call->notification,
                'executionRef' => $call->execution_ref ? substr($call->execution_ref, 0, 8) : null,
                'run' => $run ? ['status' => $run->status, 'attempts' => $run->attempts] : null,
                'when' => $when($call->created_at),
                'summary' => $this->summary($call, $labels[$call->tool]['label'] ?? $call->tool),
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
