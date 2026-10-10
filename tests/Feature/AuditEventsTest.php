<?php

namespace Tests\Feature;

use App\Audit\AuditLogger;
use App\Integrations\SafeHttpTarget;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ChatbotWorkflows;
use App\Services\ConversationControl;
use App\Services\DemoConversations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The events Ava writes by itself and the outcome of what a person tries: who (a person or an automatic process), in
 * which Workspace, with which result, never twice, never with secrets, and queryable by origin and result. Every test
 * uses two Workspaces; the rows are created by the test.
 */
class AuditEventsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_live_SUPER-SECRET-VALUE-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(string $code = 'OTHER_WS'): Workspace
    {
        return Workspace::withoutEvents(fn () => Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Otro {$code}"]));
    }

    private function integrationPayload(): array
    {
        return ['name' => 'API', 'provider' => 'P', 'type' => 'http', 'is_active' => true, 'base_url' => 'https://api.example.com', 'endpoint' => '/status', 'method' => 'GET', 'timeout' => 10, 'auth_type' => 'bearer', 'auth_secret' => self::SECRET, 'headers' => [], 'query' => [], 'body' => ''];
    }

    /** @return list<string> the summary lines of a Workspace's events: "action/actor/outcome" */
    private function trail(Workspace $workspace, ?string $resource = null): array
    {
        return AuditLog::where('workspace_id', $workspace->id)->when($resource, fn ($q) => $q->where('resource_type', $resource))->orderBy('id')->get()
            ->map(fn (AuditLog $log) => "{$log->action}/{$log->actor}/{$log->outcome}")->all();
    }

    // --- the outcome of what a person tries --------------------------------------------------------------------------

    public function test_a_connection_test_records_whether_it_worked_and_never_a_failure_as_success(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->post('/integrations', $this->integrationPayload())->assertSessionHasNoErrors();
        $integration = Integration::firstOrFail();

        Http::fake(['*' => Http::sequence()->push(['ok' => true], 200)->push(['error' => 'x'], 500)]);
        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('success');
        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');

        $this->assertSame(['created/user/success', 'tested/user/success', 'tested/user/failed'], $this->trail($workspace, 'integration'));
        $failed = AuditLog::where('outcome', 'failed')->firstOrFail();
        $this->assertSame($integration->id, $failed->resource_id);
        $this->assertContains(['field' => 'Resultado', 'before' => null, 'after' => 'La prueba no pasó'], $failed->changes);
        $this->assertStringNotContainsString(self::SECRET, json_encode(AuditLog::all()->toArray()));
    }

    public function test_a_test_that_never_ran_leaves_no_event(): void
    {
        $this->actAs();
        $this->post('/integrations', array_merge($this->integrationPayload(), ['is_active' => false]))->assertSessionHasNoErrors();
        $integration = Integration::firstOrFail();

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');

        $this->assertSame(['created/user/success'], $this->trail(Workspace::first(), 'integration'));
    }

    public function test_the_test_of_another_workspaces_integration_is_refused_and_records_nothing_there(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $foreign = $other->integrations()->create(['name' => 'Ajena', 'type' => 'http', 'config' => ['base_url' => 'https://x.test', 'endpoint' => '', 'method' => 'GET', 'timeout' => 5, 'auth' => ['type' => 'none'], 'headers' => [], 'query' => [], 'body' => null]]);

        $this->post("/integrations/{$foreign->id}/test")->assertNotFound();

        $this->assertSame([], $this->trail($other));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_generating_and_revoking_the_assistant_token_are_audited_once_each_without_the_token(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $other = $this->otherWorkspace();
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);

        $this->post("/chatbots/{$bot->id}/agent-token")->assertSessionHas('agent_token');
        $token = (string) session('agent_token');
        $this->delete("/chatbots/{$bot->id}/agent-token")->assertSessionHas('success');

        $this->assertSame(['updated/user/success', 'updated/user/success'], $this->trail($workspace, 'chatbot'));
        $this->assertStringNotContainsString($token, json_encode(AuditLog::all()->toArray()));
        $this->assertSame([], $this->trail($other));
    }

    // --- automatic events -------------------------------------------------------------------------------------------

    public function test_the_assistant_asking_for_a_person_is_an_automatic_event_of_that_workspace_and_is_written_once(): void
    {
        $workspace = $this->otherWorkspace('WS_A');
        $unrelated = $this->otherWorkspace('WS_B');
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);
        $integration = $workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN']]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        $token = app(AgentAccess::class)->generate($bot);
        $this->withToken($token)->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola'])->assertCreated();

        $this->withToken($token)->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'reason' => 'Reclamo'])->assertOk();
        $this->withToken($token)->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertOk();

        $this->assertSame(['requested/system/success'], $this->trail($workspace));
        $log = AuditLog::firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame('Asistente (automático)', $log->user_name);
        $this->assertNull($log->ip_address);
        $this->assertSame($workspace->id, $log->workspace_id);
        $this->assertSame([], $this->trail($unrelated));
        // The reason the contact gave is conversation content, not an audit fact.
        $this->assertStringNotContainsString('Reclamo', json_encode($log->toArray()));
        $this->assertStringNotContainsString('EAAB-SECRET-TOKEN', json_encode($log->toArray()));
    }

    public function test_the_agent_api_derives_the_workspace_from_the_token_and_ignores_what_the_caller_sends(): void
    {
        $a = $this->otherWorkspace('WS_A');
        $b = $this->otherWorkspace('WS_B');
        $botA = $a->chatbots()->create(['name' => 'Bot A']);
        $botB = $b->chatbots()->create(['name' => 'Bot B']);
        $integration = $a->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN']]);
        $botA->channels()->create(['workspace_id' => $a->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        $token = app(AgentAccess::class)->generate($botA);
        $victim = $botB->conversations()->create(['workspace_id' => $b->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Ajeno']);

        // The caller claims another Workspace, another chatbot and another conversation: none of it is read.
        $this->withToken($token)->postJson('/api/agent/conversations/handoff', [
            'channel' => 'whatsapp', 'contact_id' => '573001112233', 'workspace_id' => $b->id, 'chatbot_id' => $botB->id, 'conversation_id' => $victim->id,
        ])->assertOk();

        $this->assertSame('ai', $victim->fresh()->handling, 'the other Workspace conversation is untouched');
        $this->assertSame(['requested/system/success'], $this->trail($a));
        $this->assertSame([], $this->trail($b));
        $this->assertSame($a->id, Conversation::where('handling', 'pending')->firstOrFail()->workspace_id);
    }

    public function test_requests_without_a_valid_token_change_nothing_and_leave_no_event(): void
    {
        $a = $this->otherWorkspace('WS_A');
        $a->chatbots()->create(['name' => 'Bot A']);

        $this->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertUnauthorized();
        $this->withToken('not-a-real-token')->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertUnauthorized();

        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_a_resolved_conversation_reopened_by_the_contact_is_an_automatic_event(): void
    {
        $workspace = $this->otherWorkspace('WS_A');
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'contact_id' => 'v1', 'contact_name' => 'Visitante', 'last_message_at' => now()]);
        $conversation->forceFill(['handling' => 'resolved'])->save();

        app(ConversationControl::class)->reopen($conversation->refresh());

        $this->assertSame('ai', $conversation->fresh()->handling);
        $this->assertSame(['reopened/system/success'], $this->trail($workspace));
        $this->assertSame('Contacto (mensaje nuevo)', AuditLog::firstOrFail()->user_name);
    }

    public function test_the_same_operation_is_a_persons_event_when_a_person_does_it(): void
    {
        $user = $this->actAs();
        $workspace = Workspace::first();
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'contact_id' => 'v1', 'contact_name' => 'Visitante', 'last_message_at' => now()]);
        $conversation->forceFill(['handling' => 'resolved'])->save();
        // The request the person made (the logger attributes by the signed-in user of a workspace request).
        $this->get('/dashboard');

        app(AuditLogger::class)->recordForActor('reopened', 'conversation', $conversation->id, 'Visitante', [], $workspace, 'Contacto (mensaje nuevo)');

        $log = AuditLog::firstOrFail();
        $this->assertSame(['user', $user->id], [$log->actor, $log->user_id]);
    }

    public function test_the_platform_console_assigning_a_workflow_is_audited_with_before_and_after(): void
    {
        $workspace = $this->otherWorkspace('WS_A');
        $unrelated = $this->otherWorkspace('WS_B');
        config(['n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1'], 'n8n.workflows.sales' => ['name' => 'Ventas', 'n8n_workflow_id' => 'wf_sales', 'webhook_path' => 'sales-v1', 'version' => '1']]);
        $bot = $workspace->chatbots()->create(['name' => 'Asistente', 'is_active' => true]);
        $workflows = app(ChatbotWorkflows::class);

        $workflows->assign($bot, 'support');
        $workflows->assign($bot->refresh(), 'support'); // no change: no event
        $workflows->assign($bot->refresh(), 'sales');
        $workflows->unassign($bot->refresh());

        $this->assertSame(['updated/system/success', 'updated/system/success', 'updated/system/success'], $this->trail($workspace, 'chatbot'));
        $this->assertSame([], $this->trail($unrelated));
        $changes = AuditLog::orderBy('id')->get()->map(fn (AuditLog $log) => [$log->changes[0]['before'], $log->changes[0]['after']])->all();
        $this->assertSame([[null, 'support'], ['support', 'sales'], ['sales', null]], $changes);
        $this->assertSame('Consola de la plataforma', AuditLog::firstOrFail()->user_name);
    }

    public function test_the_demo_environment_writes_one_summary_event_and_nothing_when_nothing_changes(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $dev = $this->otherWorkspace('DESARROLLO_DEV');
        $other = $this->otherWorkspace('WS_B');
        $demo = app(DemoConversations::class);

        $demo->generate($dev);
        $demo->generate($dev); // already there: nothing created, nothing audited
        $demo->clean($dev);
        $demo->clean($dev); // nothing to clean

        $this->assertSame(['created/system/success', 'deleted/system/success'], $this->trail($dev));
        $this->assertSame([], $this->trail($other));
        $this->assertContains(['field' => 'Conversaciones demo', 'before' => null, 'after' => '11'], AuditLog::where('action', 'created')->firstOrFail()->changes);
        $this->assertStringNotContainsString('Hola', json_encode(AuditLog::all()->toArray()));
    }

    // --- compatibility and consultation ----------------------------------------------------------------------------

    public function test_existing_rows_without_actor_or_outcome_mean_a_person_and_success(): void
    {
        $workspace = $this->otherWorkspace('WS_A');
        $row = AuditLog::create([
            'workspace_id' => $workspace->id, 'workspace_code' => $workspace->code, 'workspace_name' => $workspace->name,
            'user_id' => null, 'user_name' => 'Persona', 'user_email' => 'p@example.com', 'action' => 'updated', 'resource_type' => 'user', 'resource_id' => 1,
            'resource_label' => 'X', 'description' => 'Modificó el usuario X', 'changes' => [], 'ip_address' => null,
        ])->fresh();

        $this->assertSame(['user', 'success'], [$row->actor, $row->outcome]);
    }

    public function test_the_audit_filters_by_origin_and_result_and_the_summary_counts_them(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $other = $this->otherWorkspace();
        $logger = app(AuditLogger::class);
        $logger->system('requested', 'conversation', 1, 'Uno', [], $workspace, 'Asistente (automático)');
        $logger->system('executed', 'chatbot_execution', 2, 'Dos', [], $workspace, 'Ejecución automática', AuditLogger::FAILED);
        $logger->system('requested', 'conversation', 3, 'Foráneo', [], $other, 'Asistente (automático)', AuditLogger::FAILED);
        $this->post('/integrations', $this->integrationPayload())->assertSessionHasNoErrors();

        $names = fn (string $query) => collect($this->get("/audit?{$query}")->assertOk()->viewData('page')['props']['events']['data'])->pluck('record')->sort()->values()->all();

        $this->assertSame(['API', 'Dos', 'Uno'], $names(''));
        $this->assertSame(['Dos', 'Uno'], $names('actor=system'));
        $this->assertSame(['API'], $names('actor=user'));
        $this->assertSame(['Dos'], $names('outcome=failed'));
        $this->assertSame(['API', 'Uno'], $names('outcome=success'));
        $this->assertSame(['Dos'], $names('actor=system&outcome=failed'));
        $this->get('/audit')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total', 3)->where('summary.failed', 1)->where('summary.automatic', 2)
            ->where('events.data.0.actor', 'user')->where('events.data.0.outcome', 'success')->where('events.data.1.outcome', 'failed')->where('events.data.2.actor', 'system')
            ->where('options.actors', fn ($actors) => collect($actors)->pluck('value')->all() === ['user', 'system']));
        // Foreign events never appear, whatever the filter.
        $this->assertStringNotContainsString('Foráneo', json_encode($this->get('/audit?outcome=failed')->viewData('page')['props']));
        $this->get('/audit?actor=robot')->assertSessionHasErrors('actor');
        $this->get('/audit?outcome=maybe')->assertSessionHasErrors('outcome');
    }

    public function test_the_pdf_names_the_origin_and_result_filters(): void
    {
        $this->actAs();
        app(AuditLogger::class)->system('executed', 'chatbot_execution', 2, 'Dos', [AuditLogger::change('Resultado', null, 'Falló')], Workspace::first(), 'Ejecución automática', AuditLogger::FAILED);

        $response = $this->get('/audit/export?actor=system&outcome=failed');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/audit/export?actor=nobody')->assertSessionHasErrors('actor');
    }

    public function test_only_who_manages_settings_reads_the_automatic_events(): void
    {
        $this->actAs('cliente');
        app(AuditLogger::class)->system('requested', 'conversation', 1, 'Uno', [], Workspace::first(), 'Asistente (automático)');

        $this->get('/audit?actor=system')->assertForbidden();
        $this->get('/audit/export?actor=system')->assertForbidden();
    }

    public function test_a_superuser_filters_automatic_events_by_workspace_only_from_the_administrative_one(): void
    {
        $this->actAs('admin', superuser: true);
        $a = Workspace::first();
        $b = $this->otherWorkspace();
        $logger = app(AuditLogger::class);
        $logger->system('requested', 'conversation', 1, 'En A', [], $a, 'Asistente (automático)');
        $logger->system('requested', 'conversation', 2, 'En B', [], $b, 'Asistente (automático)');

        $this->get("/audit?workspace={$b->id}")->assertForbidden();

        config(['workspace.admin_code' => $a->code]);
        $records = fn (string $query) => collect($this->get("/audit?{$query}")->assertOk()->viewData('page')['props']['events']['data'])->pluck('record')->sort()->values()->all();
        $this->assertSame(['En B'], $records("workspace={$b->id}&actor=system"));
        $this->assertSame(['En A', 'En B'], $records('workspace=all&actor=system'));
        $this->assertSame(['En A'], $records('actor=system'));
    }

    public function test_an_automatic_event_belongs_to_the_workspace_of_its_conversation_only(): void
    {
        $a = $this->otherWorkspace('WS_A');
        $b = $this->otherWorkspace('WS_B');
        $conversation = $b->chatbots()->create(['name' => 'B bot'])->conversations()->create(['workspace_id' => $b->id, 'channel' => 'web', 'contact_id' => 'v', 'contact_name' => 'V', 'last_message_at' => now()]);
        $conversation->forceFill(['handling' => 'resolved'])->save();

        app(ConversationControl::class)->reopen($conversation->refresh());

        $this->assertSame([], $this->trail($a));
        $this->assertSame(['reopened/system/success'], $this->trail($b));
        $this->assertSame(1, Conversation::count());
    }
}
