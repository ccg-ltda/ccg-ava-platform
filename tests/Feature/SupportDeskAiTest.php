<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ConversationContext;
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

    public function test_the_platform_configures_the_support_ai_from_the_interface_and_nothing_is_on_by_default(): void
    {
        $this->platformAdmin();

        $this->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->where('supportAi.enabled', false)->where('supportAi.n8nConfigured', true)->where('supportAi.catalog.0.key', 'soporte')->where('supportAi.problem', null));
        $this->assertStringNotContainsString('shared-SECRET', $this->get('/settings')->getContent()); // the n8n secret never reaches the page

        // Not valid: unknown workflow; then n8n not configured. Nothing changes.
        $this->put('/settings/support-ai', ['enabled' => true, 'workflow' => 'inventado'])->assertSessionHasErrors('workflow');
        config(['n8n.secret' => null]);
        $this->put('/settings/support-ai', ['enabled' => true, 'workflow' => 'soporte'])->assertSessionHasErrors('enabled');
        $this->assertFalse((bool) app(SupportDesk::class)->destination()->is_active);
        config(['n8n.secret' => 'shared-SECRET']);

        $this->put('/settings/support-ai', ['enabled' => true, 'workflow' => 'soporte'])->assertSessionHasNoErrors();
        $bot = app(SupportDesk::class)->destination()->refresh();
        $this->assertTrue($bot->is_active);
        $this->assertSame('soporte', $bot->workflow_key);
        $this->assertTrue(AuditLog::where('resource_type', 'chatbot')->where('workspace_id', $this->dev->id)->exists());

        // On: the client's request is attended by the AI first. Off again: a person gets it.
        $this->n8n(['reply' => 'Hola']);
        $this->clientWrites($this->a);
        $this->assertSame(Conversation::AI, $this->conversation()->handling);

        $this->actingAs($this->member($this->dev, 'admin'))->withSession(['workspace_id' => $this->dev->id]);
        $this->assertTrue(app(SupportDesk::class)->destination()->refresh()->is_active);
        $dev = User::where('is_superuser', true)->first();
        $this->actingAs($dev)->withSession(['workspace_id' => $this->dev->id]);
        $this->put('/settings/support-ai', ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse((bool) app(SupportDesk::class)->destination()->refresh()->is_active);
        $this->assertSame('soporte', app(SupportDesk::class)->destination()->workflow_key); // the choice is kept
        $this->clientWrites($this->b, 'Otra solicitud');
        $this->assertSame(Conversation::PENDING, Conversation::where('contact_id', 'like', 'c'.$this->b->id.'u%')->latest('id')->firstOrFail()->handling);
    }

    public function test_a_client_workspace_cannot_see_or_change_the_support_ai(): void
    {
        $c = $this->bookingWorkspace('CLIENTE_C', ['dashboard', 'settings']);
        $this->actingAs($this->member($c, 'admin'))->withSession(['workspace_id' => $c->id]);

        $this->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->where('supportAi', null));
        $this->put('/settings/support-ai', ['enabled' => true, 'workflow' => 'soporte'])->assertForbidden();
        $this->assertFalse((bool) app(SupportDesk::class)->destination()->is_active);
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
}
