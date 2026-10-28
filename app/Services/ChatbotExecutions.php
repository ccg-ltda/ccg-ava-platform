<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Chatbots\ChatbotExecutor;
use App\Chatbots\ExecutionOutcome;
use App\Chatbots\FailureKind;
use App\Chatbots\ResolvedWorkflow;
use App\Chatbots\RunResult;
use App\Jobs\RunChatbotExecution;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
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
        private readonly AuditLogger $audit,
        private readonly ModuleAccess $modules,
        private readonly ExecutionAccess $access,
        private readonly OperationalAlert $alerts,
        private readonly WorkspaceLimits $limits,
    ) {}

    /** Whether the chatbot is switched on, in an active Workspace and Organization whose plan still includes the assistant and the inbox. */
    private function active(Chatbot $chatbot): bool
    {
        $chatbot->loadMissing('workspace.organization');

        return $chatbot->is_active && ! $chatbot->is_demo && $chatbot->workspace->is_active && $chatbot->workspace->organization->is_active
            && $this->modules->allowsAll($chatbot->workspace, ModuleAccess::CHANNEL_MODULES);
    }

    /**
     * Whether this chatbot answers through the platform's workflow: it has an authorized workflow and the platform n8n is configured.
     * The channels (WhatsApp, web) use it to choose between this path (Ava starts and controls every execution) and the older one
     * (the Workspace's own n8n webhook, web only).
     */
    public function available(Chatbot $chatbot): bool
    {
        return filled(config('n8n.execution_url')) && filled(config('n8n.secret')) && $this->active($chatbot) && $this->workflows->for($chatbot) !== null;
    }

    /**
     * Records and queues the execution for a message the contact JUST wrote. Nothing happens when the chatbot has no
     * authorized workflow, a person has the conversation, the message is not a real text, or it already has an execution.
     */
    public function schedule(Conversation $conversation, Message $inbound, bool $resume = false): ?ChatbotExecution
    {
        $chatbot = $conversation->chatbot;

        // A resume (`event: resume`) hangs from the last message a person or the AI wrote, never from a contact's: it only exists when an agent hands the conversation back on purpose.
        $eligible = $resume ? in_array($inbound->sender, ['agent', 'ai'], true) : $inbound->direction === 'in';

        if (! $this->active($chatbot) || ! ($workflow = $this->workflows->for($chatbot)) || ! $this->belongsTogether($chatbot, $conversation, $inbound)
            || ! $eligible || $inbound->simulated || $conversation->isDemo() || blank($inbound->body)
            || ! $conversation->aiMayReply()) {
            return null;
        }

        // A message that already has its execution is a duplicate event: it neither counts again nor is judged against the limit again.
        if (ChatbotExecution::where('message_id', $inbound->id)->exists()) {
            return null;
        }

        $now = now();
        $reached = null;
        // The limit is checked and the execution recorded under the Workspace's row lock, so two simultaneous messages can not both take the last slot.
        // Ignores only a duplicate `message_id`: the database decides who is first, however many events arrive at once.
        $created = DB::transaction(function () use ($chatbot, $conversation, $inbound, $workflow, $now, &$reached) {
            Workspace::whereKey($chatbot->workspace_id)->lockForUpdate()->first();

            if (($reached = $this->limits->aiLimitReached($chatbot->workspace)) !== null) {
                return 0;
            }

            return ChatbotExecution::insertOrIgnore([[
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
        });

        if ($reached !== null) {
            $this->blockedByLimit($conversation, $chatbot->workspace, $reached);

            return null;
        }

        if ($created !== 1) {
            return null;
        }

        $execution = ChatbotExecution::where('message_id', $inbound->id)->firstOrFail();
        RunChatbotExecution::dispatch($execution->id);

        return $execution;
    }

    /**
     * A person gave the conversation back to the AI. If the last thing said is a message of the contact that nobody answered (they replied to the agent's question, say),
     * the AI answers THAT message now, with the persisted state and the wider history of a resume. If the agent spoke last there is nothing to answer: the AI continues
     * with the contact's next message (the contract has no event without an inbound message). Duplicate-safe: a message has at most one execution.
     */
    public function resumeAfterReturn(Conversation $conversation, bool $continueNow = false): ?ChatbotExecution
    {
        $conversation = $conversation->fresh(['chatbot.workspace.organization']);
        $last = $conversation->messages()->whereIn('sender', ['contact', 'agent', 'ai'])->orderByDesc('id')->first();

        if (! $last) {
            return null;
        }

        // The contact is waiting: answer that message. Otherwise (the agent spoke last) the AI speaks only if the agent asked it to continue now: a workflow that is not expecting
        // a `resume` event must not answer a customer who wrote nothing.
        return $last->sender === 'contact' ? $this->schedule($conversation, $last) : ($continueNow ? $this->schedule($conversation, $last, true) : null);
    }

    /**
     * The Workspace used all its AI executions of the month: no new execution starts. The message is already stored; the conversation moves to a person
     * (only if the AI still had it, like any other hand-over), so nobody is left unanswered in silence. One operational alert per Workspace per hour and one
     * audit event per Workspace per day say why, with identifiers and the limit only. History and the panel keep working; no module is withdrawn.
     */
    private function blockedByLimit(Conversation $conversation, Workspace $workspace, int $limit): void
    {
        try {
            $this->control->handOverAfterFailure($conversation, $conversation->handling_version, 'ai_limit');

            if (Cache::add("ava:ai-limit:alert:{$workspace->id}", 1, 3600)) {
                $this->alerts->raise('ai_limit_reached', ['workspace' => $workspace->id, 'limit' => $limit, 'renews' => $this->limits->period()['renews']->toDateString()]);
            }

            if (Cache::add('ava:ai-limit:audit:'.$workspace->id.':'.now()->toDateString(), 1, 86400)) {
                $this->audit->system('created', 'ai_execution', null, $workspace->name, [AuditLogger::change('Ejecución de IA', null, "Bloqueada: límite mensual de {$limit} alcanzado")], $workspace, 'Asistente (automático)', AuditLogger::FAILED);
            }
        } catch (\Throwable $e) {
            // Never lets the report of the block break the arrival of the message.
            Log::error('ai_limit_report_failed', ['exception' => $e::class, 'workspace_id' => $workspace->id]);
        }
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

        // n8n accepted this run with the instructions of that version: the only confirmation Zonno has that the workflow received them.
        if (ChatbotInstructions::ready()) {
            app(ChatbotInstructions::class)->markSynced($execution->chatbot_id, (int) $execution->chatbot->instructions_version);
        }

        return $this->complete($execution, $outcome);
    }

    /**
     * The job crashed on an unexpected error. While the execution still has runs left, recovery will try it again; on its last run it is settled as
     * failed for good, so the contact is handed to a person instead of waiting for a recovery that would only repeat the crash.
     */
    public function abandonIfExhausted(int $id): void
    {
        $execution = ChatbotExecution::with('chatbot.workspace')->find($id);

        if ($execution && $execution->status === ChatbotExecution::RUNNING && $execution->attempts >= (int) config('n8n.tries')) {
            $this->settle($execution, ChatbotExecution::FAILED, 'internal_error');
        }
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
     * @return array{requeued: int, resumed: int, uncertain: int, handed_over: int}
     */
    public function recover(): array
    {
        $stale = now()->subSeconds((int) config('n8n.timeout') + 60);
        $waiting = now()->subMinutes(5);
        $counts = ['requeued' => 0, 'resumed' => 0, 'uncertain' => 0, 'handed_over' => 0];

        // A run that keeps dying (a bug, not a lost job) must not be queued forever.
        ChatbotExecution::with('chatbot.workspace')->where('status', ChatbotExecution::RUNNING)->where('updated_at', '<', $stale)->where('attempts', '>=', (int) config('n8n.tries') + 3)->get()
            ->each(function (ChatbotExecution $execution) {
                if (ChatbotExecution::whereKey($execution->id)->where('status', ChatbotExecution::RUNNING)->update(['status' => ChatbotExecution::FAILED, 'outcome_code' => 'crashed', 'finished_at' => now()]) === 1) {
                    $this->auditFailure($execution, 'crashed');
                    $this->handOver($execution, 'crashed');
                }
            });

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

        // Backstop of D-FALLO: an execution that failed for good a moment ago but whose handover did not happen (the process died or the database
        // failed right after the failure was recorded). Handing over is idempotent, so a conversation that was already moved is simply left alone.
        ChatbotExecution::with('chatbot.workspace')->whereIn('status', [ChatbotExecution::FAILED, ChatbotExecution::DISCARDED])->whereNull('control_result')
            ->where(fn ($q) => $q->where('status', ChatbotExecution::FAILED)->orWhere('outcome_code', 'stale'))
            ->where('finished_at', '>=', now()->subHour())->get()
            ->each(function (ChatbotExecution $execution) use (&$counts) {
                $this->handOver($execution, $execution->outcome_code ?? 'unknown') && $counts['handed_over']++;
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
            ($execution->message->direction !== 'in' && ! in_array($execution->message->sender, ['agent', 'ai'], true)) || blank($execution->message->body) => 'not_text',
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
     * contact number or Workspace data; the only credential is the run-scoped token of `ava` (ExecutionAccess). `memory_key` is an opaque key that is different for every Workspace,
     * chatbot and conversation: the only thing a workflow should use to keep conversational memory.
     *
     * @return array<string, mixed>
     */
    private function payload(ChatbotExecution $execution, ResolvedWorkflow $workflow): array
    {
        $conversation = $execution->conversation;
        $chatbot = $execution->chatbot;
        $state = app(ConversationContext::class)->forWorkflow($conversation);
        // Coming back from a person the window is wider: what the agent asked and the customer answered must not fall out of it. `sender` tells contact, ai and agent apart
        // (`role` stays user/assistant, so a workflow written before this field keeps working).
        $limit = $state['resume'] ? (int) config('n8n.resume_history_messages') : (int) config('n8n.history_messages');
        $resuming = $execution->message->direction !== 'in';
        $history = $conversation->messages()->where('id', $resuming ? '<=' : '<', $execution->message_id)->whereNotNull('body')->where('sender', '!=', 'system')
            ->orderByDesc('id')->limit($limit)->get(['direction', 'sender', 'body'])->reverse()->values()
            ->map(fn (Message $m) => ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'sender' => $m->sender ?? ($m->direction === 'in' ? 'contact' : 'ai'), 'text' => $m->body])->all();

        return [
            'execution_id' => $execution->correlation_id,
            'event' => $resuming ? 'resume' : 'message',
            'workflow' => ['key' => $workflow->key, 'id' => $workflow->n8nId],
            'chatbot' => [
                'id' => $chatbot->id, 'name' => $chatbot->name,
                'instructions' => mb_substr(app(ChatbotInstructions::class)->effective($chatbot), 0, (int) config('n8n.max_instructions')),
                'instructions_version' => ChatbotInstructions::ready() ? (int) $chatbot->instructions_version : null,
                'instructions_mode' => app(ChatbotInstructions::class)->mode($chatbot),
            ],
            'conversation' => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'memory_key' => substr(hash_hmac('sha256', "w{$chatbot->workspace_id}|b{$chatbot->id}|c{$conversation->id}", (string) config('app.key')), 0, 40),
            ],
            'message' => ['id' => $execution->message_id, 'text' => $execution->message->body, 'sender' => $execution->message->sender ?? 'contact'],
            'history' => $history,
            // The persisted conversational state (facts with source and status, steps, what was already done, the human handoff). The workflow answers with `context` to change it.
            'context' => $state,
            // How the workflow reaches Ava's tools for THIS conversation: a run-scoped token (booking and order tools only, valid while this
            // execution runs) and which tools the Workspace plan includes. The workflow never needs a long-lived chatbot token.
            'ava' => [
                'api_url' => url('/api/agent'),
                'token' => $this->access->issue($execution),
                'token_expires_at' => now()->addSeconds((int) config('n8n.run_token_ttl'))->toIso8601String(),
                'guidelines' => config('chatbots.guidelines'),
                'tools' => ['booking' => $this->modules->allowsAll($chatbot->workspace, ModuleAccess::BOOKING_MODULES), 'orders' => $this->modules->allowsAll($chatbot->workspace, ModuleAccess::ORDER_MODULES)],
            ],
        ];
    }

    private function retryOrFail(ChatbotExecution $execution, ExecutionOutcome $outcome): RunResult
    {
        if ($execution->attempts >= (int) config('n8n.tries')) {
            return $this->settle($execution, ChatbotExecution::FAILED, 'retries_exhausted', $outcome->code);
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

            // Same transaction and same lock as the reply: the state the workflow proposes is stored only if its answer is (a late answer changes nothing). A failure to store it
            // is logged and does not lose the reply, but the next payload will simply not have it (the history is untouched either way).
            if (is_array($outcome->context)) {
                try {
                    app(ConversationContext::class)->apply($conversation, $outcome->context, 'ai');
                } catch (Throwable $e) {
                    Log::error('conversation_context_store_failed', ['conversation' => $conversation->id, 'exception' => $e::class]);
                }
            }

            // The AI answered after a person gave the conversation back: from here on it is a normal conversation again.
            app(ConversationContext::class)->clearResume($conversation->refresh());

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

        $state = $this->deliveryState($message->refresh());

        if (ChatbotExecution::whereKey($executionId)->where('delivery', ChatbotExecution::DELIVERY_SENDING)->update(['delivery' => $state]) === 1) {
            $this->auditDelivery(ChatbotExecution::with('chatbot.workspace')->findOrFail($executionId), $state);
        }
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

            $row->update(['delivery' => $state = $message ? $this->deliveryState($message->refresh()) : ChatbotExecution::DELIVERY_UNCERTAIN]);
            $this->auditDelivery($row->load('chatbot.workspace'), $state);

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

    private function settle(ChatbotExecution $execution, string $status, string $code, ?string $cause = null): RunResult
    {
        $changed = ChatbotExecution::whereKey($execution->id)->where('status', ChatbotExecution::RUNNING)->update(['status' => $status, 'outcome_code' => $code, 'finished_at' => now()]) === 1;

        if ($changed && $status === ChatbotExecution::FAILED) {
            $this->auditFailure($execution, $code);
        }

        // A failure the contact would never notice is not allowed to stay silent: a message too old to answer (the queue was stuck) counts as one,
        // the control rules that discard on purpose do not. The transition above happens once, so the handover below does too.
        if ($changed && ($status === ChatbotExecution::FAILED || $code === 'stale')) {
            $this->handOver($execution, $code, $cause);
        }

        return $this->finish($execution, $status === ChatbotExecution::FAILED ? RunResult::failed($code) : RunResult::discarded($code));
    }

    /**
     * D-FALLO: the AI could not answer for good. The conversation moves to a person through the control service (only if the AI still has it, as it
     * was when the execution started), an operational alert is raised and, if the business configured an approved text, the contact gets that one
     * notice. Everything hangs on the control service really moving the conversation, so a repeated call, a recovery or a duplicate event finds it
     * already handed over and does nothing more. The Workspace comes from the execution's own chatbot, so a failure never touches another one.
     * True only when this call moved the conversation.
     */
    private function handOver(ChatbotExecution $execution, string $code, ?string $cause = null): bool
    {
        try {
            $execution->loadMissing('chatbot.workspace');
            $conversation = Conversation::where('workspace_id', $execution->workspace_id)->find($execution->conversation_id);
            $moved = $conversation !== null && $this->control->handOverAfterFailure($conversation, $execution->handling_version, $code);

            ChatbotExecution::whereKey($execution->id)->whereNull('control_result')->update(['control_result' => $moved ? 'escalated' : 'not_escalated']);
        } catch (\Throwable $e) {
            // Never lets the failure it is reporting turn into another one; recovery tries again while the execution is recent.
            Log::error('chatbot_handover_failed', ['exception' => $e::class, 'execution_id' => $execution->id]);

            return false;
        }

        if (! $moved) {
            return false;
        }

        $this->alerts->raise('ai_failure_handed_to_human', [
            'workspace' => $execution->workspace_id, 'chatbot' => $execution->chatbot_id, 'conversation' => $execution->conversation_id, 'execution' => $execution->correlation_id,
            'code' => $code, 'kind' => FailureKind::of($cause ?? $code), 'cause' => $cause, 'attempts' => $execution->attempts,
        ]);
        $this->notifyContact($conversation);

        return true;
    }

    /** The one approved contingency text (`chatbots.failure_notice`), if the business configured it; nothing is invented, promised or confirmed. */
    private function notifyContact(Conversation $conversation): void
    {
        $text = trim((string) config('chatbots.failure_notice'));

        if ($text === '' || $conversation->isDemo()) {
            return;
        }

        try {
            $message = $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id, 'direction' => 'out', 'sender' => 'ai', 'type' => 'text',
                'body' => mb_substr($text, 0, (int) config('n8n.max_reply')), 'status' => Message::PENDING, 'sent_at' => now(),
            ]);
            $conversation->forceFill(['last_message_at' => $message->sent_at])->save();
            $this->delivery->deliver($message);
        } catch (\Throwable $e) {
            Log::error('chatbot_failure_notice_failed', ['exception' => $e::class, 'conversation' => $conversation->id]);
        }
    }

    /**
     * An execution that failed for good is an event of the automatic process, with a fixed code and the attempts made.
     * (Discarded executions are not: they are the control rules working, and a successful reply is the message itself.)
     */
    private function auditFailure(ChatbotExecution $execution, string $code): void
    {
        $execution->loadMissing('chatbot.workspace');
        $this->audit->system('executed', 'chatbot_execution', $execution->id, $execution->chatbot->name, [
            AuditLogger::change('Resultado', null, 'Falló'),
            AuditLogger::change('Código', null, $code),
            AuditLogger::change('Intentos', null, (string) $execution->attempts),
        ], $execution->chatbot->workspace, 'Ejecución automática', AuditLogger::FAILED);
    }

    /** A reply the channel refused, or whose outcome nobody knows, is an event of the automatic process (an accepted one is not). */
    private function auditDelivery(ChatbotExecution $execution, string $state): void
    {
        if (! in_array($state, [ChatbotExecution::DELIVERY_FAILED, ChatbotExecution::DELIVERY_UNCERTAIN], true)) {
            return;
        }

        $this->audit->system('executed', 'chatbot_execution', $execution->id, $execution->chatbot->name, [
            AuditLogger::change('Envío de la respuesta', null, $state === ChatbotExecution::DELIVERY_FAILED ? 'Rechazado por el canal' : 'Sin confirmar'),
        ], $execution->chatbot->workspace, 'Ejecución automática', AuditLogger::FAILED);
    }

    /** Operational log: identifiers and fixed codes only, never message text, contact data or credentials. */
    private function finish(ChatbotExecution $execution, RunResult $result): RunResult
    {
        Log::info('chatbot_execution', ['event' => $result->kind, 'code' => $result->code, 'execution' => $execution->correlation_id, 'conversation' => $execution->conversation_id, 'attempt' => $execution->attempts]);

        return $result;
    }
}
