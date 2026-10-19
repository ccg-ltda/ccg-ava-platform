<?php

namespace Tests\Feature;

use App\Chatbots\ChatbotExecutor;
use App\Chatbots\ExecutionOutcome;
use App\Chatbots\ResolvedWorkflow;
use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ConversationControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D-FALLO: when the AI fails for good the conversation is handed to a person and an operational alert is raised, the contact gets only the
 * approved notice (if one is configured), and nothing is pretended: no reply is invented, no booking confirmed. Meta and n8n are always simulated.
 */
class AiFailureHandoverTest extends TestCase
{
    use RefreshDatabase;

    private const N8N = 'https://n8n.platform.example.com/webhook';

    private const APP_SECRET = 'meta-app-SECRET-456';

    private const GRAPH = 'https://graph.facebook.com/*';

    private int $sequence = 0;

    /** What the simulated n8n does with the next calls: set with n8n(). */
    private $handler;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config([
            'n8n.execution_url' => self::N8N, 'n8n.secret' => 'n8n-shared-SECRET-123', 'n8n.backoff' => [0, 0],
            'services.meta.app_secret' => self::APP_SECRET, 'services.meta.webhook_verify_token' => 'verify-me',
            'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            self::N8N.'/*' => fn (Request $request) => ($this->handler)($request),
            self::GRAPH => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.++$this->sequence]]]),
        ]);
        Log::listen(function (MessageLogged $event) {
            $this->logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
        Queue::fake();
    }

    // --- scenery -----------------------------------------------------------------------------------------------

    /** @return array{Chatbot, string} a chatbot with the support workflow and its own WhatsApp number */
    private function bot(string $code, string $phone): array
    {
        $workspace = Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
        $bot = $workspace->chatbots()->create(['name' => "Asistente {$code}", 'instructions' => 'Atiende']);
        $integration = $workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => $phone], 'secrets' => ['access_token' => 'EAAB-private-token-XYZ']]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'support');

        return [$bot, $phone];
    }

    private function receive(string $phone, string $text, string $from = '573001112233', ?string $id = null): void
    {
        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $phone],
            'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $id ?? 'wamid.IN'.++$this->sequence, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::APP_SECRET)], $raw)->assertOk();
    }

    /** The job the webhook queued: runs it (and its retries) like the worker does, returning the last result. */
    private function runToEnd(): string
    {
        $job = Queue::pushed(RunChatbotExecution::class)->last();

        do {
            $result = app(ChatbotExecutions::class)->run($job->executionId);
        } while ($result->kind === 'retry');

        return $result->code;
    }

    /** @param  callable|array{0: mixed, 1: int}  $response */
    private function n8n(callable|array $response): void
    {
        $this->handler = is_callable($response) ? $response : fn () => Http::response($response[0], $response[1]);
    }

    private function conversation(Chatbot $bot, string $contact = '573001112233'): Conversation
    {
        return $bot->conversations()->where('contact_id', $contact)->firstOrFail()->refresh();
    }

    private function execution(Conversation $conversation): ChatbotExecution
    {
        return ChatbotExecution::where('conversation_id', $conversation->id)->latest('id')->firstOrFail();
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function alerts(): array
    {
        return array_values(array_filter($this->logs, fn ($entry) => $entry['message'] === 'ai_failure_handed_to_human'));
    }

    private function graphCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com'))->count();
    }

    // --- the classes of failure ---------------------------------------------------------------------------------

    public function test_a_server_error_that_never_recovers_hands_the_conversation_to_a_person_after_the_retries(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['boom', 503]);

        $this->receive($phone, 'Quiero una cita');
        $this->assertSame('retries_exhausted', $this->runToEnd());

        $conversation = $this->conversation($bot);
        $execution = $this->execution($conversation);
        $this->assertSame([Conversation::PENDING, null], [$conversation->handling, $conversation->assigned_user_id]);
        $this->assertStringContainsString('retries_exhausted', $conversation->handoff_reason);
        $this->assertSame(['failed', 'retries_exhausted', 'escalated', 3], [$execution->status, $execution->outcome_code, $execution->control_result, $execution->attempts]);
        $this->assertCount(1, $this->alerts());
        $this->assertSame(['code' => 'retries_exhausted', 'kind' => 'transient', 'cause' => 'n8n_unavailable'], array_intersect_key($this->alerts()[0]['context'], array_flip(['code', 'kind', 'cause'])));
        // Nothing was pretended: the only outgoing text would be an approved notice, and none is configured.
        $this->assertSame(0, $conversation->messages()->where('direction', 'out')->count());
        $this->assertSame(0, $this->graphCalls());
    }

    public function test_a_timeout_is_told_apart_from_an_unreachable_n8n(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 25001 milliseconds'));
        $this->receive($phone, 'Hola');
        $this->assertSame('retries_exhausted', $this->runToEnd());
        $this->assertSame(['kind' => 'timeout', 'cause' => 'timeout'], array_intersect_key($this->alerts()[0]['context'], array_flip(['kind', 'cause'])));

        [$other, $otherPhone] = $this->bot('WS_B', '1000002');
        $this->n8n(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: n8n.platform.example.com'));
        $this->receive($otherPhone, 'Hola');
        $this->assertSame('retries_exhausted', $this->runToEnd());
        $this->assertSame(['kind' => 'transient', 'cause' => 'unreachable'], array_intersect_key($this->alerts()[1]['context'], array_flip(['kind', 'cause'])));
        $this->assertSame(Conversation::PENDING, $this->conversation($other)->handling);
    }

    public function test_an_invalid_answer_fails_at_once_without_retrying_and_without_a_reply_to_the_contact(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');

        foreach (['another execution' => ['execution_id' => 'otra', 'reply' => 'x'], 'a list' => [['execution_id' => 'x', 'reply' => 'y']], 'immediately' => ['message' => 'Workflow was started']] as $name => $body) {
            $this->n8n([$body, 200]);
            $contact = '57300111'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT);
            $this->receive($phone, "Mensaje {$name}", $contact);
            $this->assertSame('invalid_response', $this->runToEnd(), $name);
            $conversation = $this->conversation($bot, $contact);
            $this->assertSame(Conversation::PENDING, $conversation->handling, $name);
            $this->assertSame(1, $this->execution($conversation)->attempts, $name);
            $this->assertSame(0, $conversation->messages()->where('direction', 'out')->count(), $name);
        }

        $this->assertCount(3, $this->alerts());
        $this->assertSame('invalid_response', $this->alerts()[0]['context']['kind']);
    }

    public function test_an_authentication_error_is_not_retried(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['forbidden', 403]);

        $this->receive($phone, 'Hola');

        $this->assertSame('n8n_auth', $this->runToEnd());
        $this->assertSame(1, $this->execution($this->conversation($bot))->attempts);
        $this->assertSame(['n8n_auth', 'auth'], [$this->alerts()[0]['context']['code'], $this->alerts()[0]['context']['kind']]);
        $this->assertSame(Conversation::PENDING, $this->conversation($bot)->handling);
    }

    public function test_a_broken_setup_is_a_definitive_failure_too(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n([[], 200]);
        $this->receive($phone, 'Hola');
        config(['n8n.secret' => null]);

        $this->assertSame('not_configured', $this->runToEnd());
        $this->assertSame(Conversation::PENDING, $this->conversation($bot)->handling);
        $this->assertSame('configuration', $this->alerts()[0]['context']['kind']);
    }

    // --- the queue ---------------------------------------------------------------------------------------------

    public function test_a_lost_job_is_recovered_and_a_message_too_old_to_answer_goes_to_a_person_instead_of_to_silence(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n([['ok' => true], 200]);
        $this->receive($phone, 'Hola');
        // The queue lost the job: nobody runs it. Recovery queues it again, but by then the contact has moved on.
        $this->travel(11)->minutes();
        $this->artisan('executions:recover')->assertSuccessful();
        $this->assertCount(2, Queue::pushed(RunChatbotExecution::class));

        $this->assertSame('stale', $this->runToEnd());

        $conversation = $this->conversation($bot);
        $this->assertSame(Conversation::PENDING, $conversation->handling);
        $this->assertSame(['discarded', 'stale', 'escalated'], [$this->execution($conversation)->status, $this->execution($conversation)->outcome_code, $this->execution($conversation)->control_result]);
        $this->assertSame('queue', $this->alerts()[0]['context']['kind']);
        $this->assertSame(0, Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::N8N))->count());
    }

    public function test_repeated_crashes_of_the_job_hand_the_conversation_over_on_the_last_run(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->app->instance(ChatbotExecutor::class, new class implements ChatbotExecutor
        {
            public function run(ResolvedWorkflow $workflow, string $correlationId, array $payload): ExecutionOutcome
            {
                throw new \RuntimeException('SQLSTATE ... Contenido privado');
            }
        });
        $this->receive($phone, 'Contenido privado');
        $job = Queue::pushed(RunChatbotExecution::class)->last();

        foreach ([1, 2] as $run) {
            $job->handle(app(ChatbotExecutions::class));
            $this->assertSame(Conversation::AI, $this->conversation($bot)->handling, "run {$run} leaves recovery in charge");
            $this->travel(2)->minutes(); // the abandoned run is older than the execution timeout, so it can be claimed again
        }

        $job->handle(app(ChatbotExecutions::class));

        $execution = $this->execution($this->conversation($bot));
        $this->assertSame([Conversation::PENDING, 'failed', 'internal_error', 3], [$this->conversation($bot)->handling, $execution->status, $execution->outcome_code, $execution->attempts]);
        $this->assertSame('queue', $this->alerts()[0]['context']['kind']);
        foreach ($this->logs as $entry) {
            $this->assertStringNotContainsString('Contenido privado', $entry['message'].json_encode($entry['context']));
        }
    }

    // --- idempotency and control rules -------------------------------------------------------------------------

    public function test_a_repeated_run_event_or_recovery_hands_over_alerts_and_notifies_exactly_once(): void
    {
        config(['chatbots.failure_notice' => 'Tuvimos un problema técnico. Una persona revisará tu mensaje.']);
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['no', 404]);
        $this->receive($phone, 'Hola', id: 'wamid.SAME');
        $job = Queue::pushed(RunChatbotExecution::class)->last();
        $this->assertSame('n8n_rejected', $this->runToEnd());
        $version = $this->conversation($bot)->handling_version;

        $this->receive($phone, 'Hola', id: 'wamid.SAME'); // Meta repeats the event
        $again = app(ChatbotExecutions::class)->run($job->executionId); // the queue redelivers the job
        app(ChatbotExecutions::class)->recover();
        $this->travel(10)->minutes();
        app(ChatbotExecutions::class)->recover();

        $this->assertSame(['discarded', 'not_runnable'], [$again->kind, $again->code]);
        $conversation = $this->conversation($bot);
        $this->assertSame($version, $conversation->handling_version);
        $this->assertCount(1, $this->alerts());
        $this->assertSame(1, ChatbotExecution::count());
        $this->assertSame(1, AuditLog::where('resource_type', 'conversation')->where('action', 'requested')->count());
        $this->assertSame(1, $this->graphCalls());
        $this->assertSame(['Tuvimos un problema técnico. Una persona revisará tu mensaje.'], $conversation->messages()->where('direction', 'out')->pluck('body')->all());
    }

    public function test_recovery_completes_a_handover_that_a_crash_left_undone_and_only_once(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['no', 404]);
        $this->receive($phone, 'Hola');
        $this->runToEnd();
        // The process died right after the failure was recorded: the execution failed, but the conversation still looks like the AI has it.
        $conversation = $this->conversation($bot);
        $conversation->forceFill(['handling' => Conversation::AI, 'handoff_reason' => null])->save();
        $execution = $this->execution($conversation);
        $execution->forceFill(['control_result' => null, 'handling_version' => $conversation->handling_version])->save();
        $this->logs = [];

        $this->artisan('executions:recover')->assertSuccessful()->expectsOutputToContain('Conversaciones pasadas a una persona por un fallo: 1');
        $this->artisan('executions:recover')->assertSuccessful()->expectsOutputToContain('Conversaciones pasadas a una persona por un fallo: 0');

        $this->assertSame(Conversation::PENDING, $this->conversation($bot)->handling);
        $this->assertSame('escalated', $execution->refresh()->control_result);
        $this->assertCount(1, $this->alerts());
    }

    public function test_the_notice_is_only_the_configured_text_and_nothing_goes_out_without_one(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['no', 404]);
        $this->receive($phone, 'Reserva mi cita mañana');
        $this->runToEnd();
        $this->assertSame(0, $this->graphCalls());

        config(['chatbots.failure_notice' => 'Aviso aprobado.']);
        $this->receive($phone, 'Otra', '573009998877');
        $this->runToEnd();
        $this->assertSame(1, $this->graphCalls());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com') && $request['text']['body'] === 'Aviso aprobado.' && $request['to'] === '573009998877');
        $this->assertSame('sent', $this->conversation($bot, '573009998877')->messages()->where('direction', 'out')->firstOrFail()->status);
    }

    public function test_a_conversation_a_person_already_holds_is_left_exactly_as_it_is(): void
    {
        config(['chatbots.failure_notice' => 'Aviso aprobado.']);
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $agent = User::factory()->create();
        $this->receive($phone, 'Hola');

        // A person takes the conversation while the workflow call is in flight, and then the call fails for good.
        $this->n8n(function () use ($bot, $agent) {
            app(ConversationControl::class)->take($this->conversation($bot), $agent);

            return Http::response('no', 404);
        });
        $this->assertSame('n8n_rejected', $this->runToEnd());

        $after = $this->conversation($bot);
        $this->assertSame([Conversation::HUMAN, $agent->id], [$after->handling, $after->assigned_user_id]);
        $this->assertSame('not_escalated', $this->execution($after)->control_result);
        $this->assertSame([], $this->alerts());
        $this->assertSame(0, $this->graphCalls());
        $this->assertSame(0, $after->messages()->where('direction', 'out')->count());
    }

    public function test_a_conversation_already_pending_for_a_person_is_not_asked_for_twice(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->receive($phone, 'Hola');
        $this->n8n(function () use ($bot) {
            app(ConversationControl::class)->requestHuman($this->conversation($bot), 'Solicitado por el asistente.');

            return Http::response('no', 404);
        });
        $requested = fn () => AuditLog::where('resource_type', 'conversation')->where('action', 'requested')->count();

        $this->assertSame('n8n_rejected', $this->runToEnd());

        $this->assertSame(1, $requested());
        $this->assertSame([], $this->alerts());
        $this->assertSame('not_escalated', $this->execution($this->conversation($bot))->control_result);
    }

    public function test_a_resolved_conversation_is_never_reopened_by_a_failure(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $agent = User::factory()->create();
        $this->receive($phone, 'Hola');
        $this->n8n(function () use ($bot, $agent) {
            $control = app(ConversationControl::class);
            $control->take($this->conversation($bot), $agent);
            $control->resolve($this->conversation($bot), $agent, true);

            return Http::response('no', 404);
        });

        $this->assertSame('n8n_rejected', $this->runToEnd());

        $after = $this->conversation($bot);
        $this->assertSame(Conversation::RESOLVED, $after->handling);
        $this->assertSame([], $this->alerts());
        $this->assertSame('not_escalated', $this->execution($after)->control_result);
    }

    public function test_after_a_failure_the_ai_stays_quiet_until_a_person_gives_the_conversation_back_and_then_answers_normally(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['no', 404]);
        $this->receive($phone, 'Hola');
        $this->runToEnd();
        $this->assertSame(Conversation::PENDING, $this->conversation($bot)->handling);

        // n8n is back, but the contact writes again while a person still has to attend: no new run (no duplicate assignment, no second alert).
        $this->n8n(fn (Request $request) => Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Listo']));
        $this->receive($phone, '¿Hola?');
        $this->assertCount(1, Queue::pushed(RunChatbotExecution::class));
        $this->assertCount(1, $this->alerts());

        $agent = User::factory()->create();
        app(ConversationControl::class)->returnToAi($this->conversation($bot), $agent, true);
        $this->receive($phone, 'Ahora sí');
        $this->assertSame('ok', $this->runToEnd());

        $this->assertSame(['Listo'], $this->conversation($bot)->messages()->where('direction', 'out')->pluck('body')->all());
        $this->assertCount(1, $this->alerts());
        $this->assertSame(Conversation::AI, $this->conversation($bot)->handling);
    }

    // --- isolation and privacy ---------------------------------------------------------------------------------

    public function test_a_failure_in_one_workspace_does_not_touch_another(): void
    {
        [$a, $phoneA] = $this->bot('WS_A', '1000001');
        [$b, $phoneB] = $this->bot('WS_B', '1000002');
        $this->n8n(fn (Request $request) => str_contains($request->body(), 'Falla ahora') ? Http::response('no', 404) : Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola B']));

        $this->receive($phoneB, 'Todo bien');
        $this->assertSame('ok', $this->runToEnd());
        $this->receive($phoneA, 'Falla ahora');
        $this->assertSame('n8n_rejected', $this->runToEnd());

        $this->assertSame(Conversation::PENDING, $this->conversation($a)->handling);
        $this->assertSame(Conversation::AI, $this->conversation($b)->handling);
        $this->assertSame(['Hola B'], $this->conversation($b)->messages()->where('direction', 'out')->pluck('body')->all());
        $this->assertCount(1, $this->alerts());
        $this->assertSame($a->workspace_id, $this->alerts()[0]['context']['workspace']);
        $this->assertSame(0, AuditLog::where('workspace_id', $b->workspace_id)->where('action', 'requested')->count());
        $this->assertSame(1, AuditLog::where('workspace_id', $a->workspace_id)->where('action', 'requested')->count());
    }

    public function test_alerts_and_logs_carry_identifiers_and_codes_never_the_message_or_the_contact(): void
    {
        [$bot, $phone] = $this->bot('WS_A', '1000001');
        $this->n8n(['no', 404]);

        $this->receive($phone, 'Tengo una cita por mi diagnóstico de diabetes', '573001112233');
        $this->runToEnd();

        $this->assertNotEmpty($this->alerts());
        foreach ($this->logs as $entry) {
            $text = $entry['message'].json_encode($entry['context']);
            $this->assertStringNotContainsString('diabetes', $text);
            $this->assertStringNotContainsString('573001112233', $text);
            $this->assertStringNotContainsString('EAAB-private-token-XYZ', $text);
            $this->assertStringNotContainsString('n8n-shared-SECRET-123', $text);
        }
        $reason = $this->conversation($bot)->handoff_reason;
        $this->assertStringNotContainsString('573001112233', $reason);
        $this->assertStringNotContainsString('diabetes', $reason);
    }
}
