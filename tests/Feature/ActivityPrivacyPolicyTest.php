<?php

namespace Tests\Feature;

use App\Models\AgentToolCall;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Observability\ApiRedactor;
use App\Services\ActivityPrivacy;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** Privacy policy of the technical activity: no credentials, masked contact data, body retention, per-Workspace switch, separate permission, Workspace isolation. */
class ActivityPrivacyPolicyTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'settings'];

    private Workspace $workspace;

    private Chatbot $bot;

    private string $token;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        Role::findOrCreate('solo_pasos', 'web')->syncPermissions(['view-conversations', 'view-technical-activity']);
        $this->workspace = $this->bookingWorkspace('PRIVADO', self::MODULES);
        [$this->bot, $this->token, $this->conversation] = $this->tenantBot($this->workspace);
    }

    /** @return array{Chatbot, string, Conversation} */
    private function tenantBot(Workspace $workspace): array
    {
        $bot = $workspace->chatbots()->create(['name' => 'Bot']);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'is_active' => true]);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura'])->refresh();

        return [$bot, app(AgentAccess::class)->generate($bot), $conversation];
    }

    private function report(array $extra = [], ?string $token = null)
    {
        return $this->flushHeaders()->withToken($token ?? $this->token)->postJson('/api/agent/activity/steps', $extra + [
            'channel' => 'whatsapp', 'contact_id' => '573001112233', 'engine' => 'n8n', 'step_ref' => 'exec-1:crm:0', 'tool' => 'crm.lookup', 'method' => 'POST', 'endpoint' => 'https://crm.example/api',
            'request_body' => ['email' => 'ana@example.com', 'phone' => '573001112233', 'note' => 'Escríbeme a bob@empresa.co o al +573009998877', 'service' => 'Corte'],
            'response_body' => ['customer' => ['name' => 'Ana', 'correo' => 'ana@example.com']], 'status' => 'succeeded', 'http_status' => 200,
        ]);
    }

    public function test_credentials_are_never_stored_whatever_the_field_or_the_text(): void
    {
        $redactor = app(ApiRedactor::class)->forActivity();
        $secrets = [
            'bearer' => 'Bearer abcDEF123456', 'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PjgQ', 'woo' => 'ck_'.str_repeat('a1', 20),
            'basic' => 'Basic dXNlcjpwYXNzd29yZA==', 'aws' => 'AKIAIOSFODNN7EXAMPLE', 'long' => str_repeat('aB3', 15), 'ava' => 'ava_'.str_repeat('x9', 12),
        ];
        $out = json_encode($redactor->data(['nested' => ['deep' => ['password' => 'pw-123', 'x_api_key' => 'k', 'cookie' => 'sid=1']], 'text' => implode(' ', $secrets)] + $secrets));

        foreach (['pw-123', 'dXNlcjpwYXNzd29yZA', 'AKIAIOSFODNN7EXAMPLE', 'dozjgNryP4J3jVmNHl0w5N', 'abcDEF123456', 'sid=1'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
        $this->assertStringContainsString('Corte', json_encode($redactor->data(['service' => 'Corte'])), 'Ordinary values stay readable.');
        $this->assertSame(['Authorization' => '[REDACTED]', 'X-Api-Key' => '[REDACTED]', 'Accept' => 'application/json'], $redactor->headers(['Authorization' => 'Bearer x', 'X-Api-Key' => 'k', 'Accept' => 'application/json']));
    }

    public function test_emails_and_phones_are_masked_in_the_stored_bodies_but_the_conversation_is_untouched(): void
    {
        $this->conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Mi correo es ana@example.com', 'external_id' => 'w1', 'sent_at' => now()]);
        $this->report()->assertCreated();

        $call = AgentToolCall::firstOrFail();
        $stored = $call->request_body.$call->response_body;
        foreach (['ana@example.com', 'bob@empresa.co', '573001112233', '+573009998877'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }
        $this->assertStringContainsString('a***@e***.com', $stored);
        $this->assertStringContainsString('••••2233', $stored);
        $this->assertStringContainsString('Corte', $stored, 'Enough is kept to debug the call.');
        $this->assertSame('Mi correo es ana@example.com', $this->conversation->messages()->value('body'), 'The real message is never altered.');
    }

    public function test_a_workspace_can_stop_storing_bodies_and_only_the_metadata_remains(): void
    {
        $this->actAsMember($this->workspace, 'admin');
        $this->put('/settings/activity-privacy', ['store_bodies' => false])->assertSessionHasNoErrors();
        $this->report(['request_headers' => ['Content-Type' => 'application/json'], 'request_query' => ['q' => 'x']])->assertCreated();

        $call = AgentToolCall::firstOrFail();
        $this->assertNull($call->request_body);
        $this->assertNull($call->response_body);
        $this->assertNull($call->request_headers);
        $this->assertNull($call->request_query);
        $this->assertSame(['POST', 'https://crm.example/api', 'succeeded', 200], [$call->method, $call->endpoint, $call->status, $call->http_status]);
        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $this->workspace->id, 'resource_type' => 'settings']);
    }

    public function test_the_switch_needs_the_settings_permission_and_belongs_to_the_session_workspace(): void
    {
        $this->actAsMember($this->workspace, 'solo_pasos');
        $this->put('/settings/activity-privacy', ['store_bodies' => false])->assertForbidden();

        $other = $this->bookingWorkspace('OTRO', self::MODULES);
        $this->actAsMember($this->workspace, 'admin');
        $this->put('/settings/activity-privacy', ['store_bodies' => false, 'workspace_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertFalse(app(ActivityPrivacy::class)->storesBodies($this->workspace));
        $this->assertTrue(app(ActivityPrivacy::class)->storesBodies($other), 'Another Workspace keeps its own setting.');
    }

    public function test_without_the_bodies_permission_only_the_metadata_is_visible(): void
    {
        $this->conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola quiero cita', 'external_id' => 'w1', 'sent_at' => now()]);
        $this->report(['trigger_message_external_id' => 'w1'])->assertCreated();
        $id = AgentToolCall::firstOrFail()->id;

        $this->actAsMember($this->workspace, 'solo_pasos');
        $list = $this->getJson("/conversations/{$this->conversation->id}/activity")->assertOk();
        $this->assertNull($list->json('calls.0.trigger'));
        $detail = $this->getJson("/conversations/{$this->conversation->id}/activity/{$id}")->assertOk();
        $this->assertTrue($detail->json('bodiesRestricted'));
        $this->assertSame(['POST', 'https://crm.example/api'], [$detail->json('request.method'), $detail->json('request.endpoint')]);
        foreach (['request.body', 'request.message', 'request.headers', 'response.body', 'response.errorMessage'] as $hidden) {
            $this->assertNull($detail->json($hidden), "{$hidden} must be hidden");
        }

        $this->actAsMember($this->workspace, 'admin');
        $full = $this->getJson("/conversations/{$this->conversation->id}/activity/{$id}")->assertOk();
        $this->assertFalse($full->json('bodiesRestricted'));
        $this->assertSame('Hola quiero cita', $full->json('request.message.text'));
        $this->assertNotNull($full->json('request.body'));
    }

    public function test_an_administrator_of_another_workspace_can_not_read_the_activity(): void
    {
        $this->report()->assertCreated();
        $id = AgentToolCall::firstOrFail()->id;
        $other = $this->bookingWorkspace('AJENO', self::MODULES);
        $this->actAsMember($other, 'admin');

        $this->getJson("/conversations/{$this->conversation->id}/activity")->assertNotFound();
        $this->getJson("/conversations/{$this->conversation->id}/activity/{$id}")->assertNotFound();
    }

    public function test_retention_removes_the_bodies_at_30_days_and_the_row_at_180(): void
    {
        $this->report()->assertCreated();
        $this->report(['step_ref' => 'exec-2:old:0'])->assertCreated();
        $this->report(['step_ref' => 'exec-3:ancient:0'])->assertCreated();
        $this->report(['step_ref' => 'exec-4:open:0', 'status' => 'started'])->assertCreated();
        AgentToolCall::where('external_ref', 'exec-2:old:0')->update(['created_at' => now()->subDays(45)]);
        AgentToolCall::where('external_ref', 'exec-3:ancient:0')->update(['created_at' => now()->subDays(200)]);
        AgentToolCall::where('external_ref', 'exec-4:open:0')->update(['created_at' => now()->subDays(300)]);

        Artisan::call('agent:tools:prune');

        $recent = AgentToolCall::where('external_ref', 'exec-1:crm:0')->firstOrFail();
        $old = AgentToolCall::where('external_ref', 'exec-2:old:0')->firstOrFail();
        $this->assertNotNull($recent->request_body, 'Under 30 days: untouched.');
        $this->assertNull($old->request_body);
        $this->assertNull($old->response_body);
        $this->assertNotNull($old->bodies_purged_at);
        $this->assertSame(['crm.lookup', 200], [$old->tool, $old->http_status], 'The metadata is kept.');
        $this->assertNull(AgentToolCall::where('external_ref', 'exec-3:ancient:0')->first(), 'Over 180 days: the row goes.');
        $this->assertNotNull(AgentToolCall::where('external_ref', 'exec-4:open:0')->first(), 'A call without an outcome is never pruned here.');
    }
}
