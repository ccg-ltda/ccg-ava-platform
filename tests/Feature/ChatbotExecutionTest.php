<?php

namespace Tests\Feature;

use App\Chatbots\ChatbotExecutor;
use App\Chatbots\ExecutionOutcome;
use App\Chatbots\ResolvedWorkflow;
use App\Chatbots\RunResult;
use App\Integrations\SafeHttpTarget;
use App\Integrations\WhatsAppType;
use App\Jobs\RunChatbotExecution;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ConversationControl;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ava starts the execution of a chatbot's n8n workflow for a message that Meta's webhook delivered, and decides what
 * happens with the answer. Meta and n8n are always simulated here: nothing in this file proves a real connection.
 */
class ChatbotExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const N8N = 'https://n8n.platform.example.com/webhook';

    private const SECRET = 'n8n-shared-SECRET-123';

    private const APP_SECRET = 'meta-app-SECRET-456';

    private const TOKEN = 'EAAB-private-token-XYZ';

    private const GRAPH = 'https://graph.facebook.com/*';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config([
            'n8n.execution_url' => self::N8N, 'n8n.secret' => self::SECRET, 'n8n.backoff' => [0, 0],
            'services.meta.app_secret' => self::APP_SECRET, 'services.meta.webhook_verify_token' => 'verify-me',
        ]);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    // --- scenery -----------------------------------------------------------------------------------------------

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    /** Adds a workflow to the platform catalog (config/n8n.php) and returns its key. */
    private function workflow(string $key = 'support', string $path = 'support-v1'): string
    {
        config(["n8n.workflows.{$key}" => ['name' => "Workflow {$key}", 'n8n_workflow_id' => 'wf_'.$key, 'webhook_path' => $path, 'version' => '1']]);

        return $key;
    }

    /** A chatbot with an active WhatsApp channel on its own number; returns [chatbot, phoneNumberId]. */
    private function bot(Workspace $workspace, string $phone, ?string $workflow = null, string $name = 'Asistente'): array
    {
        $bot = $workspace->chatbots()->create(['name' => $name, 'instructions' => "Instrucciones de {$name}"]);
        $integration = $workspace->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => $phone], 'secrets' => ['access_token' => self::TOKEN],
        ]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        $workflow && app(ChatbotWorkflows::class)->assign($bot->refresh(), $workflow);

        return [$bot, $phone];
    }

    /** n8n answers with the reply, echoing the execution id of the request (what a correct workflow does). */
    private function n8nAnswers(string $reply = 'Hola, ¿en qué te ayudo?', array $extra = [], ?callable $during = null): void
    {
        Http::fake([
            self::N8N.'/*' => function (Request $request) use ($reply, $extra, $during) {
                $during && $during($request);

                return Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => $reply] + $extra);
            },
            self::GRAPH => Http::response(['messages' => [['id' => 'wamid.OUT'.++$this->sequence]]]),
        ]);
    }

    private function event(string $phone, string $text = 'Hola', string $from = '573001112233', ?string $id = null, string $name = 'Ana'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $phone],
                'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
                'messages' => [['from' => $from, 'id' => $id ?? 'wamid.IN'.++$this->sequence, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]],
        ];
    }

    /** Posts a Meta event signed like Meta does (HMAC-SHA256 of the raw body with the app secret). */
    private function deliver(array $payload, ?string $secret = self::APP_SECRET)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? 'none'),
        ], $raw);
    }

    private function conversation(Chatbot $bot, string $contact = '573001112233'): Conversation
    {
        return $bot->conversations()->where('contact_id', $contact)->firstOrFail();
    }

    /** The execution job the webhook queued (use with Queue::fake()). */
    private function queued(): RunChatbotExecution
    {
        return Queue::pushed(RunChatbotExecution::class)->last();
    }

    private function runJob(RunChatbotExecution $job): RunResult
    {
        return app(ChatbotExecutions::class)->run($job->executionId);
    }

    /** The newest execution row. */
    private function execution(): ChatbotExecution
    {
        return ChatbotExecution::orderByDesc('id')->firstOrFail();
    }

    /** Another inbound message from `$contact` (queue faked): returns the execution it created. */
    private function newExecutionFor(Chatbot $bot, string $text, string $contact, string $phone = '1000001'): ChatbotExecution
    {
        $this->deliver($this->event($phone, $text, $contact))->assertOk();

        return ChatbotExecution::whereHas('conversation', fn ($q) => $q->where('contact_id', $contact)->where('chatbot_id', $bot->id))->latest('id')->firstOrFail();
    }

    /** States of the INBOUND messages that are set (an execution must never set one: that field is the message's own delivery state). */
    private function inboundStatuses(): array
    {
        return Message::where('direction', 'in')->whereNotNull('status')->pluck('status')->all();
    }

    /** @var list<array{level: string, text: string, context: array<string, mixed>}> */
    private array $logs = [];

    private function captureLogs(): void
    {
        Log::listen(function (MessageLogged $event) {
            $this->logs[] = ['level' => $event->level, 'text' => $event->message.json_encode($event->context), 'context' => $event->context];
        });
    }

    private function assertLogged(string $event, string $code): void
    {
        $found = array_filter($this->logs, fn ($entry) => ($entry['context']['event'] ?? null) === $event && ($entry['context']['code'] ?? null) === $code);

        $this->assertNotEmpty($found, "Expected an execution log {$event}/{$code}.");
    }

    private function n8nCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::N8N))->count();
    }

    private function graphCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com'))->count();
    }

    // --- 1. association ----------------------------------------------------------------------------------------

    public function test_an_inbound_message_belongs_only_to_the_channel_chatbot_and_workspace_of_its_number(): void
    {
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', null, 'Bot A');
        [$botB] = $this->bot($b, '2000002', null, 'Bot B');

        $this->deliver($this->event('2000002', 'Para B'))->assertOk();

        $this->assertSame(0, $botA->conversations()->count());
        $conversation = $this->conversation($botB);
        $this->assertSame($b->id, $conversation->workspace_id);
        $this->assertSame('whatsapp', $conversation->channel);
        $this->assertSame($b->id, $conversation->messages()->firstOrFail()->workspace_id);
        $this->assertSame(0, Message::where('workspace_id', $a->id)->count());
    }

    public function test_a_number_nobody_owns_or_two_integrations_claim_is_ignored_never_guessed(): void
    {
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        $this->bot($a, '1000001');
        $this->bot($b, '1000001');
        $this->bot($this->workspace('WS_C'), '3000003');

        $this->deliver($this->event('1000001'))->assertOk();
        $this->deliver($this->event('9999999'))->assertOk();

        $this->assertSame(0, Message::count());
    }

    public function test_switched_off_chatbots_workspaces_and_channels_store_nothing(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->bot($workspace, '1000001');

        $bot->update(['is_active' => false]);
        $this->deliver($this->event('1000001'))->assertOk();
        $bot->update(['is_active' => true]);
        $bot->channels()->update(['is_active' => false]);
        $this->deliver($this->event('1000001'))->assertOk();
        $bot->channels()->update(['is_active' => true]);
        $workspace->forceFill(['is_active' => false])->save();
        $this->deliver($this->event('1000001'))->assertOk();

        $this->assertSame(0, Message::count());
    }

    // --- webhook authenticity ----------------------------------------------------------------------------------

    public function test_the_webhook_refuses_events_that_are_not_signed_by_meta(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers();

        $this->deliver($this->event('1000001'), 'someone-elses-secret')->assertForbidden();
        $this->post('/api/webhooks/whatsapp', $this->event('1000001'))->assertForbidden();
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=00'], json_encode($this->event('1000001')))->assertForbidden();

        $this->assertSame(0, Message::count());
        $this->assertSame(0, $this->n8nCalls());
        $this->assertSame(0, $bot->conversations()->count());
    }

    public function test_the_webhook_does_not_exist_until_it_is_configured_and_verifies_meta_with_the_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertOk()->assertSee('12345', false);
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_challenge=12345')->assertForbidden();

        config(['services.meta.app_secret' => null]);
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertNotFound();
        $this->deliver($this->event('1000001'))->assertNotFound();
    }

    public function test_a_malformed_or_foreign_payload_changes_nothing(): void
    {
        $this->bot($this->workspace('WS_A'), '1000001');

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', 'not json', self::APP_SECRET)], 'not json')->assertStatus(400);
        $this->deliver(['object' => 'page', 'entry' => []])->assertOk();
        $this->deliver(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'other', 'value' => []]]]]])->assertOk();

        $this->assertSame(0, Message::count());
    }

    // --- 2. workflows are the chatbot's own --------------------------------------------------------------------

    public function test_a_chatbot_without_a_workflow_runs_nothing_even_when_another_workspace_has_one(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        $this->bot($a, '1000001', $workflow, 'Bot A');
        [$botB] = $this->bot($b, '2000002', null, 'Bot B');
        $this->n8nAnswers();

        $this->deliver($this->event('2000002', 'Hola B'))->assertOk();

        $this->assertSame(0, $this->n8nCalls());
        $this->assertSame(1, $botB->conversations()->firstOrFail()->messages()->count());
    }

    public function test_two_chatbots_may_share_a_workflow_without_sharing_anything_else(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', $workflow, 'Bot A');
        [$botB] = $this->bot($b, '2000002', $workflow, 'Bot B');
        $this->n8nAnswers();

        $this->deliver($this->event('1000001', 'Secreto de A', '573001110001'))->assertOk();
        $this->deliver($this->event('2000002', 'Hola desde B', '573001110002'))->assertOk();

        $bodies = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::N8N))->map(fn ($pair) => $pair[0]->data());
        $this->assertCount(2, $bodies);
        $forB = $bodies->firstWhere('chatbot.id', $botB->id);
        $this->assertSame('Hola desde B', $forB['message']['text']);
        $this->assertSame([], $forB['history']);
        $this->assertStringNotContainsString('Secreto de A', json_encode($forB));
        $this->assertNotSame($bodies->firstWhere('chatbot.id', $botA->id)['conversation']['memory_key'], $forB['conversation']['memory_key']);
    }

    public function test_the_same_contact_in_two_workspaces_gets_different_memory_keys(): void
    {
        $workflow = $this->workflow();
        $this->bot($this->workspace('WS_A'), '1000001', $workflow);
        $this->bot($this->workspace('WS_B'), '2000002', $workflow);
        $this->n8nAnswers();

        $this->deliver($this->event('1000001'))->assertOk();
        $this->deliver($this->event('2000002'))->assertOk();

        $keys = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::N8N))->map(fn ($pair) => $pair[0]->data()['conversation']['memory_key']);
        $this->assertCount(2, $keys->unique());
    }

    public function test_a_workflow_key_is_only_stored_for_an_active_chatbot_with_a_registered_enabled_workflow(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001');
        [$botB] = $this->bot($b, '2000002', $workflow);
        $workflows = app(ChatbotWorkflows::class);
        $this->assertSame('support', $workflows->for($botB)->key);
        $this->assertNull($workflows->for($botA));

        // WRITING: every Eloquent write of the key is checked (the hook), not only the command's.
        $invalid = [
            'a workflow that is not registered' => fn () => $botA->forceFill(['workflow_key' => 'inventado'])->save(),
            'a path that escapes the webhook' => function () use ($botA) {
                config(['n8n.workflows.malo' => ['n8n_workflow_id' => 'x', 'webhook_path' => '../admin']]);
                $botA->forceFill(['workflow_key' => 'malo'])->save();
            },
            'a disabled workflow' => function () use ($botA, $workflow) {
                config(["n8n.workflows.{$workflow}.enabled" => false]);
                try {
                    $botA->forceFill(['workflow_key' => $workflow])->save();
                } finally {
                    config(["n8n.workflows.{$workflow}.enabled" => true]);
                }
            },
            'an inactive chatbot' => function () use ($botA, $workflow) {
                $botA->forceFill(['is_active' => false, 'workflow_key' => $workflow])->save();
            },
            'a demo chatbot' => function () use ($botA, $workflow) {
                $botA->forceFill(['is_active' => true, 'is_demo' => true, 'workflow_key' => $workflow])->save();
            },
        ];

        foreach ($invalid as $case => $write) {
            try {
                $write();
                $this->fail("Stored {$case}.");
            } catch (\InvalidArgumentException) {
                $this->assertNull(Chatbot::find($botA->id)->workflow_key, $case);
            }
        }

        // Other columns can be changed without the key being asked again, and a valid key is stored.
        $workflows->assign($botA->refresh(), $workflow);
        $this->assertSame('support', Chatbot::find($botA->id)->workflow_key);
        $workflows->unassign($botA);
        $this->assertNull(Chatbot::find($botA->id)->workflow_key);
    }

    public function test_a_stored_key_that_stopped_being_valid_grants_nothing(): void
    {
        $workflow = $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $workflow);
        $workflows = app(ChatbotWorkflows::class);

        // READING checks the catalog again: raw writes and catalog changes are ignored.
        DB::table('chatbots')->where('id', $bot->id)->update(['workflow_key' => 'inventado']);
        $this->assertNull($workflows->for($bot->refresh()), 'unknown workflow');
        DB::table('chatbots')->where('id', $bot->id)->update(['workflow_key' => $workflow]);
        $this->assertNotNull($workflows->for($bot->refresh()));

        config(['n8n.workflows.support.enabled' => false]);
        $this->assertNull($workflows->for($bot), 'disabled in the catalog');
        config(['n8n.workflows.support.enabled' => true, 'n8n.workflows.support.webhook_path' => '../admin']);
        $this->assertNull($workflows->for($bot), 'path traversal');
        config(['n8n.workflows.support.webhook_path' => 'https://evil.example.com/x']);
        $this->assertNull($workflows->for($bot), 'absolute URL as path');
        config(['n8n.workflows.support.webhook_path' => 'support-v1']);
        $this->assertNotNull($workflows->for($bot));
        config(['n8n.workflows' => []]);
        $this->assertNull($workflows->for($bot), 'removed from the catalog');
    }

    public function test_the_assignment_belongs_to_the_chatbot_so_it_cannot_point_at_another_workspace(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', $workflow);
        [$botB] = $this->bot($b, '2000002');

        // The key is a column of the chatbot: assigning A's workflow can only ever change A's chatbot.
        $this->assertSame(1, Chatbot::where('workflow_key', 'support')->count());
        $this->assertSame($botA->id, Chatbot::where('workflow_key', 'support')->value('id'));
        $this->assertNull(app(ChatbotWorkflows::class)->for($botB));
    }

    public function test_changing_or_removing_the_workflow_before_it_runs_discards_the_execution(): void
    {
        $this->workflow();
        $this->workflow('other', 'other-v1');
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $execution = $this->execution();
        $this->assertSame(['support', 'wf_support', '1'], [$execution->workflow_key, $execution->n8n_workflow_id, $execution->workflow_version]);

        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'other');
        $this->assertSame('workflow_changed', $this->runJob($this->queued())->code);
        $this->assertSame(['discarded', 'workflow_changed'], [$execution->refresh()->status, $execution->outcome_code]);

        $execution->update(['status' => 'pending']);
        app(ChatbotWorkflows::class)->unassign($bot);
        $this->assertSame('no_workflow', $this->runJob($this->queued())->code);
        $this->assertSame(0, $this->n8nCalls());
        $this->assertSame([], $this->inboundStatuses());
    }

    public function test_a_client_cannot_assign_see_or_change_a_workflow_through_the_chatbot_pages(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$bot] = $this->bot($a, '1000001');
        [$botB] = $this->bot($b, '2000002', $workflow);
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, 'admin');
        $this->withSession(['workspace_id' => $a->id]);
        $a->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);

        $this->post("/chatbots/{$bot->id}", ['name' => 'Renombrado', 'workflow' => $workflow, 'workflow_key' => $workflow]);
        $this->assertNull(Chatbot::find($bot->id)->workflow_key);

        $this->post("/chatbots/{$botB->id}", ['name' => 'Robado', 'workflow_key' => null])->assertNotFound();
        $this->get("/chatbots/{$botB->id}")->assertNotFound();
        $this->assertSame('support', Chatbot::find($botB->id)->workflow_key);

        foreach (["/chatbots/{$bot->id}", '/chatbots', '/integrations', '/conversations'] as $url) {
            $this->assertStringNotContainsString('workflow_key', json_encode($this->get($url)->viewData('page')['props']), $url);
        }
    }

    public function test_a_message_in_ai_mode_runs_the_authorized_workflow_and_the_reply_goes_to_whatsapp(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers('Claro, te ayudo.');

        $this->deliver($this->event('1000001', '¿Horario?'))->assertOk();

        $conversation = $this->conversation($bot);
        $inbound = $conversation->messages()->where('direction', 'in')->firstOrFail();
        $reply = $conversation->messages()->where('direction', 'out')->firstOrFail();
        $this->assertSame('ai', $reply->sender);
        $this->assertSame('Claro, te ayudo.', $reply->body);
        $this->assertSame('sent', $reply->status);
        $this->assertStringStartsWith('wamid.OUT', $reply->external_id);
        $this->assertSame(1, $this->n8nCalls());
        $this->assertSame(1, $this->graphCalls());
        // The message keeps its own state: running an execution never writes to it.
        $this->assertNull($inbound->refresh()->status);
        $this->assertNull($inbound->failure_reason);

        $execution = $this->execution();
        $this->assertSame([$bot->workspace_id, $bot->id, $conversation->id, $inbound->id], [$execution->workspace_id, $execution->chatbot_id, $execution->conversation_id, $execution->message_id]);
        $this->assertSame(['succeeded', 'ok', 'replied', 'accepted', 1], [$execution->status, $execution->outcome_code, $execution->control_result, $execution->delivery, $execution->attempts]);
        $this->assertSame($reply->id, $execution->reply_message_id);
        $this->assertNotNull($execution->started_at);
        $this->assertNotNull($execution->finished_at);

        Http::assertSent(function (Request $request) use ($execution) {
            if (! str_starts_with($request->url(), self::N8N)) {
                return str_contains($request->url(), 'graph.facebook.com') ? $request['biz_opaque_callback_data'] === 'ava:'.$execution->reply_message_id : false;
            }
            $timestamp = $request->header('X-Ava-Timestamp')[0];

            return $request->url() === self::N8N.'/support-v1'
                && $request->header('X-Ava-Execution')[0] === $execution->correlation_id
                && $request->header('X-Ava-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET)
                && $request['message']['text'] === '¿Horario?' && $request['chatbot']['instructions'] === 'Instrucciones de Asistente';
        });
    }

    public function test_the_workflow_receives_no_credentials_or_contact_data_beyond_what_it_needs(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers();

        $this->deliver($this->event('1000001', 'Hola', '573009998877', null, 'Persona Real'))->assertOk();

        $body = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::N8N))->first()[0]->body();
        foreach ([self::TOKEN, self::APP_SECRET, self::SECRET, '573009998877', 'Persona Real', '1000001', 'WS_A'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_the_history_sent_to_the_workflow_is_only_this_conversation(): void
    {
        $workflow = $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $workflow);
        $this->n8nAnswers('Respuesta uno');
        $this->deliver($this->event('1000001', 'Primero', '573001110001'));
        $this->deliver($this->event('1000001', 'Otro contacto', '573001110002'));
        $this->n8nAnswers('Respuesta dos');

        $this->deliver($this->event('1000001', 'Segundo', '573001110001'));

        $last = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::N8N))->last()[0]->data();
        $this->assertSame([['role' => 'user', 'text' => 'Primero'], ['role' => 'assistant', 'text' => 'Respuesta uno']], $last['history']);
    }

    public function test_a_workflow_that_asks_for_a_person_hands_over_through_ava_and_stops_the_ai(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers('Te paso con una persona.', ['handoff' => true]);

        $this->deliver($this->event('1000001', 'Quiero un humano'))->assertOk();

        $conversation = $this->conversation($bot);
        $this->assertSame(Conversation::PENDING, $conversation->handling);
        $this->assertSame('Solicitado por el asistente.', $conversation->handoff_reason);
        $this->assertSame('handoff_applied', $this->execution()->control_result);
        $this->assertSame(1, $conversation->messages()->where('direction', 'out')->count());

        $this->deliver($this->event('1000001', 'Hola?'))->assertOk();
        $this->assertSame(1, $this->n8nCalls());
        $this->assertSame(1, ChatbotExecution::count());
    }

    public function test_a_workflow_handoff_through_the_webhook_is_one_automatic_event_of_that_workspace_without_message_text(): void
    {
        $this->workflow();
        $workspace = $this->workspace('WS_A');
        $unrelated = $this->workspace('WS_B');
        $this->bot($workspace, '1000001', 'support');
        $setup = AuditLog::count();
        $this->n8nAnswers('Te paso con una persona.', ['handoff' => true]);

        // A forged event is refused before anything is written; the real one is audited once.
        $this->deliver($this->event('1000001', 'Quiero un humano SECRETO'), 'forged')->assertForbidden();
        $this->assertSame($setup, AuditLog::count());
        $this->deliver($this->event('1000001', 'Quiero un humano SECRETO'))->assertOk();
        $this->deliver($this->event('1000001', 'Hola?'))->assertOk();

        $events = AuditLog::where('action', 'requested')->get();
        $this->assertCount(1, $events);
        $this->assertSame([$workspace->id, 'system', 'success', null], [$events[0]->workspace_id, $events[0]->actor, $events[0]->outcome, $events[0]->user_id]);
        $this->assertSame(0, AuditLog::where('workspace_id', $unrelated->id)->count());
        $trail = json_encode(AuditLog::all()->toArray());
        foreach (['SECRETO', 'Te paso con', self::TOKEN, self::SECRET, self::APP_SECRET] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $trail);
        }
    }

    public function test_a_resolved_conversation_reopens_with_the_ai_when_the_contact_writes_again(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers();
        $this->deliver($this->event('1000001', 'Hola'));
        $conversation = $this->conversation($bot);
        $agent = User::factory()->create();
        app(ConversationControl::class)->resolve($conversation, $agent, true);

        $this->deliver($this->event('1000001', 'Una duda más'))->assertOk();

        $this->assertSame(Conversation::AI, $conversation->refresh()->handling);
        $this->assertSame(2, $this->n8nCalls());
    }

    // --- 4. human mode ------------------------------------------------------------------------------------------

    public function test_a_message_in_human_mode_is_stored_but_starts_no_automatic_reply(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers();
        $this->deliver($this->event('1000001', 'Hola'));
        $conversation = $this->conversation($bot);
        $agent = User::factory()->create();
        app(ConversationControl::class)->take($conversation, $agent);
        $before = $this->n8nCalls();

        $this->deliver($this->event('1000001', 'Sigo aquí'))->assertOk();

        $this->assertSame($before, $this->n8nCalls());
        $this->assertSame(1, $conversation->messages()->where('body', 'Sigo aquí')->count());
        $this->assertSame(1, $conversation->messages()->where('direction', 'out')->count());
    }

    public function test_a_late_answer_is_dropped_after_a_person_takes_the_conversation(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $agent = User::factory()->create();
        $this->n8nAnswers('Respuesta tardía', [], fn () => app(ConversationControl::class)->take($this->conversation($bot), $agent));

        $this->deliver($this->event('1000001', 'Hola'))->assertOk();

        $conversation = $this->conversation($bot);
        $this->assertSame(Conversation::HUMAN, $conversation->handling);
        $this->assertSame(0, $conversation->messages()->where('direction', 'out')->count());
        $this->assertSame(0, $this->graphCalls());
        $execution = $this->execution();
        $this->assertSame(['discarded', 'late_response', 'late_dropped', null, null], [$execution->status, $execution->outcome_code, $execution->control_result, $execution->delivery, $execution->reply_message_id]);
        $this->assertSame([], $this->inboundStatuses());
    }

    public function test_control_going_back_and_forth_during_an_execution_still_discards_the_stale_answer(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $agent = User::factory()->create();
        $this->n8nAnswers('Respuesta vieja', [], function () use ($bot, $agent) {
            $control = app(ConversationControl::class);
            $control->take($this->conversation($bot), $agent);
            $control->returnToAi($this->conversation($bot), $agent, true);
        });

        $this->deliver($this->event('1000001', 'Hola'))->assertOk();

        $conversation = $this->conversation($bot);
        $this->assertSame(Conversation::AI, $conversation->handling);
        $this->assertSame(0, $conversation->messages()->where('direction', 'out')->count());
        $this->assertSame(0, $this->graphCalls());
        $this->assertSame('late_response', $this->execution()->outcome_code);
    }

    public function test_a_late_answer_is_dropped_after_the_case_is_resolved(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $agent = User::factory()->create();
        $this->n8nAnswers('Tarde', [], fn () => app(ConversationControl::class)->resolve($this->conversation($bot), $agent, true));

        $this->deliver($this->event('1000001', 'Hola'))->assertOk();

        $this->assertSame(0, $this->conversation($bot)->messages()->where('direction', 'out')->count());
        $this->assertSame('late_response', $this->execution()->outcome_code);
    }

    public function test_an_execution_queued_before_a_transfer_does_not_call_the_workflow_at_all(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $job = $this->queued();
        app(ConversationControl::class)->take($this->conversation($bot), User::factory()->create());

        $this->assertSame('superseded', $this->runJob($job)->code);
        $this->assertSame(0, $this->n8nCalls());
        $this->assertSame('superseded', $this->execution()->outcome_code);
    }

    // --- 7. retries and duplicates -----------------------------------------------------------------------------

    public function test_meta_resending_an_event_stores_and_answers_it_once(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers();
        $event = $this->event('1000001', 'Hola', '573001112233', 'wamid.SAME');

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        $conversation = $this->conversation($bot);
        $this->assertSame(1, $conversation->messages()->where('direction', 'in')->count());
        $this->assertSame(1, $conversation->messages()->where('direction', 'out')->count());
        $this->assertSame(1, $this->n8nCalls());
        $this->assertSame(1, $this->graphCalls());
    }

    public function test_a_temporary_failure_is_retried_and_the_reply_is_sent_once(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $failures = [503, 504];
        Http::fake([
            self::N8N.'/*' => function (Request $request) use (&$failures) {
                return $failures ? Http::response('error', array_shift($failures)) : Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Por fin']);
            },
            self::GRAPH => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        ]);
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $job = $this->queued();

        $first = $this->runJob($job);
        $this->assertSame(['retry', 'n8n_unavailable'], [$first->kind, $first->code]);
        $this->assertSame(['pending', 1, 'n8n_unavailable'], [$this->execution()->status, $this->execution()->attempts, $this->execution()->outcome_code]);
        $second = $this->runJob($job);
        $this->assertSame(['retry', 'n8n_unavailable'], [$second->kind, $second->code]);
        $this->assertSame(0, $this->conversation($bot)->messages()->where('direction', 'out')->count());

        $this->assertSame('done', $this->runJob($job)->kind);
        $this->assertSame(['succeeded', 3, 'accepted'], [$this->execution()->status, $this->execution()->attempts, $this->execution()->delivery]);
        $this->assertSame(1, $this->conversation($bot)->messages()->where('direction', 'out')->count());
        $this->assertSame(1, $this->graphCalls());
        $this->assertSame([], $this->inboundStatuses());
    }

    public function test_running_the_same_execution_twice_never_calls_n8n_or_whatsapp_twice(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $job = $this->queued();

        $this->assertSame('done', $this->runJob($job)->kind);
        // Even AFTER Meta's id replaced anything local, the database row still says it ran and was delivered.
        $second = $this->runJob($job);
        $third = $this->runJob($job);

        $this->assertSame(['discarded', 'not_runnable'], [$second->kind, $second->code]);
        $this->assertSame('not_runnable', $third->code);
        $this->assertSame(1, $this->n8nCalls());
        $this->assertSame(1, $this->graphCalls());
        $this->assertSame(1, $this->conversation($bot)->messages()->where('direction', 'out')->count());
    }

    public function test_n8n_errors_never_lose_the_original_message_nor_change_it(): void
    {
        $cases = [
            'unavailable' => [Http::response('boom', 500), 'retries_exhausted'],
            'rejected' => [Http::response('no', 404), 'n8n_rejected'],
            'not json' => [Http::response('<html>', 200), 'invalid_response'],
            'no reply' => [Http::response(['execution_id' => 'x'], 200), 'invalid_response'],
            'huge answer' => [Http::response(str_repeat('a', 70000), 200), 'invalid_response'],
        ];
        $current = null;
        Http::fake([self::N8N.'/*' => function () use (&$current) {
            return $current;
        }]);
        Queue::fake();
        $this->workflow();
        $i = 0;

        foreach ($cases as $name => [$response, $code]) {
            $i++;
            $current = $response;
            $phone = (string) (1000000 + $i);
            [$bot] = $this->bot($this->workspace("WS_{$i}"), $phone, 'support');

            $this->deliver($this->event($phone, "Mensaje {$name}"))->assertOk();
            $job = $this->queued();

            while (($result = $this->runJob($job))->kind === 'retry');

            $conversation = $this->conversation($bot);
            $inbound = $conversation->messages()->where('direction', 'in')->firstOrFail();
            $execution = ChatbotExecution::where('message_id', $inbound->id)->firstOrFail();
            $this->assertSame(['failed', $code], [$result->kind, $result->code], $name);
            $this->assertSame(['failed', $code, null], [$execution->status, $execution->outcome_code, $execution->delivery], $name);
            $this->assertSame("Mensaje {$name}", $inbound->body, $name);
            $this->assertNull($inbound->status, $name);
            $this->assertNull($inbound->failure_reason, $name);
            $this->assertSame(0, $conversation->messages()->where('direction', 'out')->count(), $name);
            $this->assertSame(Conversation::AI, $conversation->handling, $name);
        }
    }

    public function test_an_answer_that_does_not_echo_the_execution_is_not_accepted(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        Http::fake([self::N8N.'/*' => Http::response(['execution_id' => (string) str()->uuid(), 'reply' => 'De otra ejecución', 'workspace_id' => $bot->workspace_id])]);
        Queue::fake();

        $this->deliver($this->event('1000001'))->assertOk();

        $this->assertSame('invalid_response', $this->runJob($this->queued())->code);
        $this->assertSame(0, $this->conversation($bot)->messages()->where('direction', 'out')->count());
    }

    public function test_nothing_runs_without_the_n8n_url_and_secret(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        config(['n8n.secret' => null]);
        $this->n8nAnswers();
        Queue::fake();

        $this->deliver($this->event('1000001'))->assertOk();

        $this->assertSame('not_configured', $this->runJob($this->queued())->code);
        $this->assertSame(0, $this->n8nCalls());
        $this->assertSame(1, $this->conversation($bot)->messages()->count());
    }

    public function test_the_workflow_url_cannot_reach_an_internal_address_or_use_plain_http_in_production(): void
    {
        $this->workflow();
        $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001'))->assertOk();
        $job = $this->queued();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['10.0.0.5']));
        $this->assertSame('unsafe_target', $this->runJob($job)->code);

        $this->execution()->update(['status' => 'pending']);
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['n8n.execution_url' => 'http://n8n.platform.example.com/webhook']);
        $this->app['env'] = 'production';
        $this->assertSame('insecure_target', $this->runJob($job)->code);
        $this->app['env'] = 'testing';

        $this->assertSame(0, $this->n8nCalls());
    }

    public function test_a_reply_that_whatsapp_refuses_for_sure_is_failed_and_never_resent(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        Http::fake([
            self::N8N.'/*' => fn (Request $request) => Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola']),
            self::GRAPH => Http::response(['error' => ['code' => 131047, 'message' => 'token '.self::TOKEN]], 400),
        ]);

        $this->deliver($this->event('1000001'))->assertOk();

        $reply = $this->conversation($bot)->messages()->where('direction', 'out')->firstOrFail();
        $this->assertSame('failed', $reply->status);
        $this->assertStringNotContainsString(self::TOKEN, (string) $reply->failure_reason);
        $this->assertSame(['succeeded', 'failed'], [$this->execution()->status, $this->execution()->delivery]);
        $this->assertSame([], $this->inboundStatuses());
    }

    public function test_a_good_run_writes_no_audit_event(): void
    {
        $this->workflow();
        $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $setup = AuditLog::count(); // the workflow assignment of the scenery is itself an event
        $this->n8nAnswers('Hola');

        $this->deliver($this->event('1000001'))->assertOk();

        $this->assertSame('accepted', $this->execution()->delivery);
        $this->assertSame($setup, AuditLog::count(), 'a normal run is not an administrative event');
    }

    public function test_a_refused_reply_is_one_automatic_failed_event_without_secrets(): void
    {
        $this->workflow();
        $workspace = $this->workspace('WS_A');
        $unrelated = $this->workspace('WS_B');
        $this->bot($workspace, '1000001', 'support');
        $setup = AuditLog::count();

        Http::fake([
            self::N8N.'/*' => fn (Request $request) => Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola']),
            self::GRAPH => Http::response(['error' => ['code' => 131047, 'message' => 'token '.self::TOKEN]], 400),
        ]);
        $this->deliver($this->event('1000001', 'Otra', '573009998877'))->assertOk();

        $logs = AuditLog::where('action', 'executed')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($setup + 1, AuditLog::count());
        $log = $logs->first();
        $this->assertSame(['executed', 'system', 'failed', $workspace->id, null], [$log->action, $log->actor, $log->outcome, $log->workspace_id, $log->user_id]);
        $this->assertSame('Ejecución automática', $log->user_name);
        $this->assertSame(ChatbotExecution::orderByDesc('id')->firstOrFail()->id, $log->resource_id);
        $trail = json_encode(AuditLog::all()->toArray());
        foreach ([self::TOKEN, self::SECRET, self::APP_SECRET, 'Otra', 'Hola'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $trail);
        }
        $this->assertSame(0, AuditLog::where('workspace_id', $unrelated->id)->count());
    }

    // --- 9. no secrets in logs or answers ----------------------------------------------------------------------

    public function test_logs_and_error_answers_carry_no_secrets_or_message_content(): void
    {
        $this->captureLogs();
        $this->workflow();
        $this->bot($this->workspace('WS_A'), '1000001', 'support');
        Http::fake([self::N8N.'/*' => Http::response('internal '.self::SECRET.' '.self::TOKEN, 500)]);

        $response = $this->deliver($this->event('1000001', 'Contenido privado del cliente', '573001112233'));
        $bad = $this->deliver($this->event('1000001'), 'wrong');

        foreach ([$response->getContent(), $bad->getContent()] as $text) {
            foreach ([self::SECRET, self::TOKEN, self::APP_SECRET, 'Contenido privado'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text);
            }
        }

        $this->assertNotEmpty($this->logs);
        foreach ($this->logs as $entry) {
            foreach (['Contenido privado', self::SECRET, self::TOKEN, self::APP_SECRET, '573001112233'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $entry['text']);
            }
        }
    }

    // --- 10 & 11. nobody reaches another Workspace's data ------------------------------------------------------

    public function test_users_cannot_reach_conversations_chatbots_or_controls_of_another_workspace(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', $workflow, 'Bot A');
        [$botB] = $this->bot($b, '2000002', $workflow, 'Bot B');
        $this->n8nAnswers();
        $this->deliver($this->event('2000002', 'Datos de B'));
        $foreign = $this->conversation($botB);

        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, 'admin');
        $a->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);
        $this->withSession(['workspace_id' => $a->id]);

        $this->get("/conversations?c={$foreign->id}")->assertNotFound();
        $this->get("/chatbots/{$botB->id}")->assertNotFound();
        $this->post("/chatbots/{$botB->id}", ['name' => 'Robado'])->assertNotFound();
        $this->post("/conversations/{$foreign->id}/take")->assertNotFound();
        $this->post("/conversations/{$foreign->id}/release")->assertNotFound();
        $this->post("/conversations/{$foreign->id}/resolve")->assertNotFound();
        $this->post("/conversations/{$foreign->id}/messages", ['body' => 'hola'])->assertNotFound();
        $this->post("/conversations/{$foreign->id}/assign", ['user_id' => $user->id])->assertNotFound();

        $this->assertSame(Conversation::AI, $foreign->refresh()->handling);
        $this->assertSame('Bot B', $botB->refresh()->name);
        $inbox = json_encode($this->get('/conversations')->viewData('page')['props']);
        $this->assertStringNotContainsString('Datos de B', $inbox);
        $this->assertStringNotContainsString('wf_support', $inbox);
    }

    public function test_the_agent_api_of_one_chatbot_cannot_change_control_of_another_workspace(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', $workflow);
        [$botB] = $this->bot($b, '2000002', $workflow);
        $this->n8nAnswers();
        $this->deliver($this->event('2000002', 'Hola', '573001112233'));
        $token = app(AgentAccess::class)->generate($botA);

        $this->withToken($token)->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertOk();

        $this->assertSame(Conversation::AI, $this->conversation($botB)->handling);
        $this->assertSame(Conversation::PENDING, $this->conversation($botA)->handling);
    }

    public function test_the_existing_n8n_reporting_path_does_not_start_executions(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers();
        $token = app(AgentAccess::class)->generate($bot);

        $this->withToken($token)->postJson('/api/agent/messages', [
            'channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'Reportado por n8n', 'external_id' => 'wamid.R1',
        ])->assertCreated();

        $this->assertSame(0, $this->n8nCalls());
    }

    // --- delivery states and platform tools ---------------------------------------------------------------------

    public function test_delivery_states_from_meta_update_only_the_matching_message_of_that_chatbot(): void
    {
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', $this->workflow());
        $this->n8nAnswers('Hola');
        $this->deliver($this->event('1000001', 'Hola'));
        $reply = $this->conversation($bot)->messages()->where('direction', 'out')->firstOrFail();

        $this->deliver(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1000001'],
            'statuses' => [['id' => $reply->external_id, 'recipient_id' => '573001112233', 'status' => 'read']],
        ]]]]]])->assertOk();

        $this->assertSame('read', $reply->refresh()->status);
    }

    public function test_the_platform_command_assigns_only_catalog_workflows_and_lists_them(): void
    {
        $this->workflow('soporte', 'soporte/v1');
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001');

        $this->artisan('workflows:manage', ['action' => 'assign', '--key' => 'inexistente', '--chatbot' => $bot->id])->assertFailed();
        $this->artisan('workflows:manage', ['action' => 'assign', '--key' => 'soporte', '--chatbot' => 99999])->assertFailed();
        $this->artisan('workflows:manage', ['action' => 'assign', '--key' => 'soporte', '--chatbot' => $bot->id])->assertSuccessful();
        $this->artisan('workflows:manage', ['action' => 'assign', '--key' => 'soporte', '--chatbot' => $bot->id])->assertSuccessful();

        $this->assertSame('soporte', app(ChatbotWorkflows::class)->for($bot->refresh())->key);
        $this->artisan('workflows:manage', ['action' => 'list'])->assertSuccessful();

        $this->artisan('workflows:manage', ['action' => 'unassign', '--chatbot' => $bot->id])->assertSuccessful();
        $this->assertNull(app(ChatbotWorkflows::class)->for($bot->refresh()));
    }

    public function test_an_inactive_or_demo_chatbot_cannot_be_given_a_workflow(): void
    {
        $workflow = $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001');
        $bot->update(['is_active' => false]);

        $this->artisan('workflows:manage', ['action' => 'assign', '--key' => $workflow, '--chatbot' => $bot->id])->assertFailed();

        $this->assertSame(0, $bot->workspace->integrations()->where('type', 'workflow')->count());
    }

    public function test_an_unexpected_error_fails_the_job_with_a_fixed_code_and_leaks_nothing(): void
    {
        $this->captureLogs();
        $failures = [];
        Event::listen(JobFailed::class, function ($event) use (&$failures) {
            $failures[] = $event->exception->getMessage();
        });
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->app->instance(ChatbotExecutor::class, new class implements ChatbotExecutor
        {
            public function run(ResolvedWorkflow $workflow, string $correlationId, array $payload): ExecutionOutcome
            {
                throw new \RuntimeException('SQLSTATE insert ... values (Contenido privado, '.self::class.')');
            }
        });

        $this->deliver($this->event('1000001', 'Contenido privado'))->assertOk();

        $this->assertSame(['chatbot_execution_failed:internal_error'], $failures);
        $errors = array_filter($this->logs, fn ($entry) => $entry['level'] === 'error');
        $this->assertCount(1, $errors);
        foreach ($this->logs as $entry) {
            $this->assertStringNotContainsString('Contenido privado', $entry['text']);
        }
        $inbound = $this->conversation($bot)->messages()->where('direction', 'in')->firstOrFail();
        $this->assertNull($inbound->status);
        $this->assertSame(0, $this->conversation($bot)->messages()->where('direction', 'out')->count());
        // The execution stays claimable (recovery picks it up): a bug in one run does not lose it.
        $this->assertSame('running', $this->execution()->status);
    }

    public function test_a_failed_execution_does_not_touch_the_inbox_or_the_conversation_state(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        Http::fake([self::N8N.'/*' => Http::response('boom', 500)]);
        $user = User::factory()->create();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();

        $this->actingAsWorkspaceMember($user, 'admin');
        $this->withSession(['workspace_id' => $bot->workspace_id]);
        $bot->workspace->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);
        $page = $this->get('/conversations?c='.$this->conversation($bot)->id)->assertOk()->viewData('page')['props'];

        $this->assertSame('Hola', $page['selected']['items'][0]['body']);
        $this->assertNull($page['selected']['items'][0]['status']);
        $this->assertSame(Conversation::AI, $page['selected']['handling']);
        $this->assertCount(1, $page['selected']['items']);
    }

    public function test_the_queue_gives_a_run_less_time_than_it_waits_before_handing_the_job_to_another_worker(): void
    {
        $timeout = (new RunChatbotExecution(1))->timeout;

        $this->assertGreaterThan(config('n8n.timeout'), $timeout);
        foreach (['database', 'redis'] as $connection) {
            $this->assertLessThan(config("queue.connections.{$connection}.retry_after"), $timeout, $connection);
        }
        $this->assertLessThan(60, $timeout, 'Horizon workers are killed at 60 s');
    }

    public function test_an_uncertain_send_is_recorded_as_such_and_never_repeated(): void
    {
        $names = ['timeout after the request', 'connection reset', 'server error', 'bad gateway', 'ok without an id'];
        Http::fake([
            self::N8N.'/*' => fn (Request $request) => Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola']),
            self::GRAPH => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out after 10001 milliseconds')
                ->pushFailedConnection('cURL error 56: Recv failure: Connection reset by peer')
                ->push('oops', 500)
                ->push('', 502)
                ->push(['messages' => []], 200),
        ]);
        Queue::fake();
        $this->workflow();
        $i = 0;

        foreach ($names as $name) {
            $i++;
            $phone = (string) (1000000 + $i);
            [$bot] = $this->bot($this->workspace("WS_{$i}"), $phone, 'support');

            $this->deliver($this->event($phone, 'Hola'))->assertOk();
            $job = $this->queued();
            $this->runJob($job);

            $execution = ChatbotExecution::where('chatbot_id', $bot->id)->firstOrFail();
            $reply = $this->conversation($bot)->messages()->where('direction', 'out')->firstOrFail();
            $this->assertSame(['succeeded', 'uncertain'], [$execution->status, $execution->delivery], $name);
            $this->assertSame('unconfirmed', $reply->status, $name);
            $this->assertNull($reply->external_id, $name);
            $this->assertStringContainsString('No se reintenta solo', (string) $reply->failure_reason, $name);

            // Neither another run, nor the queue, nor the recovery command may send it again.
            $before = $this->graphCalls();
            $this->runJob($job);
            $execution->update(['updated_at' => now()->subHour()]);
            $this->artisan('executions:recover')->assertSuccessful();
            $this->assertSame($before, $this->graphCalls(), $name);
            $this->assertSame(1, $this->conversation($bot)->messages()->where('direction', 'out')->count(), $name);
        }
    }

    public function test_only_failures_before_the_request_could_be_processed_count_as_refused(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $integration = $bot->workspace->integrations()->where('type', 'whatsapp')->firstOrFail();
        $type = app(WhatsAppType::class);
        $cases = [
            ['cURL error 6: Could not resolve host', 'failed'],
            ['cURL error 7: Failed to connect to graph.facebook.com', 'failed'],
            ['cURL error 60: SSL certificate problem', 'failed'],
            ['cURL error 28: Operation timed out', 'uncertain'],
            ['cURL error 52: Empty reply from server', 'uncertain'],
            ['something unexpected', 'uncertain'],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$message]) {
            $sequence->pushFailedConnection($message);
        }
        $sequence->push(['messages' => [['id' => 'wamid.X']]]);
        Http::fake([self::GRAPH => $sequence]);

        foreach ($cases as [$message, $expected]) {
            $result = $type->send($integration, '573001112233', 'Hola', 'ava:1');
            $this->assertSame($expected, $result->uncertain ? 'uncertain' : ($result->ok ? 'ok' : 'failed'), $message);
            $this->assertStringNotContainsString(self::TOKEN, (string) $result->reason);
        }

        $this->assertTrue($type->send($integration, '573001112233', 'Hola', 'ava:1')->ok);
        Http::assertSent(fn (Request $request) => $request['biz_opaque_callback_data'] === 'ava:1' && $request['to'] === '573001112233');
    }

    public function test_meta_delivery_states_settle_an_uncertain_reply_by_its_tag_and_nothing_else(): void
    {
        $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', 'support');
        [$botB] = $this->bot($b, '2000002', 'support');
        Http::fake([
            self::N8N.'/*' => fn (Request $request) => Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola']),
            self::GRAPH => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out'),
        ]);
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $reply = $this->conversation($botA)->messages()->where('direction', 'out')->firstOrFail();
        $this->assertSame('unconfirmed', $reply->status);

        $state = fn (string $phone, string $recipient, string $tag, string $id, string $status = 'delivered') => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phone],
            'statuses' => [['id' => $id, 'recipient_id' => $recipient, 'status' => $status, 'biz_opaque_callback_data' => $tag]],
        ]]]]]];

        // Not this recipient, another Workspace's number, a malformed tag, a tag of nothing: nothing changes.
        $this->deliver($state('1000001', '573009990000', "ava:{$reply->id}", 'wamid.NOPE1'))->assertOk();
        $this->deliver($state('2000002', '573001112233', "ava:{$reply->id}", 'wamid.NOPE2'))->assertOk();
        $this->deliver($state('1000001', '573001112233', "ava:{$reply->id}x", 'wamid.NOPE3'))->assertOk();
        $this->deliver($state('1000001', '573001112233', 'ava:999999', 'wamid.NOPE4'))->assertOk();
        $this->assertSame(['unconfirmed', null], [$reply->refresh()->status, $reply->external_id]);

        $this->deliver($state('1000001', '573001112233', "ava:{$reply->id}", 'wamid.REAL'))->assertOk();
        $this->assertSame(['delivered', 'wamid.REAL', null], [$reply->refresh()->status, $reply->external_id, $reply->failure_reason]);
        $this->assertSame('accepted', $this->execution()->delivery);

        // A message that already has Meta's id is never rewritten by a tag.
        $this->deliver($state('1000001', '573001112233', "ava:{$reply->id}", 'wamid.OTHER', 'read'))->assertOk();
        $this->assertSame('wamid.REAL', $reply->refresh()->external_id);
        $this->assertSame(0, $this->graphCalls() - 1);
    }

    public function test_a_failed_state_from_meta_settles_an_uncertain_reply_as_failed(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        Http::fake([
            self::N8N.'/*' => fn (Request $request) => Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola']),
            self::GRAPH => Http::response('x', 503),
        ]);
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $reply = $this->conversation($bot)->messages()->where('direction', 'out')->firstOrFail();

        $this->deliver(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '1000001'],
            'statuses' => [['id' => 'wamid.F', 'recipient_id' => '573001112233', 'status' => 'failed', 'biz_opaque_callback_data' => "ava:{$reply->id}"]],
        ]]]]]])->assertOk();

        $this->assertSame(['failed', 'wamid.F'], [$reply->refresh()->status, $reply->external_id]);
        $this->assertSame('failed', $this->execution()->delivery);
    }

    public function test_the_database_refuses_an_execution_that_crosses_workspaces_chatbots_or_conversations(): void
    {
        $workflow = $this->workflow();
        [$a, $b] = [$this->workspace('WS_A'), $this->workspace('WS_B')];
        [$botA] = $this->bot($a, '1000001', $workflow);
        [$botB] = $this->bot($b, '2000002', $workflow);
        $this->n8nAnswers();
        $this->deliver($this->event('1000001', 'De A', '573001110001'));
        $this->deliver($this->event('1000001', 'Otro de A', '573001110002'));
        $this->deliver($this->event('2000002', 'De B', '573001110003'));
        $conversationA = $this->conversation($botA, '573001110001');
        $otherA = $this->conversation($botA, '573001110002');
        $conversationB = $this->conversation($botB, '573001110003');
        $messageA = $conversationA->messages()->where('direction', 'in')->firstOrFail();
        $messageB = $conversationB->messages()->where('direction', 'in')->firstOrFail();
        $base = ['correlation_id' => (string) str()->uuid(), 'workflow_key' => 'support', 'n8n_workflow_id' => 'wf_support', 'handling_version' => 0, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
        $table = fn () => DB::table('chatbot_executions');
        $insert = fn (array $row) => $table()->insert($base + ['correlation_id' => (string) str()->uuid()] + $row);
        $free = $otherA->messages()->create(['workspace_id' => $a->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Sin ejecución', 'external_id' => 'wamid.FREE', 'sent_at' => now()]);
        $good = ['workspace_id' => $a->id, 'chatbot_id' => $botA->id, 'conversation_id' => $otherA->id, 'message_id' => $free->id];
        $before = $table()->count();

        $cases = [
            'a message of another Workspace' => ['message_id' => $messageB->id],
            'a message of another conversation of the same chatbot' => ['message_id' => $messageA->id],
            'a conversation of another Workspace' => ['conversation_id' => $conversationB->id, 'message_id' => $messageB->id],
            'a chatbot that does not own the conversation' => ['chatbot_id' => $botB->id],
            'a Workspace that does not own the chatbot' => ['workspace_id' => $b->id],
            'a Workspace that does not exist' => ['workspace_id' => 9999],
        ];

        foreach ($cases as $name => $override) {
            try {
                $insert($override + $good);
                $this->fail("The database accepted {$name}.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame($before, $table()->count());
        $insert($good); // the consistent one is accepted
        // One execution per message, whatever the path.
        $this->expectException(QueryException::class);
        $insert($good);
    }

    public function test_a_reply_belongs_to_one_execution_and_to_the_same_conversation(): void
    {
        $this->workflow();
        [$a] = [$this->workspace('WS_A')];
        [$bot] = $this->bot($a, '1000001', 'support');
        $this->n8nAnswers();
        $this->deliver($this->event('1000001', 'Uno'));
        $this->deliver($this->event('1000001', 'Dos'));
        [$first, $second] = ChatbotExecution::orderBy('id')->get()->all();
        $this->assertNotNull($first->reply_message_id);

        $this->expectException(QueryException::class);
        // Same conversation, so the foreign key is satisfied: it is the unique key that stops one reply serving two executions.
        $second->forceFill(['reply_message_id' => $first->reply_message_id])->save();
    }

    public function test_a_repeated_event_creates_one_execution_even_if_scheduled_twice(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola', '573001112233', 'wamid.SAME'))->assertOk();
        $inbound = $this->conversation($bot)->messages()->where('direction', 'in')->firstOrFail();

        $again = app(ChatbotExecutions::class)->schedule($this->conversation($bot)->load('chatbot'), $inbound);

        $this->assertNull($again);
        $this->assertSame(1, ChatbotExecution::count());
        Queue::assertPushed(RunChatbotExecution::class, 1);
    }

    public function test_recovery_requeues_what_a_crash_left_half_done_without_repeating_a_send(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $execution = $this->execution();
        $old = now()->subHour();

        // Pending for too long (the queue lost the job): queued again, then it runs normally once.
        $execution->forceFill(['updated_at' => $old])->save();
        $this->artisan('executions:recover')->assertSuccessful();
        Queue::assertPushed(RunChatbotExecution::class, 2);
        $this->assertSame('done', $this->runJob($this->queued())->kind);
        $this->assertSame(1, $this->graphCalls());

        // A worker died while "running": reclaimed after the timeout, never before.
        $running = $this->newExecutionFor($bot, 'Otro', '573001110002');
        $running->forceFill(['status' => 'running', 'attempts' => 1, 'updated_at' => now()])->save();
        $this->assertSame('not_runnable', $this->runJob(new RunChatbotExecution($running->id))->code);
        $running->forceFill(['updated_at' => $old])->save();
        $this->assertSame('done', $this->runJob(new RunChatbotExecution($running->id))->kind);
        $this->assertSame(2, $running->refresh()->attempts);

        // A run that keeps dying is failed, not queued forever.
        $dying = $this->newExecutionFor($bot, 'Tercero', '573001110003');
        $dying->forceFill(['status' => 'running', 'attempts' => 6, 'updated_at' => $old])->save();
        $this->artisan('executions:recover')->assertSuccessful();
        $this->assertSame(['failed', 'crashed'], [$dying->refresh()->status, $dying->outcome_code]);
    }

    public function test_a_reply_stored_but_never_handed_over_is_resumed_once_and_a_lost_send_becomes_uncertain(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $execution = $this->execution();
        $conversation = $this->conversation($bot);
        $reply = $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'direction' => 'out', 'sender' => 'ai', 'type' => 'text', 'body' => 'Guardada', 'status' => 'pending', 'sent_at' => now()]);
        $execution->forceFill(['status' => 'succeeded', 'reply_message_id' => $reply->id, 'delivery' => 'pending', 'updated_at' => now()->subHour()])->save();

        // The crash happened before the hand-over: resuming sends it exactly once.
        $this->artisan('executions:recover')->assertSuccessful();
        $this->assertSame('done', $this->runJob($this->queued())->kind);
        $this->assertSame(1, $this->graphCalls());
        $this->assertSame(['sent', 'accepted'], [$reply->refresh()->status, $execution->refresh()->delivery]);
        $this->assertSame('not_runnable', $this->runJob($this->queued())->code);
        $this->assertSame(1, $this->graphCalls());

        // The crash happened after "sending" was recorded: nobody knows, so it is uncertain and not sent again.
        $other = $this->newExecutionFor($bot, 'Otro', '573001110002');
        $second = $other->conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'direction' => 'out', 'sender' => 'ai', 'type' => 'text', 'body' => 'En vuelo', 'status' => 'pending', 'sent_at' => now()]);
        $other->forceFill(['status' => 'succeeded', 'reply_message_id' => $second->id, 'delivery' => 'sending', 'updated_at' => now()->subHour()])->save();

        $this->artisan('executions:recover')->assertSuccessful();

        $this->assertSame('uncertain', $other->refresh()->delivery);
        $this->assertSame('unconfirmed', $second->refresh()->status);
        $this->assertSame(1, $this->graphCalls());
    }

    public function test_a_stored_reply_is_dropped_not_sent_if_a_person_took_over_before_it_was_handed_over(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $this->n8nAnswers();
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $execution = $this->execution();
        $conversation = $this->conversation($bot);
        $reply = $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'direction' => 'out', 'sender' => 'ai', 'type' => 'text', 'body' => 'Tarde', 'status' => 'pending', 'sent_at' => now()]);
        $execution->forceFill(['status' => 'succeeded', 'reply_message_id' => $reply->id, 'delivery' => 'pending'])->save();
        app(ConversationControl::class)->take($conversation, User::factory()->create());

        $this->assertSame('done', $this->runJob($this->queued())->kind);

        $execution->refresh();
        $this->assertSame(['discarded', 'superseded_before_send', 'superseded', null, null], [$execution->status, $execution->outcome_code, $execution->control_result, $execution->delivery, $execution->reply_message_id]);
        $this->assertNull(Message::find($reply->id));
        $this->assertSame(0, $this->graphCalls());
    }

    public function test_a_failed_execution_can_be_retried_only_if_it_stored_no_reply(): void
    {
        $this->workflow();
        [$bot] = $this->bot($this->workspace('WS_A'), '1000001', 'support');
        $answer = false;
        Http::fake([
            self::N8N.'/*' => function (Request $request) use (&$answer) {
                return $answer ? Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Ahora sí']) : Http::response('no', 404);
            },
            self::GRAPH => Http::response(['messages' => [['id' => 'wamid.R1']]]),
        ]);
        Queue::fake();
        $this->deliver($this->event('1000001', 'Hola'))->assertOk();
        $this->runJob($this->queued());
        $execution = $this->execution();
        $this->assertSame(['failed', 'n8n_rejected'], [$execution->status, $execution->outcome_code]);

        $answer = true;
        $this->artisan('executions:recover', ['--retry' => $execution->id])->assertSuccessful();
        $this->assertSame('done', $this->runJob($this->queued())->kind);
        $this->assertSame(['succeeded', 'accepted'], [$execution->refresh()->status, $execution->delivery]);

        // One that has a reply (or is not failed) cannot be run again: that could answer twice.
        $this->artisan('executions:recover', ['--retry' => $execution->id])->assertFailed();
        $this->artisan('executions:recover', ['--retry' => 99999])->assertFailed();
        $this->assertSame(1, $this->graphCalls());
    }
}
