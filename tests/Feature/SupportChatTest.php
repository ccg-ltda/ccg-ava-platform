<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\SendSupportNotification;
use App\Models\AuditLog;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\OperationalAlert;
use App\Services\SupportChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CCG's public support chat: the visitor of a public page writes through the web widget and the conversation lands in the inbox of the administrative
 * Workspace (Desarrollo_DEV), where only its authorized team reads and answers it. The visitor never joins that Workspace, never chooses it, and never
 * reaches its inbox; a client's own web chat stays in the client's Workspace. n8n and the AI are faked (no external service is called).
 */
class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://n8n.example.com/webhook/ccg-support';

    private const NOTIFY = 'https://n8n.example.com/webhook/ccg-team-alert';

    private Workspace $dev;

    private Workspace $client;

    private string $key;

    private string $clientKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        $this->seedRoleCatalog();

        $organization = Organization::create(['name' => 'CCG']);
        // The default code of config/workspace.php: the destination is identified by it, never by an id.
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_X', 'name' => 'Cliente X']);

        $this->key = $this->liveWidget($this->dev, 'Soporte CCG');
        $this->clientKey = $this->liveWidget($this->client, 'Chat del cliente');
        config(['support.public_chat_key' => $this->key]);
    }

    private function member(Workspace $workspace, string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function signIn(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
    }

    private function liveWidget(Workspace $workspace, string $name): string
    {
        $workspace->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => self::HOOK, 'allowed_origins' => []], 'secrets' => ['webhook_secret' => 'SECRETO-DEL-WEBHOOK']]);
        $bot = $workspace->chatbots()->create(['name' => $name]);
        $this->signIn($this->member($workspace, 'admin'), $workspace)->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('success');
        auth()->logout();

        return ChatbotChannel::where('chatbot_id', $bot->id)->where('channel', 'web')->firstOrFail()->public_key;
    }

    private function say(string $session, string $text, ?string $key = null, array $extra = [])
    {
        return $this->postJson('/api/widget/'.($key ?? $this->key).'/messages', ['session_id' => $session, 'message' => $text] + $extra);
    }

    // --- the visitor -> the inbox of Desarrollo_DEV ---------------------------------------------------------------

    public function test_a_public_visitor_creates_a_conversation_in_the_support_channel_of_the_administrative_workspace_without_an_account(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'Hola, soy Ava.'])]);
        $users = User::count();
        $members = $this->dev->users()->count();

        $this->say('visitor-0001', 'Quiero información')->assertOk()->assertExactJson(['reply' => 'Hola, soy Ava.']);

        $conversation = Conversation::where('channel', 'web')->firstOrFail();
        $this->assertSame($this->dev->id, $conversation->workspace_id, 'the destination is the administrative Workspace');
        $this->assertSame('DESARROLLO_DEV', $conversation->chatbot->workspace->code);
        $this->assertSame(ChatbotChannel::where('public_key', $this->key)->value('chatbot_id'), $conversation->chatbot_id);
        $this->assertSame(['contact', 'ai'], $conversation->messages()->orderBy('id')->pluck('sender')->all());

        // The visitor is nobody of the platform: no account, no membership.
        $this->assertSame($users, User::count());
        $this->assertSame($members, $this->dev->users()->count());
    }

    public function test_the_public_pages_offer_the_chat_only_while_the_configured_channel_really_belongs_to_the_administrative_workspace(): void
    {
        $this->get('/pre-login')->assertOk()->assertInertia(fn ($page) => $page->where('supportChat.key', $this->key)->where('supportChat.script', url('/widget/ava-widget.js')));
        $this->assertNull(app(SupportChannel::class)->problem());

        // Not configured: nothing is offered (no button that does nothing).
        config(['support.public_chat_key' => null]);
        $this->get('/pre-login')->assertInertia(fn ($page) => $page->where('supportChat', null));

        // A key of a client's channel is refused and reported; it never reaches the public pages.
        config(['support.public_chat_key' => $this->clientKey]);
        $this->assertSame('not_administrative_workspace', app(SupportChannel::class)->problem());
        $this->get('/pre-login')->assertInertia(fn ($page) => $page->where('supportChat', null));

        config(['support.public_chat_key' => str_repeat('a', 32)]);
        $this->assertSame('unknown_channel', app(SupportChannel::class)->problem());
        config(['support.public_chat_key' => 'corta']);
        $this->assertSame('invalid_key', app(SupportChannel::class)->problem());

        // The channel stops being live: no button either.
        config(['support.public_chat_key' => $this->key]);
        ChatbotChannel::where('public_key', $this->key)->update(['is_active' => false]);
        $this->assertSame('channel_not_live', app(SupportChannel::class)->problem());
    }

    public function test_the_workspace_sent_by_the_visitor_changes_nothing_the_key_decides(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);

        $this->say('visitor-0001', 'Hola', null, ['workspace_id' => $this->client->id, 'workspace' => 'CLIENTE_X', 'chatbot_id' => $this->client->chatbots()->value('id')])->assertOk();

        $this->assertSame(1, Conversation::where('workspace_id', $this->dev->id)->count());
        $this->assertSame(0, Conversation::where('workspace_id', $this->client->id)->count());
    }

    public function test_the_visitor_cannot_reach_the_internal_inbox_or_the_administrative_apis(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $this->say('visitor-0001', 'Hola')->assertOk();
        $conversation = Conversation::firstOrFail();

        $this->get('/conversations')->assertRedirect('/login');
        $this->postJson("/conversations/{$conversation->id}/take")->assertUnauthorized();
        $this->postJson("/conversations/{$conversation->id}/messages", ['body' => 'Hola'])->assertUnauthorized();
        $this->getJson('/api/agent/config')->assertUnauthorized();
        $this->postJson('/api/agent/conversations/handoff', ['contact_id' => 'visitor-0001'])->assertUnauthorized();
        $this->get('/plans')->assertRedirect('/login');
        $this->assertSame('ai', $conversation->fresh()->handling, 'nothing changed');
    }

    // --- who may read and answer ---------------------------------------------------------------------------------

    public function test_only_the_authorized_team_of_the_administrative_workspace_sees_and_answers_the_conversations(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $this->say('visitor-0001', 'Necesito ayuda')->assertOk();
        $conversation = Conversation::firstOrFail();

        // A user of CCG with a role that lacks the permission is refused by the backend.
        $this->signIn($this->member($this->dev, 'cliente'), $this->dev);
        $this->get('/conversations')->assertForbidden();
        $this->post("/conversations/{$conversation->id}/take")->assertForbidden();

        // An administrator of a CLIENT company, with every client permission, sees nothing of CCG's support and cannot act on it.
        $this->signIn($this->member($this->client, 'admin'), $this->client);
        $this->get('/conversations')->assertOk()->assertInertia(fn ($page) => $page->where('conversations', []));
        $this->post("/conversations/{$conversation->id}/take")->assertNotFound();
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Intruso'])->assertNotFound();
        $this->assertSame(0, Message::where('sender', 'agent')->count());

        // The authorized agent of CCG reads it and answers.
        $this->signIn($this->member($this->dev, 'agente'), $this->dev);
        $this->get('/conversations')->assertOk()->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.channel.key', 'web'));
        $this->post("/conversations/{$conversation->id}/take")->assertSessionHasNoErrors();
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Soy una persona de CCG'])->assertSessionMissing('error');

        $reply = Message::where('sender', 'agent')->firstOrFail();
        $this->assertSame('Soy una persona de CCG', $reply->body);
        $this->assertNotNull($reply->sender_user_id, 'a person, not the AI');
        $this->assertSame(['contact', 'ai', 'agent'], $conversation->messages()->orderBy('id')->pluck('sender')->all());
    }

    public function test_the_answer_of_the_team_reaches_only_its_visitor_and_visitors_cannot_read_each_other(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $this->say('visitor-0001', 'Soy el primero')->assertOk();
        $this->say('visitor-0002', 'Soy la segunda')->assertOk();
        $first = Conversation::where('contact_id', 'visitor-0001')->firstOrFail();

        $this->signIn($this->member($this->dev, 'agente'), $this->dev);
        $this->post("/conversations/{$first->id}/take");
        $this->post("/conversations/{$first->id}/messages", ['body' => 'Solo para el primero']);
        auth()->logout();

        $this->getJson("/api/widget/{$this->key}/messages?session_id=visitor-0002")->assertOk()->assertJsonCount(0, 'messages');
        $this->getJson("/api/widget/{$this->key}/messages?session_id=visitor-0001")->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.text', 'Solo para el primero');
        // The channel of a client does not serve CCG's conversations: the same session id there is another, empty conversation.
        $this->getJson("/api/widget/{$this->clientKey}/messages?session_id=visitor-0001")->assertOk()->assertJsonCount(0, 'messages');
        // The history is never part of what the widget gets: only what an agent wrote and has not been delivered yet.
        $this->assertStringNotContainsString('Soy el primero', $this->getJson("/api/widget/{$this->key}/messages?session_id=visitor-0001")->getContent());
    }

    public function test_the_web_chat_of_a_client_stays_in_the_workspace_of_that_client(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'respuesta del cliente'])]);

        $this->say('visitor-0001', 'Hola cliente', $this->clientKey)->assertOk();
        $this->say('visitor-0001', 'Hola CCG')->assertOk(); // the same session id on the two channels: two conversations

        $this->assertSame(1, Conversation::where('workspace_id', $this->client->id)->count());
        $this->assertSame(1, Conversation::where('workspace_id', $this->dev->id)->count());
        $this->assertSame('Hola cliente', Message::where('workspace_id', $this->client->id)->where('direction', 'in')->value('body'));
        $this->assertSame('Hola CCG', Message::where('workspace_id', $this->dev->id)->where('direction', 'in')->value('body'));

        $this->signIn($this->member($this->dev, 'agente'), $this->dev)->get('/conversations')->assertInertia(fn ($page) => $page->has('conversations', 1));
    }

    // --- no duplicates, escalation, failures ---------------------------------------------------------------------

    public function test_a_repeated_request_is_stored_and_answered_once_and_alerts_the_team_once(): void
    {
        Queue::fake();
        config(['support.notify_webhook_url' => self::NOTIFY]);
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);

        $this->say('visitor-0001', 'Hola', null, ['message_id' => 'm-abc12345'])->assertOk();
        $this->say('visitor-0001', 'Hola', null, ['message_id' => 'm-abc12345'])->assertOk()->assertExactJson(['reply' => null]);

        $this->assertSame(1, Message::where('direction', 'in')->count());
        Http::assertSentCount(1);
        Queue::assertPushed(SendSupportNotification::class, 1);
        Queue::assertPushed(SendSupportNotification::class, fn (SendSupportNotification $job) => $job->event === 'new_conversation');

        // A second message of the same conversation is not a new conversation.
        $this->say('visitor-0001', 'Otra cosa', null, ['message_id' => 'm-def67890'])->assertOk();
        Queue::assertPushed(SendSupportNotification::class, 1);
    }

    public function test_a_request_for_a_person_hands_the_conversation_over_and_alerts_the_team_without_sending_anything_when_unconfigured(): void
    {
        Queue::fake();
        Http::fake([self::HOOK => Http::response(['reply' => 'Te paso con una persona.', 'handoff' => true])]);

        $this->say('visitor-0001', 'Quiero hablar con alguien')->assertOk()->assertJsonPath('handling', 'pending');
        $this->assertSame('pending', Conversation::firstOrFail()->handling);
        Queue::assertNothingPushed(); // no CCG_SUPPORT_NOTIFY_URL: nothing leaves Ava

        config(['support.notify_webhook_url' => self::NOTIFY]);
        $this->say('visitor-0002', 'Necesito una persona ya')->assertOk();
        Queue::assertPushed(SendSupportNotification::class, fn (SendSupportNotification $job) => $job->event === 'new_conversation');
        Queue::assertPushed(SendSupportNotification::class, fn (SendSupportNotification $job) => $job->event === 'human_requested');
    }

    public function test_when_the_ai_fails_the_visitor_is_not_left_with_an_error_and_a_person_is_asked_for(): void
    {
        Http::fake([self::HOOK => Http::response('boom: SECRETO-DEL-WEBHOOK', 500)]);

        $response = $this->say('visitor-0001', 'Hola')->assertOk()->assertJsonPath('handling', 'pending');

        $this->assertStringNotContainsString('SECRETO', $response->getContent());
        $conversation = Conversation::firstOrFail();
        $this->assertSame('pending', $conversation->handling);
        $this->assertSame('Hola', $conversation->messages()->where('direction', 'in')->value('body'), 'the request is not lost');

        // The widget of a client keeps the plain failure for its own visitor.
        $this->say('visitor-0001', 'Hola', $this->clientKey)->assertStatus(502);
    }

    public function test_the_team_alert_carries_ids_only_is_idempotent_by_event_and_a_failure_is_reported_without_secrets(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'ok']), self::NOTIFY => Http::sequence()->push([], 200)->push('CLAVE-DE-AVISOS', 500)]);
        $this->say('visitor-0001', 'Texto privado del visitante')->assertOk();
        $conversation = Conversation::firstOrFail();
        config(['support.notify_webhook_url' => self::NOTIFY, 'support.notify_secret' => 'CLAVE-DE-AVISOS']); // after the conversation: the test calls the job itself

        $job = new SendSupportNotification('new_conversation', $conversation->id, (int) $conversation->handling_version);
        $job->handle(app(SafeHttpTarget::class));

        Http::assertSent(function ($request) use ($conversation) {
            if ($request->url() !== self::NOTIFY) {
                return false;
            }

            $body = $request->body();

            return $request->header('X-Ava-Event-Id')[0] === "support:new_conversation:{$conversation->id}:{$conversation->handling_version}"
                && $request->header('X-Ava-Secret')[0] === 'CLAVE-DE-AVISOS'
                && ! str_contains($body, 'Texto privado') && ! str_contains($body, 'visitor-0001') && str_contains($body, '"conversation_id":'.$conversation->id);
        });

        // A rejected alert is retried (it throws) and, when it gives up, reported with a fixed reason: no URL, no secret, no message text.
        try {
            $job->handle(app(SafeHttpTarget::class));
            $this->fail('a rejected alert must throw so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertSame('support_notification_rejected_500', $e->getMessage());
            $this->assertStringNotContainsString('CLAVE', $e->getMessage());
        }

        $this->mock(OperationalAlert::class)->shouldReceive('raise')->once()->withArgs(fn (string $event, array $context) => $event === 'support_notification_failed'
            && ! str_contains(json_encode($context), 'CLAVE') && ! str_contains(json_encode($context), 'n8n.example.com'));
        $job->failed($e);
    }

    // --- audit and diagnosis -------------------------------------------------------------------------------------

    public function test_the_internal_actions_on_a_support_conversation_are_audited_with_who_did_them(): void
    {
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $this->say('visitor-0001', 'Hola')->assertOk();
        $conversation = Conversation::firstOrFail();
        $agent = $this->member($this->dev, 'agente');

        $this->signIn($agent, $this->dev)->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/resolve");

        $events = AuditLog::where('workspace_id', $this->dev->id)->where('user_id', $agent->id)->get();
        $this->assertGreaterThanOrEqual(2, $events->count());
        $this->assertTrue($events->every(fn (AuditLog $event) => $event->workspace_code === 'DESARROLLO_DEV'));
        $this->assertSame('resolved', $conversation->fresh()->handling);
    }

    public function test_the_diagnosis_reports_the_support_chat_without_revealing_the_key(): void
    {
        Artisan::call('integrations:diagnose');
        $output = Artisan::output();
        $this->assertStringContainsString('El chat público de CCG apunta a un canal Web activo del Workspace administrativo.', $output);
        $this->assertStringContainsString('Sin CCG_SUPPORT_NOTIFY_URL', $output);
        $this->assertStringNotContainsString($this->key, $output);

        config(['support.public_chat_key' => $this->clientKey]);
        $this->assertSame(1, Artisan::call('integrations:diagnose'));
        $output = Artisan::output();
        $this->assertStringContainsString('not_administrative_workspace', $output);
        $this->assertStringNotContainsString($this->clientKey, $output);

        config(['support.public_chat_key' => null]);
        Artisan::call('integrations:diagnose');
        $this->assertStringContainsString('no está configurado (CCG_SUPPORT_CHAT_KEY)', Artisan::output());
    }
}
