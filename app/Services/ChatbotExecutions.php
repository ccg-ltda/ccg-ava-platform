<?php

namespace App\Services;

use App\Chatbots\ChatbotExecutor;
use App\Chatbots\ExecutionOutcome;
use App\Chatbots\ResolvedWorkflow;
use App\Chatbots\RunResult;
use App\Jobs\RunChatbotExecution;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs the workflow assigned to a chatbot for an inbound message, and decides what happens with its answer. Ava is the
 * authority: it only starts an execution for a conversation of the chatbot's own Workspace while the AI has it, it hands
 * the workflow just the context it needs, and it stores and sends the answer only if control did not change meanwhile
 * (`handling_version`). The workflow itself is whatever `ChatbotExecutor` is bound to (today n8n).
 *
 * Every execution is a row of `chatbot_executions` (one per inbound message: `message_id` is unique) and every change of
 * its state is a conditional update on that row, so two workers, a redelivered job or a repeated event can never run,
 * answer or send the same thing twice. The inbound message is never touched: a failure leaves it, the inbox and the
 * conversation exactly as they were.
 *
 *   status:   pending -> running -> succeeded | discarded | failed        (running -> pending = temporary failure, retried)
 *   delivery: (none) | pending -> sending -> accepted | failed | uncertain
 *
 * Handing the reply to WhatsApp is the one step that cannot be repeated safely, so it is recorded BEFORE it happens
 * (`sending`) and attempted at most once per reply. Meta's Cloud API has no idempotency key for sending, so when the
 * outcome is unknown (a timeout after the request may have been processed, a server error, a crash between `sending` and
 * the recorded result) the reply is `uncertain` / `unconfirmed` and is NEVER resent automatically: a person decides. Ava
 * tags every send with `ava:{message id}` (`biz_opaque_callback_data`), which Meta echoes in the delivery states, and
 * `WhatsAppInbound` uses that tag to settle an uncertain reply when the state arrives. If it never arrives the reply stays
 * uncertain: that risk belongs to the external API and cannot be removed from here.
 *
 * A reply is stored under the conversation's row lock (the one every change of control takes) and checked again, under
 * the same lock, when claiming `sending`; after a person takes over, no automatic reply goes out. What remains is the
 * window of one request to the channel, as for the automation's own `authorize` check.
 */
class ChatbotExecutions
{
    public function __construct(
        private readonly ChatbotExecutor $executor,
        private readonly ChatbotWorkflows $workflows,
        private readonly ConversationControl $control,
        private readonly ChannelDelivery $delivery,
    ) {}

    /** Whether the chatbot is switched on and in an active Workspace and Organization. */
    private function active(Chatbot $chatbot): bool
    {
        $chatbot->loadMissing('workspace.organization');

        return $chatbot->is_active && ! $chatbot->is_demo && $chatbot->workspace->is_active && $chatbot->workspace->organization->is_active;
    }

    /**
     * Records and queues the execution for a message the contact JUST wrote. Nothing happens when the chatbot has no
     * authorized workflow, a person has the conversation, the message is not a real text, or it already has an execution.
     */
    public function schedule(Conversation $conversation, Message $inbound): ?ChatbotExecution
    {
        $chatbot = $conversation->chatbot;

        if (! $this->active($chatbot) || ! ($workflow = $this->workflows->for($chatbot)) || ! $this->belongsTogether($chatbot, $conversation, $inbound)
            || $inbound->direction !== 'in' || $inbound->simulated || $conversation->isDemo() || blank($inbound->body)
            || ! $conversation->aiMayReply()) {
            return null;
        }

        $now = now();
        // Ignores only a duplicate `message_id`: the database decides who is first, however many events arrive at once.
        $created = ChatbotExecution::insertOrIgnore([[
            'correlation_id' => (string) Str::uuid(),
            'workspace_id' => $chatbot->workspace_id,
            'chatbot_id' => $chatbot->id,
            'conversation_id' => $conversation->id,
            'message_id' => $inbound->id,
            'workflow_key' => $workflow->key,
            'n8n_workflow_id' => $workflow->n8nId,
            'workflow_version' => $workflow->version,
            'handling_version' => $conversation->handling_version,
            'status' => ChatbotExecution::PENDING,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);

        if ($created !== 1) {
            return null;
        }

        $execution = ChatbotExecution::where('message_id', $inbound->id)->firstOrFail();
        RunChatbotExecution::dispatch($execution->id);

        return $execution;
    }

    /** Runs one execution (or resumes the delivery of a reply that was stored but never handed over). */
    public function run(int $id): RunResult
    {
        if (! $this->claim($id)) {
            $resume = ChatbotExecution::whereKey($id)->where('status', ChatbotExecution::SUCCEEDED)->where('delivery', ChatbotExecution::DELIVERY_PENDING)->first();

            if ($resume) {
                $this->deliverReply($resume->id);

                return $this->finish($resume, RunResult::done());
            }

            return RunResult::discarded('not_runnable');
        }

        $execution = ChatbotExecution::with(['chatbot.workspace.organization', 'conversation', 'message'])->findOrFail($id);
        $workflow = $this->blocker($execution);

        if (is_string($workflow)) {
            return $this->settle($execution, ChatbotExecution::DISCARDED, $workflow);
        }

        $outcome = $this->executor->run($workflow, $execution->correlation_id, $this->payload($execution, $workflow));

        if ($outcome->isTemporary()) {
            return $this->retryOrFail($execution, $outcome);
        }

        if (! $outcome->answeredOk()) {
            return $this->settle($execution, ChatbotExecution::FAILED, $outcome->code);
        }

        return $this->complete($execution, $outcome);
    }

    /** Manual recovery of a FAILED execution that stored no reply: it runs again from the start (its checks still apply). */
    public function retryFailed(int $id): bool
    {
        $reset = ChatbotExecution::whereKey($id)->where('status', ChatbotExecution::FAILED)->whereNull('reply_message_id')
            ->update(['status' => ChatbotExecution::PENDING, 'attempts' => 0, 'outcome_code' => null, 'finished_at' => null]) === 1;

        if ($reset) {
            RunChatbotExecution::dispatch($id);
        }

        return $reset;
    }

    /**
     * Picks up what a crash or a lost queue left half done, without ever repeating a send: executions that stayed pending
     * or running are queued again (claiming is atomic), a stored reply that was never handed over is resumed, and a reply
     * left `sending` (we cannot know whether it went out) is settled as uncertain.
     *
     * @return array{requeued: int, resumed: int, uncertain: int}
     */
    public function recover(): array
    {
        $stale = now()->subSeconds((int) config('n8n.timeout') + 60);
        $waiting = now()->subMinutes(5);
        $counts = ['requeued' => 0, 'resumed' => 0, 'uncertain' => 0];

        // A run that keeps dying (a bug, not a lost job) must not be queued forever.
        ChatbotExecution::where('status', ChatbotExecution::RUNNING)->where('updated_at', '<', $stale)->where('attempts', '>=', (int) config('n8n.tries') + 3)
            ->update(['status' => ChatbotExecution::FAILED, 'outcome_code' => 'crashed', 'finished_at' => now()]);

        ChatbotExecution::where(fn ($q) => $q->where('status', ChatbotExecution::PENDING)->where('updated_at', '<', $waiting)
            ->orWhere(fn ($q) => $q->where('status', ChatbotExecution::RUNNING)->where('updated_at', '<', $stale)))
            ->pluck('id')->each(function (int $id) use (&$counts) {
                RunChatbotExecution::dispatch($id);
                $counts['requeued']++;
            });

        ChatbotExecution::where('status', ChatbotExecution::SUCCEEDED)->where('delivery', ChatbotExecution::DELIVERY_PENDING)->where('updated_at', '<', $stale)
            ->pluck('id')->each(function (int $id) use (&$counts) {
                RunChatbotExecution::dispatch($id);
                $counts['resumed']++;
            });

        ChatbotExecution::where('delivery', ChatbotExecution::DELIVERY_SENDING)->where('updated_at', '<', $stale)
            ->pluck('id')->each(function (int $id) use (&$counts) {
                $this->settleUnknownSend($id) && $counts['uncertain']++;
            });

        return $counts;
    }

    /** `pending` (or a `running` one whose worker died) -> `running`, atomically; false when it is not ours to run. */
    private function claim(int $id): bool
    {
        $stale = now()->subSeconds((int) config('n8n.timeout') + 60);

        return ChatbotExecution::whereKey($id)
            ->where(fn ($query) => $query->where('status', ChatbotExecution::PENDING)->orWhere(fn ($q) => $q->where('status', ChatbotExecution::RUNNING)->where('updated_at', '<', $stale)))
            ->update(['status' => ChatbotExecution::RUNNING, 'started_at' => now(), 'attempts' => DB::raw('attempts + 1')]) === 1;
    }

    /** The workflow to run, or the fixed reason why this execution must not run any more: everything is read again from the database. */
    private function blocker(ChatbotExecution $execution): ResolvedWorkflow|string
    {
        $chatbot = $execution->chatbot;
        $conversation = Conversation::find($execution->conversation_id);
        $active = $this->active($chatbot);
        $workflow = $active ? $this->workflows->for($chatbot) : null;

        return match (true) {
            ! $active => 'chatbot_off',
            $workflow === null => 'no_workflow',
            $workflow->key !== $execution->workflow_key => 'workflow_changed',
            $execution->message->direction !== 'in' || blank($execution->message->body) => 'not_text',
            $execution->message->created_at->lt(now()->subMinutes((int) config('n8n.max_age_minutes'))) => 'stale',
            ! $conversation || ! $conversation->aiMayReply() || $conversation->handling_version !== $execution->handling_version => 'superseded',
            default => $workflow,
        };
    }

    /** The chatbot, conversation and message are of one Workspace and refer to each other (the foreign keys enforce it too). */
    private function belongsTogether(Chatbot $chatbot, Conversation $conversation, Message $message): bool
    {
        return $conversation->chatbot_id === $chatbot->id
            && $conversation->workspace_id === $chatbot->workspace_id
            && $message->conversation_id === $conversation->id
            && $message->workspace_id === $chatbot->workspace_id;
    }

    /**
     * What the workflow may know: the chatbot's instructions, the message and a short history of THIS conversation. No
     * contact number, Workspace data or credential. `memory_key` is an opaque key that is different for every Workspace,
     * chatbot and conversation: the only thing a workflow should use to keep conversational memory.
     *
     * @return array<string, mixed>
     */
    private function payload(ChatbotExecution $execution, ResolvedWorkflow $workflow): array
    {
        $conversation = $execution->conversation;
        $chatbot = $execution->chatbot;
        $history = $conversation->messages()->where('id', '<', $execution->message_id)->whereNotNull('body')
            ->orderByDesc('id')->limit((int) config('n8n.history_messages'))->get(['direction', 'body'])->reverse()->values()
            ->map(fn (Message $m) => ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'text' => $m->body])->all();

        return [
            'execution_id' => $execution->correlation_id,
            'event' => 'message',
            'workflow' => ['key' => $workflow->key, 'id' => $workflow->n8nId],
            'chatbot' => ['id' => $chatbot->id, 'name' => $chatbot->name, 'instructions' => mb_substr((string) $chatbot->instructions, 0, (int) config('n8n.max_instructions'))],
            'conversation' => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'memory_key' => substr(hash_hmac('sha256', "w{$chatbot->workspace_id}|b{$chatbot->id}|c{$conversation->id}", (string) config('app.key')), 0, 40),
            ],
            'message' => ['id' => $execution->message_id, 'text' => $execution->message->body],
            'history' => $history,
        ];
    }

    private function retryOrFail(ChatbotExecution $execution, ExecutionOutcome $outcome): RunResult
    {
        if ($execution->attempts >= (int) config('n8n.tries')) {
            return $this->settle($execution, ChatbotExecution::FAILED, 'retries_exhausted');
        }

        ChatbotExecution::whereKey($execution->id)->where('status', ChatbotExecution::RUNNING)->update(['status' => ChatbotExecution::PENDING, 'outcome_code' => $outcome->code]);
        $backoff = config('n8n.backoff');

        return $this->finish($execution, RunResult::retry($outcome->code, (int) ($backoff[$execution->attempts - 1] ?? end($backoff))));
    }

    /** Stores the reply (if control did not change), hands it to the channel and applies the transfer the workflow asked for. */
    private function complete(ChatbotExecution $execution, ExecutionOutcome $outcome): RunResult
    {
        $verdict = DB::transaction(function () use ($execution, $outcome) {
            $conversation = Conversation::whereKey($execution->conversation_id)->lockForUpdate()->firstOrFail();
            $row = ChatbotExecution::whereKey($execution->id)->lockForUpdate()->first();

            if ($row->status !== ChatbotExecution::RUNNING) {
                return RunResult::discarded('not_running');
            }

            // Control changed while the workflow was working (a person took over, the case was resolved): the answer is late.
            if (! $conversation->aiMayReply() || $conversation->handling_version !== $execution->handling_version) {
                $row->update(['status' => ChatbotExecution::DISCARDED, 'outcome_code' => 'late_response', 'control_result' => 'late_dropped', 'finished_at' => now()]);

                return RunResult::discarded('late_response');
            }

            $reply = null;

            if ($outcome->reply !== null) {
                $reply = $conversation->messages()->create([
                    'workspace_id' => $conversation->workspace_id,
                    'direction' => 'out',
                    'sender' => 'ai',
                    'type' => 'text',
                    'body' => $outcome->reply,
                    'status' => Message::PENDING,
                    'sent_at' => now(),
                ]);
                $conversation->forceFill(['last_message_at' => $reply->sent_at])->save();
            }

            $row->update([
                'status' => ChatbotExecution::SUCCEEDED,
                'outcome_code' => 'ok',
                'control_result' => 'replied',
                'reply_message_id' => $reply?->id,
                'delivery' => $reply ? ChatbotExecution::DELIVERY_PENDING : null,
                'finished_at' => now(),
            ]);

            return RunResult::done();
        });

        if ($verdict->kind !== 'done') {
            return $this->finish($execution, $verdict);
        }

        $this->deliverReply($execution->id);

        if ($outcome->handoff) {
            $this->applyHandoff($execution);
        }

        return $this->finish($execution, $verdict);
    }

    /**
     * Hands the stored reply to the channel AT MOST ONCE. Under the conversation lock it first checks that the AI still
     * has the conversation as it was and records `sending`; only then does the request go out, outside the lock, and its
     * result (accepted, refused or uncertain) is recorded after.
     */
    private function deliverReply(int $executionId): void
    {
        $messageId = DB::transaction(function () use ($executionId) {
            $conversationId = ChatbotExecution::whereKey($executionId)->value('conversation_id');
            $conversation = Conversation::whereKey($conversationId)->lockForUpdate()->first();
            $row = ChatbotExecution::whereKey($executionId)->lockForUpdate()->first();

            if ($row->delivery !== ChatbotExecution::DELIVERY_PENDING) {
                return null;
            }

            if (! $conversation->aiMayReply() || $conversation->handling_version !== $row->handling_version) {
                $replyId = $row->reply_message_id;
                $row->update(['status' => ChatbotExecution::DISCARDED, 'outcome_code' => 'superseded_before_send', 'control_result' => 'superseded', 'reply_message_id' => null, 'delivery' => null]);
                Message::whereKey($replyId)->delete(); // it never left Ava

                return null;
            }

            $row->update(['delivery' => ChatbotExecution::DELIVERY_SENDING]);

            return $row->reply_message_id;
        });

        if ($messageId === null) {
            return;
        }

        $message = Message::findOrFail($messageId);
        $this->delivery->deliver($message);

        ChatbotExecution::whereKey($executionId)->where('delivery', ChatbotExecution::DELIVERY_SENDING)->update(['delivery' => $this->deliveryState($message->refresh())]);
    }

    /** What the channel did with the reply, as recorded on the message itself. Anything we cannot read as a clear answer is uncertain. */
    private function deliveryState(Message $message): string
    {
        return match ($message->status) {
            'sent', 'delivered', 'read' => ChatbotExecution::DELIVERY_ACCEPTED,
            'failed' => ChatbotExecution::DELIVERY_FAILED,
            default => ChatbotExecution::DELIVERY_UNCERTAIN,
        };
    }

    /** A reply left `sending` by a crash: nobody knows whether it went out, so it is kept as unconfirmed and never resent. */
    private function settleUnknownSend(int $executionId): bool
    {
        return DB::transaction(function () use ($executionId) {
            $row = ChatbotExecution::whereKey($executionId)->lockForUpdate()->first();

            if ($row?->delivery !== ChatbotExecution::DELIVERY_SENDING) {
                return false;
            }

            $message = Message::find($row->reply_message_id);

            if ($message?->status === Message::PENDING) {
                $this->delivery->unconfirm($message, 'No se pudo confirmar el envío: pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo.');
            }

            $row->update(['delivery' => $message ? $this->deliveryState($message->refresh()) : ChatbotExecution::DELIVERY_UNCERTAIN]);

            return true;
        });
    }

    /** The workflow asked for a person: Ava asks the control service, which only moves a conversation the AI still has. */
    private function applyHandoff(ChatbotExecution $execution): void
    {
        // Only for an execution whose answer stood (a dropped one asks for nothing) and a conversation the AI still has as it was.
        $conversation = ChatbotExecution::whereKey($execution->id)->where('status', ChatbotExecution::SUCCEEDED)->exists() ? Conversation::find($execution->conversation_id) : null;
        $applied = $conversation?->aiMayReply() && $conversation->handling_version === $execution->handling_version;

        if ($applied) {
            $this->control->requestHuman($conversation, 'Solicitado por el asistente.');
        }

        if ($conversation) {
            ChatbotExecution::whereKey($execution->id)->update(['control_result' => $applied ? 'handoff_applied' : 'handoff_skipped']);
        }
    }

    private function settle(ChatbotExecution $execution, string $status, string $code): RunResult
    {
        ChatbotExecution::whereKey($execution->id)->where('status', ChatbotExecution::RUNNING)->update(['status' => $status, 'outcome_code' => $code, 'finished_at' => now()]);

        return $this->finish($execution, $status === ChatbotExecution::FAILED ? RunResult::failed($code) : RunResult::discarded($code));
    }

    /** Operational log: identifiers and fixed codes only, never message text, contact data or credentials. */
    private function finish(ChatbotExecution $execution, RunResult $result): RunResult
    {
        Log::info('chatbot_execution', ['event' => $result->kind, 'code' => $result->code, 'execution' => $execution->correlation_id, 'conversation' => $execution->conversation_id, 'attempt' => $execution->attempts]);

        return $result;
    }
}
