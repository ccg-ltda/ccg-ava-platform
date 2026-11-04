<?php

namespace Tests\Feature;

use App\Models\AgentToolCall;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The engine (n8n / Lava Studio) reports the API calls it makes by itself and Ava shows them next to the tools it serves: idempotent by `step_ref`, redacted,
 * isolated by Workspace, honest about what was not reported.
 */
class AgentActivityReportTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations'];

    private Workspace $workspace;

    private Chatbot $bot;

    private string $token;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('ACTIVIDAD', self::MODULES);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Asistente']);
        $this->bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'is_active' => true]);
        $this->token = app(AgentAccess::class)->generate($this->bot);
        $this->conversation = $this->bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura'])->refresh();
    }

    /** @return array<string, mixed> */
    private function step(array $extra = []): array
    {
        return $extra + [
            'channel' => 'whatsapp', 'contact_id' => '573001112233', 'engine' => 'n8n', 'step_ref' => 'exec-77:booker_availability:0', 'execution_id' => 'exec-77',
            'tool' => 'booker.availability', 'tool_label' => 'Disponibilidad en Booker', 'method' => 'post', 'endpoint' => 'https://api.booker.example/v4.1/availability?token=abc123',
            'request_headers' => ['Authorization' => 'Bearer SECRET-TOKEN', 'Content-Type' => 'application/json'],
            'request_body' => ['service' => 5, 'api_key' => 'KEY-VALUE', 'date' => '2026-11-05'],
        ];
    }

    private function report(array $data)
    {
        return $this->withToken($this->token)->postJson('/api/agent/activity/steps', $data);
    }

    public function test_a_reported_step_is_stored_with_its_request_redacted_and_pending_until_the_answer_arrives(): void
    {
        $this->report($this->step())->assertCreated()->assertJsonPath('status', 'started')->assertJsonPath('finished', false);

        $call = AgentToolCall::firstOrFail();
        $this->assertSame(['reported', 'n8n', 'exec-77', 'POST', $this->workspace->id, $this->conversation->id], [$call->source, $call->engine, $call->execution_id, $call->method, $call->workspace_id, $call->conversation_id]);
        $this->assertStringNotContainsString('SECRET-TOKEN', json_encode($call->getAttributes()));
        $this->assertStringNotContainsString('KEY-VALUE', json_encode($call->getAttributes()));
        $this->assertStringNotContainsString('abc123', json_encode($call->getAttributes()));
        $this->assertSame('https://api.booker.example/v4.1/availability?token=[REDACTED]', $call->endpoint);
        $this->assertNull($call->finished_at);
        $this->assertNull($call->response_body, 'Nothing is invented for an answer nobody reported.');
    }

    public function test_the_answer_completes_the_same_step_and_a_retry_never_duplicates_it(): void
    {
        $this->report($this->step())->assertCreated();
        $done = $this->step(['status' => 'succeeded', 'http_status' => 200, 'duration_ms' => 321, 'response_body' => ['slots' => ['09:00', '10:30']]]);

        $this->report($done)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('created', false)->assertJsonPath('finished', true);
        $this->report($done)->assertOk()->assertJsonPath('created', false);
        // A late replay of the start must not reopen a finished step nor erase its answer.
        $this->report($this->step())->assertOk()->assertJsonPath('status', 'succeeded');

        $this->assertSame(1, AgentToolCall::count());
        $call = AgentToolCall::firstOrFail();
        $this->assertSame([200, 321], [$call->http_status, $call->duration_ms]);
        $this->assertStringContainsString('10:30', $call->response_body);
        $this->assertNotNull($call->finished_at);
    }

    public function test_a_failed_step_keeps_the_error_without_secrets(): void
    {
        $this->report($this->step(['status' => 'failed', 'http_status' => 503, 'error_class' => 'NodeApiError', 'error_message' => 'Bearer LEAKED-1 timeout']))->assertCreated();

        $call = AgentToolCall::firstOrFail();
        $this->assertSame(['failed', 503, 'exception'], [$call->status, $call->http_status, $call->reason]);
        $this->assertStringNotContainsString('LEAKED-1', (string) $call->error_message);
    }

    public function test_validation_unknown_engines_unknown_contacts_and_missing_token(): void
    {
        $this->report($this->step(['engine' => 'zapier']))->assertStatus(422);
        $this->report($this->step(['step_ref' => 'x']))->assertStatus(422);
        $this->report($this->step(['contact_id' => '579999999999']))->assertNotFound();
        $this->flushHeaders()->postJson('/api/agent/activity/steps', $this->step())->assertUnauthorized();
        $this->assertSame(0, AgentToolCall::count());
    }

    public function test_a_step_key_of_another_conversation_or_workspace_can_never_be_touched(): void
    {
        $this->report($this->step())->assertCreated();
        $other = $this->bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573009998877', 'contact_name' => 'Otra'])->refresh();

        $this->report($this->step(['contact_id' => $other->contact_id, 'status' => 'failed']))->assertStatus(409);

        $foreign = $this->bookingWorkspace('AJENO', self::MODULES);
        $foreignBot = $foreign->chatbots()->create(['name' => 'Ajeno']);
        $foreignBot->channels()->create(['workspace_id' => $foreign->id, 'channel' => 'whatsapp', 'is_active' => true]);
        $foreignToken = app(AgentAccess::class)->generate($foreignBot);
        $foreignBot->conversations()->create(['workspace_id' => $foreign->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'X']);

        $this->withToken($foreignToken)->postJson('/api/agent/activity/steps', $this->step())->assertCreated();

        $this->assertSame(1, AgentToolCall::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(1, AgentToolCall::where('workspace_id', $foreign->id)->count(), 'The same key in another Workspace is its own step.');
        $this->assertSame('started', AgentToolCall::where('workspace_id', $this->workspace->id)->value('status'));
    }

    public function test_the_panel_numbers_the_steps_of_a_run_and_the_detail_shows_request_and_answer(): void
    {
        $this->conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Quiero una cita mañana', 'external_id' => 'wamid.1', 'sent_at' => now()]);
        $this->report($this->step(['trigger_message_external_id' => 'wamid.1', 'status' => 'succeeded', 'http_status' => 200, 'response_body' => ['ok' => true]]))->assertCreated();
        $this->report($this->step(['step_ref' => 'exec-77:booker_book:1', 'tool' => 'booker.book', 'tool_label' => 'Reserva en Booker']))->assertCreated();
        $this->actAsMember($this->workspace);

        $list = $this->getJson("/conversations/{$this->conversation->id}/activity")->assertOk();
        $calls = collect($list->json('calls'))->keyBy('tool');

        $this->assertSame([1, 2], [$calls['booker.availability']['step'], $calls['booker.book']['step']]);
        $this->assertSame(['Pendiente', 'n8n'], [$calls['booker.book']['statusLabel'], $calls['booker.book']['engineLabel']]);
        $this->assertSame('Quiero una cita mañana', $calls['booker.availability']['trigger']['text']);

        $detail = $this->getJson("/conversations/{$this->conversation->id}/activity/{$calls['booker.availability']['id']}")->assertOk();
        $this->assertSame('POST', $detail->json('request.method'));
        $this->assertSame('Quiero una cita mañana', $detail->json('request.message.text'));
        $this->assertSame(['ok' => true], $detail->json('response.body'));
        $this->assertSame(200, $detail->json('response.httpStatus'));
        $this->assertStringNotContainsString('SECRET-TOKEN', $detail->getContent());
    }

    public function test_the_detail_of_a_step_of_another_conversation_is_not_found(): void
    {
        $this->report($this->step())->assertCreated();
        $other = $this->bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573009998877', 'contact_name' => 'Otra'])->refresh();
        $this->actAsMember($this->workspace);

        $this->getJson("/conversations/{$other->id}/activity/".AgentToolCall::firstOrFail()->id)->assertNotFound();
    }
}
