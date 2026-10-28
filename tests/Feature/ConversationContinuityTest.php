<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ConversationContext;
use App\Services\ConversationControl;
use App\Services\ConversationMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The AI resumes where it was after a person handed the conversation back. n8n is a CONTROLLED DOUBLE that follows the real contract (signed request, the
 * `execution_id` echoed, `reply` / `handoff` / `context` in the answer) and behaves like a simple stateful workflow: it asks for the name, then the phone, then
 * the email, and then the reason, but only for what the persisted context does not have yet. Nothing here talks to a real n8n or WhatsApp.
 */
class ConversationContinuityTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const N8N = 'https://n8n.platform.example.com/webhook';

    private const PHONE_ID = '1000001';

    private Workspace $ws;

    private Chatbot $bot;

    private User $agent;

    /** @var list<array<string, mixed>> the payloads n8n received, in order */
    private array $received = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['n8n.execution_url' => self::N8N, 'n8n.secret' => 'n8n-shared-SECRET', 'n8n.backoff' => [0, 0], 'services.meta.app_secret' => 'meta-secret', 'services.meta.webhook_verify_token' => 'v',
            'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1']]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seedRoleCatalog();

        $this->ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations', 'assistants']);
        $this->bot = $this->ws->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Atiende']);
        $integration = $this->ws->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => self::PHONE_ID], 'secrets' => ['access_token' => 'EAAB-token']]);
        $this->bot->channels()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($this->bot->refresh(), 'support');
        $this->agent = $this->member($this->ws, 'admin');
        $this->workflow();
    }

    /**
     * The stateful workflow double. It reads ONLY the payload (history + persisted context) like a real workflow would: it asks for the first thing the context lacks, records
     * what the customer just said as a confirmed fact for the thing it had asked, and never repeats an operation already in `operations`.
     */
    private function workflow(?callable $override = null): void
    {
        // Replaces (not appends to) the stub: Http::fake keeps the first matching one.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::N8N.'/*' => function (Request $request) use ($override) {
                $payload = $request->data();
                $this->received[] = $payload;
                $facts = $payload['context']['facts'] ?? [];
                $text = $payload['message']['text'];
                $answer = ['execution_id' => $request->header('X-Ava-Execution')[0]];

                if ($override && ($custom = $override($payload))) {
                    return Http::response($answer + $custom);
                }

                $asked = $payload['context']['next_step'] ?? null;
                $propose = [];

                // What the customer just answered goes to the question that was open (the workflow says so: it is a statement of the customer).
                if ($asked && str_starts_with($asked, 'ask_') && ! isset($facts[substr($asked, 4)])) {
                    $propose[substr($asked, 4)] = ['value' => $text, 'confirmed' => true];
                    $facts[substr($asked, 4)] = ['value' => $text];
                }

                foreach (['name' => '¿Cuál es tu nombre?', 'phone' => '¿Cuál es tu teléfono?', 'email' => '¿Cuál es tu correo?'] as $key => $question) {
                    if (! isset($facts[$key])) {
                        return Http::response($answer + ['reply' => $question, 'context' => ['facts' => $propose, 'steps' => $payload['context']['steps'] ?: [], 'next_step' => "ask_{$key}"]]);
                    }
                }

                $operations = isset($payload['context']['operations']['booking']) ? [] : ['booking' => 'RES-1'];

                return Http::response($answer + ['reply' => "Gracias {$facts['name']['value']}. ¿Cuál es el motivo de tu consulta?", 'context' => ['facts' => $propose, 'steps' => ['intake_done'], 'next_step' => 'ask_reason', 'operations' => $operations]]);
            },
        ]);
    }

    private function inbound(string $text): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '1555', 'phone_number_id' => self::PHONE_ID],
            'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => '573001112233']],
            'messages' => [['from' => '573001112233', 'id' => 'wamid.IN'.++$this->seq, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
        $raw = json_encode($payload);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-secret')], $raw)->assertOk();
    }

    /** Runs the execution the last inbound message queued. */
    private function aiAnswers(): void
    {
        app(ChatbotExecutions::class)->run(Queue::pushed(RunChatbotExecution::class)->last()->executionId);
    }

    private function conversation(): Conversation
    {
        return Conversation::where('chatbot_id', $this->bot->id)->firstOrFail()->refresh();
    }

    private function aiReplies(): array
    {
        return $this->conversation()->messages()->where('sender', 'ai')->orderBy('id')->pluck('body')->all();
    }

    public function test_after_a_person_hands_it_back_the_ai_continues_without_repeating_questions_or_operations(): void
    {
        // 1-6: the AI asks name and phone and goes on to the email.
        $this->inbound('Hola');
        $this->aiAnswers();
        $this->inbound('Ana Pérez');
        $this->aiAnswers();
        $this->inbound('3001234567');
        $this->aiAnswers();
        $this->assertSame(['¿Cuál es tu nombre?', '¿Cuál es tu teléfono?', '¿Cuál es tu correo?'], $this->aiReplies());

        $context = app(ConversationContext::class)->get($this->conversation());
        $this->assertSame('Ana Pérez', $context['facts']['name']['value']);
        $this->assertSame('confirmed', $context['facts']['name']['status']);
        $this->assertSame('3001234567', $context['facts']['phone']['value']);
        $callsBefore = count($this->received);

        // 7-10: an agent takes control, asks for the email, the customer answers and the agent records it.
        $conversation = $this->conversation();
        $control = app(ConversationControl::class);
        $control->take($conversation, $this->agent);
        app(ConversationMessenger::class)->send($conversation->refresh(), $this->agent, true, 'Hola Ana, ¿me confirmas tu correo?');
        $this->inbound('ana@empresa.co');
        // While a person has it the AI is silent: no execution, no call to n8n.
        $this->assertCount($callsBefore, $this->received);
        $this->assertSame(3, ChatbotExecution::count());
        app(ConversationContext::class)->apply($this->conversation(), ['facts' => ['email' => 'ana@empresa.co']], 'agent');

        // 11-13: the agent gives it back; the AI answers the unanswered message FROM the persisted state.
        $control->returnToAi($this->conversation(), $this->agent, true);
        app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation());
        $this->aiAnswers();

        $sent = $this->received[count($this->received) - 1];
        $this->assertTrue($sent['context']['resume']);
        $this->assertSame('Ana Pérez', $sent['context']['facts']['name']['value']);
        $this->assertSame('3001234567', $sent['context']['facts']['phone']['value']);
        $this->assertSame('ana@empresa.co', $sent['context']['facts']['email']['value']);
        $this->assertSame('agent', $sent['context']['facts']['email']['source']);
        // The agent's question and the customer's answer are inside the (wider) history, told apart by sender.
        $this->assertContains('agent', array_column($sent['history'], 'sender'));
        $this->assertContains('Hola Ana, ¿me confirmas tu correo?', array_column($sent['history'], 'text'));
        $this->assertSame(['contact', 'ai', 'contact', 'ai', 'contact', 'ai', 'agent'], array_column($sent['history'], 'sender'));

        // It moved on: it asked for NOTHING it already had, and went to the next step.
        $replies = $this->aiReplies();
        $this->assertSame('Gracias Ana Pérez. ¿Cuál es el motivo de tu consulta?', end($replies));
        $this->assertCount(4, $replies);
        $this->assertSame(1, count(array_filter($replies, fn ($r) => $r === '¿Cuál es tu nombre?')));
        $this->assertSame(1, count(array_filter($replies, fn ($r) => $r === '¿Cuál es tu teléfono?')));

        // The operation was recorded once, and the cycle is over (not "resuming" any more).
        $after = app(ConversationContext::class)->get($this->conversation());
        $this->assertSame('RES-1', $after['operations']['booking']['id']);
        $this->assertSame('ask_reason', $after['next_step']);
        $this->assertNull($after['handoff']['returned_at']);
        $this->assertNotNull($after['handoff']['resumed_at']);

        // Handing back again, or a repeated resume, does not answer the same message twice.
        $this->assertNull(app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation()));
        $this->assertSame(4, ChatbotExecution::count());
        $this->assertSame(4, Message::where('sender', 'ai')->count());
    }

    public function test_an_operation_already_done_is_never_replaced_or_repeated(): void
    {
        $this->inbound('Hola');
        $context = app(ConversationContext::class);
        $conversation = $this->conversation();

        $context->apply($conversation, ['operations' => ['booking' => 'RES-1']], 'ai');
        $context->apply($conversation, ['operations' => ['booking' => 'RES-OTHER', 'customer' => 'C-9']], 'ai');

        $stored = $context->get($conversation->refresh());
        $this->assertSame('RES-1', $stored['operations']['booking']['id']);
        $this->assertSame('C-9', $stored['operations']['customer']['id']);
        $this->assertSame(['booking' => 'RES-1', 'customer' => 'C-9'], $context->forWorkflow($conversation)['operations']);
    }

    public function test_inferred_data_never_overwrites_or_becomes_confirmed_and_contradictions_are_kept(): void
    {
        $this->inbound('Hola');
        $context = app(ConversationContext::class);
        $conversation = $this->conversation();

        $context->apply($conversation, ['facts' => ['phone' => '3001234567']], 'agent');
        $context->apply($conversation, ['facts' => ['phone' => ['value' => '3009999999']]], 'ai'); // an inference (not confirmed by the customer)
        $context->apply($conversation, ['facts' => ['city' => ['value' => 'Bogotá']]], 'ai');

        $stored = $context->get($conversation->refresh());
        $this->assertSame('3001234567', $stored['facts']['phone']['value']);
        $this->assertSame('confirmed', $stored['facts']['phone']['status']);
        $this->assertSame('3009999999', $stored['conflicts'][0]['proposed']);
        $this->assertSame('inferred', $stored['facts']['city']['status']); // stays an inference

        // The customer later states a different phone: it replaces the value, and the old one is kept.
        $context->apply($conversation, ['facts' => ['phone' => ['value' => '3112223344', 'confirmed' => true]]], 'ai');
        $stored = $context->get($conversation->refresh());
        $this->assertSame('3112223344', $stored['facts']['phone']['value']);
        $this->assertSame('3001234567', $stored['facts']['phone']['previous'][0]['value']);
        $this->assertSame('customer', $stored['facts']['phone']['source']);
    }

    public function test_incomplete_ambiguous_and_malformed_proposals_are_ignored_not_half_applied(): void
    {
        $this->inbound('Hola');
        $context = app(ConversationContext::class);
        $conversation = $this->conversation();

        $context->apply($conversation, ['facts' => ['Bad Key' => 'x', 'ok_key' => '', 'phone' => ['value' => ['array']], 'name' => 'Ana', 'x' => ['nested' => 1]], 'steps' => ['ask_name', 'not a key!'], 'next_step' => 'NOT VALID', 'operations' => ['bad key' => '1']], 'agent');

        $stored = $context->get($conversation->refresh());
        $this->assertSame(['name'], array_keys($stored['facts']));
        $this->assertSame(['ask_name'], $stored['steps']);
        $this->assertNull($stored['next_step']);
        $this->assertSame([], $stored['operations']);
    }

    public function test_a_damaged_context_is_recovered_without_touching_the_history_and_the_return_is_not_faked(): void
    {
        $this->inbound('Hola');
        $conversation = $this->conversation();
        $before = $conversation->messages()->count();
        $conversation->forceFill(['context' => ['version' => 99, 'facts' => 'garbage']])->save();

        $control = app(ConversationControl::class);
        $control->take($conversation->refresh(), $this->agent);
        $control->returnToAi($this->conversation(), $this->agent, true);

        $stored = $this->conversation()->context;
        $this->assertSame(1, $stored['version']);
        $this->assertIsArray($stored['facts']);
        $this->assertNotNull($stored['handoff']['returned_at']);
        $this->assertSame($before, $this->conversation()->messages()->count()); // history intact
        $this->assertSame(Conversation::AI, $this->conversation()->handling);
    }

    public function test_a_late_answer_does_not_store_its_context(): void
    {
        $this->inbound('Hola');
        $conversation = $this->conversation();
        $execution = ChatbotExecution::firstOrFail();

        // A person takes it while the workflow is working: its answer is late and changes nothing, not even the context.
        $this->workflow(function () use ($conversation) {
            app(ConversationControl::class)->take($conversation->fresh(), $this->agent);

            return ['reply' => 'tarde', 'context' => ['facts' => ['name' => ['value' => 'Fantasma', 'confirmed' => true]]]];
        });
        app(ChatbotExecutions::class)->run($execution->id);

        $this->assertSame([], $this->aiReplies());
        $this->assertArrayNotHasKey('name', app(ConversationContext::class)->get($this->conversation())['facts']);
    }

    public function test_the_workflow_can_ask_for_a_person_and_it_is_a_valid_transition_not_every_message(): void
    {
        $this->workflow(fn (array $p) => str_contains($p['message']['text'], 'persona') ? ['reply' => 'Te paso con una persona.', 'handoff' => true] : ['reply' => 'Claro.']);

        $this->inbound('Hola');
        $this->aiAnswers();
        $this->assertSame(Conversation::AI, $this->conversation()->handling);
        $this->assertSame(0, WorkspaceNotification::count());

        $this->inbound('Quiero hablar con una persona');
        $this->aiAnswers();
        $this->assertSame(Conversation::PENDING, $this->conversation()->handling);
        $this->assertSame(1, WorkspaceNotification::where('type', 'conversation_pending')->count());
        $this->assertNotNull(app(ConversationContext::class)->get($this->conversation())['handoff']['requested_at']);
    }

    public function test_returning_without_a_new_message_resumes_only_when_the_agent_asks_and_only_once(): void
    {
        $this->inbound('Hola');
        $this->aiAnswers();
        $conversation = $this->conversation();
        $control = app(ConversationControl::class);
        $control->take($conversation, $this->agent);
        app(ConversationMessenger::class)->send($conversation->refresh(), $this->agent, true, 'Ya revisé tu caso, Ana.');

        // Back to the AI WITHOUT «continuar ahora»: the agent spoke last, nothing waits for the AI, no call to n8n.
        $control->returnToAi($this->conversation(), $this->agent, true);
        $calls = count($this->received);
        $this->assertNull(app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation()));
        $this->assertCount($calls, $this->received);

        // With it: ONE execution of event `resume`, hung from the agent's last message; a second (simultaneous) attempt creates nothing.
        $first = app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation(), true);
        $second = app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation(), true);
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(2, ChatbotExecution::count());

        $this->aiAnswers();
        $sent = $this->received[count($this->received) - 1];
        $this->assertSame('resume', $sent['event']);
        $this->assertSame('agent', $sent['message']['sender']);
        $this->assertSame('Ya revisé tu caso, Ana.', end($sent['history'])['text']);
        $this->assertCount(2, $this->aiReplies());
    }

    public function test_while_a_person_has_it_no_resume_and_a_stale_answer_never_overwrites_newer_context(): void
    {
        $this->inbound('Hola');
        $conversation = $this->conversation();
        $stale = ChatbotExecution::firstOrFail();
        $control = app(ConversationControl::class);

        // A person takes it and records a confirmed email; the first execution had not answered yet.
        $control->take($conversation, $this->agent);
        app(ConversationContext::class)->apply($this->conversation(), ['facts' => ['email' => 'ana@empresa.co']], 'agent');
        $this->assertNull(app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation(), true)); // still theirs: the AI stays stopped
        $this->assertSame(1, ChatbotExecution::count());

        // Its late answer proposes another email: dropped, together with its context.
        $this->workflow(fn () => ['reply' => 'tarde', 'context' => ['facts' => ['email' => ['value' => 'otro@x.co', 'confirmed' => true]]]]);
        app(ChatbotExecutions::class)->run($stale->id);

        $this->assertSame('ana@empresa.co', app(ConversationContext::class)->get($this->conversation())['facts']['email']['value']);
        $this->assertSame([], $this->aiReplies());
    }

    public function test_a_failing_workflow_after_a_resume_hands_the_conversation_to_a_person(): void
    {
        $this->inbound('Hola');
        $this->aiAnswers();
        $conversation = $this->conversation();
        $control = app(ConversationControl::class);
        $control->take($conversation, $this->agent);
        $control->returnToAi($this->conversation(), $this->agent, true);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::N8N.'/*' => Http::response('boom', 500)]);
        $this->inbound('¿Sigues ahí?');
        for ($i = 0; $i < 3; $i++) {
            $this->aiAnswers();
        }

        $this->assertSame(Conversation::PENDING, $this->conversation()->handling);
        $this->assertSame(1, WorkspaceNotification::where('type', 'conversation_pending')->count());
    }

    public function test_the_agent_endpoint_records_facts_only_for_the_operator_and_only_in_its_workspace(): void
    {
        $this->inbound('Hola');
        $conversation = $this->conversation();
        app(ConversationControl::class)->take($conversation, $this->agent);

        $this->actingAs($this->agent)->withSession(['workspace_id' => $this->ws->id]);
        $this->post("/conversations/{$conversation->id}/context", ['facts' => ['phone' => '3001234567']])->assertSessionHasNoErrors();
        $this->post("/conversations/{$conversation->id}/context", ['facts' => ['Bad Key' => 'x']])->assertSessionHasErrors('facts');
        $facts = app(ConversationContext::class)->get($conversation->refresh())['facts'];
        $this->assertSame('3001234567', $facts['phone']['value']);
        $this->assertSame('agent', $facts['phone']['source']);
        $this->assertArrayNotHasKey('Bad Key', $facts);

        // Another agent who does not manage conversations is refused; a foreign Workspace cannot reach it at all.
        $other = $this->member($this->ws, 'agente');
        $this->actingAs($other)->withSession(['workspace_id' => $this->ws->id]);
        $this->post("/conversations/{$conversation->id}/context", ['facts' => ['phone' => '3000000000']])->assertSessionHas('error');
        $this->assertSame('3001234567', app(ConversationContext::class)->get($conversation->refresh())['facts']['phone']['value']);

        $foreign = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations', 'assistants']);
        $this->actingAs($this->member($foreign, 'admin'))->withSession(['workspace_id' => $foreign->id]);
        $this->post("/conversations/{$conversation->id}/context", ['facts' => ['phone' => '3000000000']])->assertNotFound();
    }
}
