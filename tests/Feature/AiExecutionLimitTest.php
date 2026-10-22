<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\OperationalAlert;
use App\Services\WorkspaceLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The AI execution limit: one execution = one run of the assistant's workflow for one inbound message (`chatbot_executions`). When a Workspace has used
 * its effective limit of the calendar month, NEW executions do not start: the message stays stored and the conversation goes to a person, an alert and an
 * audit event say why, history and the panel keep working, and the next month (or a higher limit) lets it run again. Meta and n8n are never contacted here.
 */
class AiExecutionLimitTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private Workspace $acme;

    private Workspace $beta;

    private Chatbot $acmeBot;

    private Chatbot $betaBot;

    /** @var list<array{0: string, 1: array}> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.secret' => 'n8n-shared-SECRET-123', 'services.meta.app_secret' => 'meta-app-SECRET-456', 'services.meta.webhook_verify_token' => 'verify-me']);
        Http::preventStrayRequests();
        Queue::fake();

        config(['n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1']]);
        [$this->acme, $this->acmeBot] = $this->tenant('ACME', '1000001');
        [$this->beta, $this->betaBot] = $this->tenant('BETA', '1000002');

        $this->mock(OperationalAlert::class)->shouldReceive('raise')->andReturnUsing(function (string $event, array $context = []) {
            $this->alerts[] = [$event, $context];
        });
    }

    /** @return array{0: Workspace, 1: Chatbot} */
    private function tenant(string $code, string $phone): array
    {
        $workspace = Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
        $bot = $workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Instrucciones']);
        $integration = $workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => $phone], 'secrets' => ['access_token' => 'EAAB-token']]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'support');

        return [$workspace, $bot];
    }

    /** An inbound WhatsApp message delivered to the signed webhook, as Meta does. */
    private function inbound(string $phone, string $from = '573001112233', ?string $id = null): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $phone],
            'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $id ?? 'wamid.IN'.++$this->sequence, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]]]]]];
        $raw = json_encode($payload);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-SECRET-456')], $raw)->assertOk();
    }

    private function executions(Workspace $workspace): int
    {
        return ChatbotExecution::where('workspace_id', $workspace->id)->count();
    }

    private function limit(Workspace $workspace, ?int $value, string $reason = 'Acuerdo comercial'): void
    {
        app(WorkspaceLimits::class)->setOverride($workspace, 'ai_executions', $value, $reason, null);
    }

    private function alertCount(string $event): int
    {
        return count(array_filter($this->alerts, fn ($a) => $a[0] === $event));
    }

    // --- the limit --------------------------------------------------------------------------------------------------

    public function test_under_and_exactly_at_the_limit_runs_and_the_next_one_is_blocked_without_losing_the_message(): void
    {
        $this->limit($this->acme, 2);

        $this->inbound('1000001', '573001110001');
        $this->inbound('1000001', '573001110002'); // the 2nd: exactly the limit, still allowed
        $this->assertSame(2, $this->executions($this->acme));

        $this->inbound('1000001', '573001110003'); // the 3rd: blocked
        $this->assertSame(2, $this->executions($this->acme));
        Queue::assertPushed(RunChatbotExecution::class, 2);

        // The message is stored and the conversation goes to a person: nobody is left unanswered in silence.
        $conversation = Conversation::where('workspace_id', $this->acme->id)->where('contact_id', '573001110003')->firstOrFail();
        $this->assertSame(1, $conversation->messages()->where('direction', 'in')->count());
        $this->assertSame(Conversation::PENDING, $conversation->handling);
    }

    public function test_the_block_is_reported_once_and_audited_without_message_text(): void
    {
        $this->limit($this->acme, 1);
        $this->inbound('1000001', '573001110001');
        $this->inbound('1000001', '573001110002');
        $this->inbound('1000001', '573001110003');

        $this->assertSame(1, $this->alertCount('ai_limit_reached'), 'one alert per Workspace per hour, not one per message');
        $alert = collect($this->alerts)->firstWhere(0, 'ai_limit_reached')[1];
        $this->assertSame(['workspace', 'limit', 'renews'], array_keys($alert));
        $this->assertSame(1, AuditLog::where('workspace_id', $this->acme->id)->where('resource_type', 'ai_execution')->where('outcome', 'failed')->count());
        $this->assertStringNotContainsString('Hola', json_encode(AuditLog::where('resource_type', 'ai_execution')->get()->toArray()));
    }

    public function test_a_duplicate_event_neither_counts_twice_nor_hands_over_again(): void
    {
        $this->limit($this->acme, 1);
        $this->inbound('1000001', '573001110001', 'wamid.SAME');
        $this->inbound('1000001', '573001110001', 'wamid.SAME'); // Meta redelivers

        $this->assertSame(1, $this->executions($this->acme));
        $this->assertSame(0, $this->alertCount('ai_limit_reached'));
    }

    public function test_without_a_limit_nothing_is_blocked_and_a_discarded_execution_is_not_consumption(): void
    {
        foreach (range(1, 4) as $i) {
            $this->inbound('1000001', "57300111000{$i}");
        }
        $this->assertSame(4, $this->executions($this->acme));

        ChatbotExecution::where('workspace_id', $this->acme->id)->limit(2)->update(['status' => ChatbotExecution::DISCARDED]);
        $this->assertSame(2, app(WorkspaceLimits::class)->aiExecutionsInPeriod($this->acme));
    }

    // --- inheritance, exceptions and the period -------------------------------------------------------------------------

    public function test_the_limit_is_inherited_from_the_plan_and_a_particular_one_wins_and_can_be_reset(): void
    {
        $plan = Plan::where('key', config('modules.default_plan'))->firstOrFail();
        PlanLimit::create(['plan_id' => $plan->id, 'metric' => 'ai_executions', 'value' => 1]);

        $this->inbound('1000001', '573001110001');
        $this->inbound('1000001', '573001110002');
        $this->assertSame(1, $this->executions($this->acme), 'inherited from the plan');

        $this->limit($this->acme, 5, 'Piloto ampliado'); // an authorized adjustment
        $this->inbound('1000001', '573001110003');
        $this->assertSame(2, $this->executions($this->acme));

        $this->limit($this->acme, null, 'Sin tope un mes'); // explicitly unlimited
        $this->inbound('1000001', '573001110004');
        $this->assertSame(3, $this->executions($this->acme));

        app(WorkspaceLimits::class)->clearOverride($this->acme, 'ai_executions'); // back to the plan: 3 already used >= 1
        $this->inbound('1000001', '573001110005');
        $this->assertSame(3, $this->executions($this->acme));
    }

    public function test_a_frozen_subscription_keeps_its_limit_when_the_plan_is_edited(): void
    {
        $plan = Plan::where('key', config('modules.default_plan'))->firstOrFail();
        $this->acme->update(['plan_id' => $plan->id]);
        PlanLimit::create(['plan_id' => $plan->id, 'metric' => 'ai_executions', 'value' => 100]);
        Subscription::create(['workspace_id' => $this->acme->id, 'plan_id' => $plan->id, 'status' => Subscription::ACTIVE, 'payment_status' => 'confirmed', 'payment_reference' => 'TX-F']);
        Subscription::where('workspace_id', $this->acme->id)->update([
            'terms' => json_encode(['version' => 1, 'source' => 'signup', 'plan' => ['id' => $plan->id, 'name' => $plan->name], 'solution' => null, 'price' => null, 'modules' => ['assistants', 'conversations'], 'limits' => ['users' => null, 'ai_executions' => 1]]),
            'terms_source' => 'signup', 'terms_captured_at' => now(),
        ]);

        $effective = app(WorkspaceLimits::class)->effective($this->acme->fresh(), 'ai_executions');
        $this->assertSame([1, 'contract', 100], [$effective['value'], $effective['source'], $effective['planNow']], 'the plan says 100 today; the contract says 1');

        $this->inbound('1000001', '573001110001');
        $this->inbound('1000001', '573001110002');
        $this->assertSame(1, $this->executions($this->acme));
    }

    public function test_the_next_calendar_month_starts_a_new_period_and_history_is_kept(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        $this->limit($this->acme, 1);
        $this->inbound('1000001', '573001110001');
        $this->inbound('1000001', '573001110002');
        $this->assertSame(1, $this->executions($this->acme));

        $this->travelTo(now()->startOfMonth()->addMonth()->addHours(2));
        Cache::flush();
        $this->assertSame(0, app(WorkspaceLimits::class)->aiExecutionsInPeriod($this->acme), 'the counter restarts logically');
        $this->inbound('1000001', '573001110003');

        $this->assertSame(2, $this->executions($this->acme), 'last month stays in the table: nothing is deleted');
        $this->assertSame(1, app(WorkspaceLimits::class)->aiExecutionsInPeriod($this->acme));
        $this->travelBack();
    }

    public function test_another_workspace_is_never_affected(): void
    {
        $this->limit($this->acme, 1);
        $this->inbound('1000001', '573001110001');
        $this->inbound('1000001', '573001110002');

        foreach (range(1, 3) as $i) {
            $this->inbound('1000002', "57300222000{$i}");
        }

        $this->assertSame([1, 3], [$this->executions($this->acme), $this->executions($this->beta)]);
        $this->assertSame(Conversation::AI, Conversation::where('workspace_id', $this->beta->id)->first()->handling);
    }

    public function test_an_expired_trial_starts_no_ai_execution_and_a_queued_one_does_not_run(): void
    {
        $plan = Plan::where('key', config('modules.default_plan'))->firstOrFail();
        $this->acme->update(['plan_id' => $plan->id]);
        Subscription::create(['workspace_id' => $this->acme->id, 'plan_id' => $plan->id, 'status' => Subscription::TRIALING, 'payment_status' => 'pending']);
        Subscription::where('workspace_id', $this->acme->id)->update(['trial_starts_at' => now(), 'trial_ends_at' => now()->addDay(), 'trial_reason' => 'Prueba de ventas']);

        $this->inbound('1000001', '573001110001'); // during the trial: runs and is queued
        $queued = ChatbotExecution::where('workspace_id', $this->acme->id)->firstOrFail();
        $this->assertSame(ChatbotExecution::PENDING, $queued->status);

        $this->travel(3)->days(); // the trial ends before the queued execution runs
        $this->inbound('1000001', '573001110002'); // a new message: nothing starts
        $this->assertSame(1, $this->executions($this->acme));

        $result = app(ChatbotExecutions::class)->run($queued->id);
        $this->assertFalse($result->ran ?? false);
        $this->assertSame(ChatbotExecution::DISCARDED, $queued->fresh()->status);
        $this->assertSame('chatbot_off', $queued->fresh()->outcome_code, 'a queued run is checked again at run time and refused');
        Queue::assertPushed(RunChatbotExecution::class, 1);
        $this->travelBack();
    }

    public function test_the_internal_workspace_is_never_limited(): void
    {
        $dev = Workspace::create(['organization_id' => $this->acme->organization_id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $this->assertNull(app(WorkspaceLimits::class)->aiLimitReached($dev));
        $this->assertSame('administrative', app(WorkspaceLimits::class)->effective($dev, 'ai_executions')['source']);
    }
}
