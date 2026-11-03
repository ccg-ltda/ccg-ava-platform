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
use App\Services\ChatbotWorkflows;
use App\Services\ConversationControl;
use App\Services\ConversationMessenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * «Devolver a la IA» through the real route (`POST /conversations/{id}/release`), with no extra option. The conversation goes back to the AI under the same row and history
 * (never a new conversation); a message of the contact nobody answered is processed right away, and with nothing pending the AI waits for the next message and says nothing by
 * itself. Pressing the button again can not answer twice. n8n and WhatsApp are never called: the queue is faked and Http refuses stray requests.
 */
class ReturnToAiTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const PHONE_ID = '1000001';

    private Workspace $ws;

    private Chatbot $bot;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['n8n.legacy_environment' => 'all', 'n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.secret' => 'n8n-shared-SECRET', 'services.meta.app_secret' => 'meta-secret', 'services.meta.webhook_verify_token' => 'v',
            'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1', 'workspaces' => ['*']]]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seedRoleCatalog();

        $this->ws = $this->bookingWorkspace('DEVUELVE', ['dashboard', 'conversations', 'assistants']);
        $this->bot = $this->ws->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Atiende']);
        $integration = $this->ws->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => self::PHONE_ID], 'secrets' => ['access_token' => 'EAAB-token']]);
        $this->bot->channels()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($this->bot->refresh(), 'support');
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

    private function conversation(): Conversation
    {
        return Conversation::where('chatbot_id', $this->bot->id)->firstOrFail()->refresh();
    }

    /** A conversation a person took: the contact wrote, the AI had it, then an agent took it. */
    private function takenByAnAgent(): User
    {
        $this->inbound('Hola');
        $agent = $this->actAsMember($this->ws);
        app(ConversationControl::class)->take($this->conversation(), $agent);

        return $agent;
    }

    public function test_it_goes_back_to_the_ai_in_the_same_conversation_with_all_its_history(): void
    {
        $agent = $this->takenByAnAgent();
        app(ConversationMessenger::class)->send($this->conversation(), $agent, true, 'Soy una persona, te ayudo.');
        $before = Conversation::count();
        $messages = Message::count();

        $this->post("/conversations/{$this->conversation()->id}/release")->assertSessionHas('success');

        $this->assertSame($before, Conversation::count(), 'no new conversation');
        $this->assertSame($messages, Message::count(), 'the history is untouched');
        $this->assertSame(Conversation::AI, $this->conversation()->handling);
        $this->assertNull($this->conversation()->assigned_user_id);
        $this->assertSame(['contact', 'agent'], Message::where('conversation_id', $this->conversation()->id)->orderBy('id')->pluck('sender')->all());
    }

    public function test_a_message_of_the_contact_nobody_answered_is_processed_right_away(): void
    {
        $agent = $this->takenByAnAgent();
        $this->inbound('¿Siguen abiertos?'); // written while a person had it: the AI stayed silent
        Queue::fake(); // forget the queue of the first message: only what the release schedules counts
        $executions = ChatbotExecution::count();

        $this->post("/conversations/{$this->conversation()->id}/release")->assertSessionHas('success');

        Queue::assertPushed(RunChatbotExecution::class, 1);
        $this->assertSame($executions + 1, ChatbotExecution::count());
        $latest = Message::where('conversation_id', $this->conversation()->id)->where('sender', 'contact')->latest('id')->first();
        $this->assertSame($latest->id, ChatbotExecution::latest('id')->first()->message_id, 'the execution answers THAT message');
    }

    public function test_with_nothing_pending_the_ai_waits_and_does_not_speak_by_itself(): void
    {
        $agent = $this->takenByAnAgent();
        app(ConversationMessenger::class)->send($this->conversation(), $agent, true, 'Listo, quedó resuelto.'); // the agent spoke last
        Queue::fake();
        $executions = ChatbotExecution::count();
        $messages = Message::count();

        $this->post("/conversations/{$this->conversation()->id}/release")->assertSessionHas('success');

        Queue::assertNotPushed(RunChatbotExecution::class);
        $this->assertSame($executions, ChatbotExecution::count());
        $this->assertSame($messages, Message::count(), 'no spontaneous message');
        $this->assertSame(Conversation::AI, $this->conversation()->handling);

        // The next message of the contact is what the AI answers.
        $this->inbound('Gracias');
        Queue::assertPushed(RunChatbotExecution::class, 1);
    }

    public function test_pressing_the_button_again_does_not_answer_twice(): void
    {
        $agent = $this->takenByAnAgent();
        $this->inbound('¿Siguen abiertos?');
        Queue::fake();
        $executions = ChatbotExecution::count();
        $version = $this->conversation()->handling_version;
        $id = $this->conversation()->id;

        $this->post("/conversations/{$id}/release")->assertSessionHas('success');
        $this->post("/conversations/{$id}/release")->assertSessionHas('error');
        $this->post("/conversations/{$id}/release")->assertSessionHas('error');

        Queue::assertPushed(RunChatbotExecution::class, 1);
        $this->assertSame($executions + 1, ChatbotExecution::count());
        $this->assertSame($version + 1, $this->conversation()->handling_version, 'only the first click changed the control');
        $this->assertSame(Conversation::AI, $this->conversation()->handling);
    }

    public function test_the_release_ignores_a_continue_option_sent_by_the_client(): void
    {
        $agent = $this->takenByAnAgent();
        app(ConversationMessenger::class)->send($this->conversation(), $agent, true, 'Listo.');
        Queue::fake();

        $this->post("/conversations/{$this->conversation()->id}/release", ['continue' => true])->assertSessionHas('success');

        Queue::assertNotPushed(RunChatbotExecution::class);
    }

    public function test_only_who_may_operate_the_conversation_can_give_it_back_and_another_workspace_cannot(): void
    {
        $agent = $this->takenByAnAgent();
        $id = $this->conversation()->id;
        $this->app['auth']->forgetGuards();
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'conversations', 'assistants']);
        $this->actAsMember($other);

        $this->post("/conversations/{$id}/release")->assertNotFound();
        $this->assertSame(Conversation::HUMAN, $this->conversation()->handling);
        $this->assertSame($agent->id, $this->conversation()->assigned_user_id);
    }
}
