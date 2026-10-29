<?php

namespace Tests\Feature;

use App\Chatbots\N8nConnection;
use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\ApiCallLog;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ConversationContext;
use App\Services\ConversationControl;
use App\Services\ConversationMessenger;
use App\Services\SupportDesk;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Desarrollo_DEV answering its support desk with AI: only when the administrative Workspace activates the desk chatbot AND assigns it a platform workflow (default: people
 * attend, as before). n8n is a controlled double; nothing talks to a real n8n.
 */
class SupportDeskAiTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $a;

    private Workspace $b;

    /** @var list<array<string, mixed>> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        config(['n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.secret' => 'shared-SECRET', 'n8n.backoff' => [0, 0],
            'n8n.workflows.soporte' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_s', 'webhook_path' => 'soporte-v1', 'version' => '1']]);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->seedRoleCatalog();
        $this->dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations']);
    }

    private function n8n(callable|array $answer): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Http::fake(['n8n.platform.example.com/*' => function (Request $request) use ($answer) {
            $this->received[] = $request->data();
            $body = is_callable($answer) ? $answer($request->data()) : $answer;

            return $body instanceof PromiseInterface ? $body : Http::response($body + ['execution_id' => $request->header('X-Ava-Execution')[0]]);
        }]);
    }

    private function activateAi(): Chatbot
    {
        $bot = app(SupportDesk::class)->destination();
        $bot->forceFill(['is_active' => true])->save();
        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'soporte');

        return $bot;
    }

    private function clientWrites(Workspace $workspace, string $text = 'Necesito ayuda'): User
    {
        static $n = 0;
        $user = $this->member($workspace, 'admin');
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
        $this->postJson('/support/messages', ['message' => $text, 'message_id' => 'ses-'.str_pad((string) ++$n, 8, '0', STR_PAD_LEFT)])->assertOk();

        return $user;
    }

    private function conversation(): Conversation
    {
        return Conversation::where('chatbot_id', app(SupportDesk::class)->destination()->id)->latest('id')->firstOrFail()->refresh();
    }

    private function runAi(): void
    {
        app(ChatbotExecutions::class)->run(Queue::pushed(RunChatbotExecution::class)->last()->executionId);
    }

    public function test_by_default_people_attend_the_support_request_as_before(): void
    {
        $this->clientWrites($this->a);

        $this->assertSame(Conversation::PENDING, $this->conversation()->handling);
        Queue::assertNothingPushed();
    }

    public function test_when_dev_activates_the_workflow_the_ai_attends_first_and_the_reply_is_stored(): void
    {
        $this->activateAi();
        $this->n8n(['reply' => 'Hola, soy el asistente de soporte. ¿Qué módulo?']);

        $this->clientWrites($this->a);

        $this->assertSame(Conversation::AI, $this->conversation()->handling); // not pending: the AI has it
        $this->assertSame(0, WorkspaceNotification::count());
        $this->runAi();
        $this->assertSame(['Hola, soy el asistente de soporte. ¿Qué módulo?'], $this->conversation()->messages()->where('sender', 'ai')->pluck('body')->all());
        // The payload carries the persisted context and never another Workspace's data.
        $this->assertArrayHasKey('context', $this->received[0]);
        $this->assertStringNotContainsString('CLIENTE_B', json_encode($this->received[0]));
    }

    public function test_the_workflow_asking_for_a_person_moves_it_to_pending_and_notifies_dev_once(): void
    {
        $this->activateAi();
        $this->n8n(['reply' => 'Te paso con una persona del equipo.', 'handoff' => true, 'context' => ['facts' => ['topic' => ['value' => 'facturación', 'confirmed' => true]]]]);

        $this->clientWrites($this->a, 'Quiero hablar con alguien');
        $this->runAi();

        $conversation = $this->conversation();
        $this->assertSame(Conversation::PENDING, $conversation->handling);
        $this->assertSame(1, WorkspaceNotification::where('workspace_id', $this->dev->id)->where('type', 'conversation_pending')->count());
        $this->assertSame('facturación', app(ConversationContext::class)->get($conversation)['facts']['topic']['value']);
    }

    public function test_when_n8n_cannot_be_reached_the_request_goes_to_a_person_and_nobody_is_left_in_silence(): void
    {
        $this->activateAi();
        $this->n8n(fn () => Http::response('error', 500));

        $this->clientWrites($this->a);
        for ($i = 0; $i < 3; $i++) {
            $this->runAi();
        }

        $conversation = $this->conversation();
        $this->assertSame(Conversation::PENDING, $conversation->handling);
        $this->assertSame(1, WorkspaceNotification::where('type', 'conversation_pending')->count());
        $this->assertSame(0, $conversation->messages()->where('sender', 'ai')->count());
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        $this->dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->dev->id]);

        return $user;
    }

    public function test_support_has_no_ai_configuration_of_its_own_anywhere(): void
    {
        $this->platformAdmin();

        // Configuraciones offers no «Soporte con IA»: the AI of the support is the ordinary chatbot of Desarrollo_DEV (Asistentes) with a workflow of the general catalog.
        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page->missing('supportAi'));
        $this->assertContains($this->put('/settings/support-ai', ['enabled' => true, 'workflow' => 'soporte'])->status(), [404, 405]);
        $this->assertFalse((bool) app(SupportDesk::class)->destination()->is_active, 'Nothing is on by default.');
        $this->assertSame([], array_values(array_filter(array_keys(config('permissions.permissions')), fn (string $name) => str_contains($name, 'support-ai'))));
        $this->assertArrayNotHasKey('supportai', config('permissions.groups'));
        $this->assertFileDoesNotExist(app_path('Services/SupportAi.php'));
    }

    public function test_a_support_conversation_runs_the_general_chain_in_the_administrative_workspace(): void
    {
        $this->activateAi();
        $this->n8n(['reply' => 'Hola, ¿en qué te ayudo?', 'context' => ['facts' => ['name' => ['value' => 'Ana', 'confirmed' => true]]]]);

        $this->clientWrites($this->a);
        $this->runAi();

        $conversation = $this->conversation();
        $this->assertSame($this->dev->id, $conversation->workspace_id);
        $this->assertSame($this->dev->id, $conversation->chatbot->workspace_id);
        $this->assertSame('soporte', $this->received[0]['workflow']['key']);
        $this->assertSame('message', $this->received[0]['event']);
        $this->assertSame(['Hola, ¿en qué te ayudo?'], $conversation->messages()->where('sender', 'ai')->pluck('body')->all());

        // The call is in the history of the administrative Workspace, redacted: no secret, no signature, no body of the conversation.
        $log = ApiCallLog::where('integration', 'n8n')->latest('id')->firstOrFail();
        $this->assertSame($this->dev->id, $log->workspace_id);
        $this->assertSame('success', $log->outcome);
        $dump = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('shared-SECRET', $dump);
        $this->assertStringNotContainsString('Necesito ayuda', $dump);
        $this->assertSame(0, ApiCallLog::where('workspace_id', $this->a->id)->count());

        // Another Workspace's chatbots are untouched by it.
        $this->assertSame(0, Chatbot::where('workspace_id', '!=', $this->dev->id)->whereNotNull('workflow_key')->count());
    }

    public function test_a_person_takes_the_support_conversation_the_ai_stays_silent_and_resumes_with_the_context(): void
    {
        $this->activateAi();
        $this->n8n(['reply' => 'Hola, ¿cómo te llamas?', 'context' => ['facts' => ['topic' => ['value' => 'facturación', 'confirmed' => true]], 'next_step' => 'ask_name']]);
        $client = $this->clientWrites($this->a);
        $this->runAi();

        $agent = $this->member($this->dev, 'admin');
        $control = app(ConversationControl::class);
        $control->take($this->conversation(), $agent);
        app(ConversationMessenger::class)->send($this->conversation(), $agent, true, 'Soy Luis, del equipo. Ya revisé tu factura.');

        // While the person has it, a new message from the client reaches the conversation but never the AI.
        $calls = count($this->received);
        $this->actingAs($client)->withSession(['workspace_id' => $this->a->id]);
        $this->postJson('/support/messages', ['message' => '¿Me confirmas?', 'message_id' => 'ses-silence01'])->assertOk();
        $this->assertSame(Conversation::HUMAN, $this->conversation()->handling);
        $this->assertCount($calls, $this->received);
        $this->assertSame(['Hola, ¿cómo te llamas?'], $this->conversation()->messages()->where('sender', 'ai')->pluck('body')->all());

        // Back to the AI with «continuar ahora»: ONE `resume` with the history of the person and the persisted context.
        $control->returnToAi($this->conversation(), $agent, true);
        $this->assertNotNull(app(ChatbotExecutions::class)->resumeAfterReturn($this->conversation(), true));
        $this->n8n(['reply' => 'Gracias por esperar, ya quedó.']);
        $this->runAi();

        $sent = end($this->received);
        $this->assertSame('facturación', $sent['context']['facts']['topic']['value']);
        $this->assertContains('agent', array_column($sent['history'], 'sender'));
        $this->assertContains('Soy Luis, del equipo. Ya revisé tu factura.', array_column($sent['history'], 'text'));
    }

    public function test_two_client_workspaces_get_separate_conversations_context_and_memory(): void
    {
        $this->activateAi();
        $this->n8n(['reply' => 'ok', 'context' => ['facts' => ['name' => ['value' => 'Ana', 'confirmed' => true]]]]);

        $this->clientWrites($this->a, 'Hola desde A');
        $this->runAi();
        $this->clientWrites($this->b, 'Hola desde B');
        $this->runAi();

        $conversations = Conversation::where('chatbot_id', app(SupportDesk::class)->destination()->id)->orderBy('id')->get();
        $this->assertCount(2, $conversations);
        $this->assertNotSame($this->received[0]['conversation']['memory_key'], $this->received[1]['conversation']['memory_key']);
        // B's payload starts with no facts: nothing of A's conversation reaches it.
        $this->assertSame([], $this->received[1]['context']['facts']);
        $this->assertStringNotContainsString('Hola desde A', json_encode($this->received[1]));
    }

    public function test_the_support_conversation_runs_on_the_n8n_connection_of_desarrollo_dev_not_on_the_environment(): void
    {
        $this->dev->integrations()->create(['name' => 'n8n', 'type' => 'n8n', 'is_active' => true, 'config' => ['base_url' => 'https://n8n.dev.example.com'], 'secrets' => ['api_key' => 'dev-api-key-123456', 'execution_secret' => 'dev-execution-SECRET-0001']]);
        $this->activateAi();
        $seen = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['n8n.dev.example.com/*' => function (Request $request) use (&$seen) {
            $seen[] = $request;

            return Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'Hola desde el n8n de DEV']);
        }]);

        $this->clientWrites($this->a);
        $this->runAi();

        $this->assertCount(1, $seen);
        $this->assertSame('https://n8n.dev.example.com/webhook/soporte-v1', $seen[0]->url());
        $timestamp = $seen[0]->header('X-Ava-Timestamp')[0];
        $this->assertSame('sha256='.hash_hmac('sha256', "{$timestamp}.{$seen[0]->body()}", 'dev-execution-SECRET-0001'), $seen[0]->header('X-Ava-Signature')[0]);
        $this->assertSame(['Hola desde el n8n de DEV'], $this->conversation()->messages()->where('sender', 'ai')->pluck('body')->all());
        $this->assertStringNotContainsString('shared-SECRET', json_encode($seen[0]->headers()).$seen[0]->body(), 'The platform variables never reach a Workspace that has its own connection.');
        // The client Workspace that wrote has no connection and gets nothing of it.
        $this->assertNull(app(N8nConnection::class)->forWorkspace($this->a));
    }
}
