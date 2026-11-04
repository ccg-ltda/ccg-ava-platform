<?php

namespace Tests\Feature;

use App\Models\AgentToolCall;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ConversationControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Contract test of the n8n <-> Ava conversation flow with the HTTP shapes n8n uses (local, automated: NO real n8n takes part): message in, shared context, handoff to a
 * person, the person answers, the conversation returns to the AI, and the AI side finds the whole history and the facts so nothing is asked twice. Also retries and isolation.
 */
class N8nContractFlowTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Chatbot $bot;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('FLUJO', ['dashboard', 'assistants', 'conversations']);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Bot']);
        $this->bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'is_active' => true]);
        $this->token = app(AgentAccess::class)->generate($this->bot);
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null)
    {
        return $this->flushHeaders()->withToken($token ?? $this->token)->json($method, "/api/agent{$path}", $data);
    }

    private function incoming(string $text, string $id): array
    {
        return ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => $text, 'external_id' => $id];
    }

    public function test_the_whole_human_and_ai_cycle_keeps_one_history_and_the_collected_facts(): void
    {
        // 1-2. A new message creates the conversation; n8n learns the AI may answer.
        $first = $this->api('POST', '/messages', $this->incoming('Hola, soy Ana y quiero una cita', 'wamid.1'))->assertCreated();
        $this->assertTrue($first->json('control.ai_allowed'));
        $version = $first->json('control.version');

        // 3-5. n8n stores what it learned and reports a step it executed.
        $this->api('POST', '/conversations/context', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'facts' => ['name' => ['value' => 'Ana', 'confirmed' => true]], 'steps' => ['intake_done'], 'next_step' => 'ask_email'])->assertOk();
        $this->api('POST', '/activity/steps', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'engine' => 'n8n', 'step_ref' => 'exec-9:llm:0', 'tool' => 'llm.reply', 'status' => 'succeeded', 'http_status' => 200])->assertCreated();
        $this->api('POST', '/messages', ['direction' => 'out', 'body' => '¿Cuál es tu correo?', 'external_id' => 'wamid.2'] + $this->incoming('', 'x'))->assertCreated();

        // 6. n8n asks for a person; a retry of the same request changes nothing.
        $this->api('POST', '/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'reason' => 'Pide hablar con alguien'])->assertOk()
            ->assertJsonPath('control.mode', 'pending')->assertJsonPath('handoff.outcome', 'requested')->assertJsonPath('handoff.executed', true);
        $again = $this->api('POST', '/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => '573001112233'])->assertOk()
            ->assertJsonPath('handoff.outcome', 'already_pending')->assertJsonPath('handoff.executed', false);
        $this->assertSame('pending', $again->json('control.mode'));
        $this->assertFalse($again->json('control.ai_allowed'));

        // The AI must not answer while a person holds the conversation, even with a stale authorization request.
        $this->api('POST', '/conversations/authorize', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'version' => $version])->assertOk()->assertJsonPath('control.ai_allowed', false);

        // 7-9. A person takes it, answers from Ava, and the customer replies.
        $agent = $this->member($this->workspace, 'admin');
        $conversation = Conversation::firstOrFail();
        app(ConversationControl::class)->take($conversation, $agent);
        $conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'out', 'sender' => 'agent', 'sender_user_id' => $agent->id, 'type' => 'text', 'body' => 'Te ayudo yo. Dime tu correo.', 'sent_at' => now()]);
        $this->api('POST', '/messages', $this->incoming('ana@example.com', 'wamid.3'))->assertCreated();
        $this->assertFalse($this->api('GET', '/conversations/context?channel=whatsapp&contact_id=573001112233')->json('control.ai_allowed'), 'A person still has it.');

        // 10. The person gives it back to the AI.
        app(ConversationControl::class)->returnToAi($conversation->refresh(), $agent, true);

        // 11. n8n recovers context and history: the fact it already had, the agent's turn and the customer's answer, with no repeated question to make.
        $context = $this->api('GET', '/conversations/context?channel=whatsapp&contact_id=573001112233&limit=20')->assertOk();
        $this->assertSame('ai', $context->json('control.mode'));
        $this->assertTrue($context->json('control.ai_allowed'));
        $this->assertSame('Ana', $context->json('context.facts.name.value'));
        $this->assertSame(['intake_done'], $context->json('context.steps'));
        $this->assertTrue($context->json('context.resume'), 'The workflow knows it is resuming after a person.');
        $texts = array_column($context->json('history'), 'text');
        $this->assertSame(['Hola, soy Ana y quiero una cita', '¿Cuál es tu correo?', 'Te ayudo yo. Dime tu correo.', 'ana@example.com'], $texts);
        $this->assertSame(['contact', 'ai', 'agent', 'contact'], array_column($context->json('history'), 'sender'));
        $this->assertSame(4, Message::count(), 'One history: nothing duplicated by the sync.');

        // 12. A retry of an already reported message does not duplicate it and does not make the AI answer twice.
        $retry = $this->api('POST', '/messages', $this->incoming('ana@example.com', 'wamid.3'))->assertOk();
        $this->assertFalse($retry->json('control.ai_allowed'));
        $this->assertSame(4, Message::count());
        $this->assertSame(1, AgentToolCall::count());
    }

    public function test_the_workflow_can_read_only_what_is_newer_than_the_last_message_it_processed(): void
    {
        $first = $this->api('POST', '/messages', $this->incoming('Uno', 'wamid.1'))->assertCreated()->json('id');
        $this->api('POST', '/messages', $this->incoming('Dos', 'wamid.2'))->assertCreated();
        $this->api('POST', '/messages', $this->incoming('Tres', 'wamid.3'))->assertCreated();

        $news = $this->api('GET', "/conversations/context?channel=whatsapp&contact_id=573001112233&after_id={$first}")->assertOk();
        $this->assertSame(['Dos', 'Tres'], array_column($news->json('history'), 'text'));
        $last = $news->json('last_message_id');
        $this->assertSame([], $this->api('GET', "/conversations/context?channel=whatsapp&contact_id=573001112233&after_id={$last}")->json('history'), 'Nothing new after the last processed message.');
    }

    public function test_the_context_is_only_readable_and_writable_inside_the_workspace_of_the_token(): void
    {
        $this->api('POST', '/messages', $this->incoming('Hola', 'wamid.1'))->assertCreated();
        $otherWorkspace = $this->bookingWorkspace('AJENO', ['dashboard', 'assistants', 'conversations']);
        $otherBot = $otherWorkspace->chatbots()->create(['name' => 'Ajeno']);
        $otherBot->channels()->create(['workspace_id' => $otherWorkspace->id, 'channel' => 'whatsapp', 'is_active' => true]);
        $otherToken = app(AgentAccess::class)->generate($otherBot);

        $this->api('GET', '/conversations/context?channel=whatsapp&contact_id=573001112233', [], $otherToken)->assertNotFound();
        $this->api('POST', '/conversations/context', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'facts' => ['name' => 'Intruso']], $otherToken)->assertNotFound();
        $this->assertSame([], Conversation::firstOrFail()->refresh()->context['facts'] ?? [], 'The foreign write changed nothing.');
        $this->flushHeaders()->getJson('/api/agent/conversations/context?channel=whatsapp&contact_id=573001112233')->assertUnauthorized();
    }

    public function test_invalid_context_payloads_are_rejected_and_malformed_parts_are_not_stored(): void
    {
        $this->api('POST', '/messages', $this->incoming('Hola', 'wamid.1'))->assertCreated();

        $this->api('GET', '/conversations/context?channel=nope&contact_id=573001112233')->assertStatus(422);
        $this->api('GET', '/conversations/context?channel=whatsapp&contact_id=573001112233&limit=500')->assertStatus(422);
        $this->api('POST', '/conversations/context', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'facts' => ['bad key!' => 'x', 'name' => 'Ana']])->assertOk();

        $facts = $this->api('GET', '/conversations/context?channel=whatsapp&contact_id=573001112233')->json('context.facts');
        $this->assertSame(['name'], array_keys($facts));
    }
}
