<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\SendAgentMessage;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ConversationControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Who answers a conversation: the AI or a human agent, how control moves between them and what each side may do. */
class HumanHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://n8n.example.com/webhook/ava-web';

    private const CONTACT = '573001112233';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function member(Workspace $workspace, string $role): User
    {
        $this->seedRoleCatalog();
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function signIn(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);
    }

    /** A chatbot with its WhatsApp channel on; returns [chatbot, agent token]. */
    private function liveBot(Workspace $workspace, string $name = 'Asistente'): array
    {
        $bot = $workspace->chatbots()->create(['name' => $name]);
        $integration = $workspace->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100200300'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN'],
        ]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    /** A chatbot with its web widget on; returns [chatbot, public key]. */
    private function liveWidget(Workspace $workspace): array
    {
        $workspace->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => self::HOOK, 'allowed_origins' => []], 'secrets' => []]);
        $bot = $workspace->chatbots()->create(['name' => 'Web bot']);
        $admin = $this->member($workspace, 'admin');
        $this->signIn($admin, $workspace)->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('success');

        return [$bot, ChatbotChannel::where('chatbot_id', $bot->id)->where('channel', 'web')->firstOrFail()->public_key];
    }

    private function conversation(Chatbot $bot, string $contact = self::CONTACT, string $channel = 'whatsapp'): Conversation
    {
        $conversation = $bot->conversations()->create(['workspace_id' => $bot->workspace_id, 'channel' => $channel, 'contact_id' => $contact, 'contact_name' => 'María', 'last_message_at' => now()]);
        $conversation->messages()->create(['workspace_id' => $bot->workspace_id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => now()]);

        return $conversation;
    }

    private function report(string $token, array $data = [])
    {
        return $this->withToken($token)->postJson('/api/agent/messages', $data + ['channel' => 'whatsapp', 'contact_id' => self::CONTACT, 'direction' => 'in', 'type' => 'text', 'body' => 'Hola']);
    }

    private function authorizeReply(string $token, array $data = [])
    {
        return $this->withToken($token)->postJson('/api/agent/conversations/authorize', $data + ['channel' => 'whatsapp', 'contact_id' => self::CONTACT]);
    }

    private function metaAccepts(string $id = 'wamid.AGENT1'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $id]]], 200)]);
    }

    // --- the AI answers until a person takes over -----------------------------------------------------------------

    public function test_existing_and_new_conversations_start_with_the_ai(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);

        $response = $this->report($token)->assertCreated();

        $response->assertJsonPath('control.mode', 'ai')->assertJsonPath('control.ai_allowed', true)->assertJsonPath('control.version', 0);
        $conversation = Conversation::firstOrFail();
        $this->assertSame('ai', $conversation->handling);
        $this->assertSame('contact', Message::firstOrFail()->sender);
        $this->assertNull($conversation->assigned_user_id);
    }

    public function test_a_retried_message_does_not_make_the_ai_answer_twice(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));

        $this->report($token, ['external_id' => 'wamid.1'])->assertCreated()->assertJsonPath('control.ai_allowed', true);
        $this->report($token, ['external_id' => 'wamid.1'])->assertOk()->assertJsonPath('created', false)->assertJsonPath('control.ai_allowed', false);

        $this->assertSame(1, Message::count());
    }

    public function test_the_automation_can_ask_for_a_person_and_the_ai_stops(): void
    {
        [, $token] = $this->liveBot($this->workspace('WS_A'));
        $this->report($token)->assertCreated();

        $this->withToken($token)->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => self::CONTACT, 'reason' => 'Quiere hablar de un reclamo'])
            ->assertOk()->assertJsonPath('control.mode', 'pending')->assertJsonPath('control.ai_allowed', false);

        $conversation = Conversation::firstOrFail();
        $this->assertSame('pending', $conversation->handling);
        $this->assertSame('Quiere hablar de un reclamo', $conversation->handoff_reason);
        $this->authorizeReply($token)->assertJsonPath('control.ai_allowed', false)->assertJsonPath('control.mode', 'pending');
        // Asking again changes nothing (no new version).
        $version = $conversation->handling_version;
        $this->withToken($token)->postJson('/api/agent/conversations/handoff', ['channel' => 'whatsapp', 'contact_id' => self::CONTACT])->assertOk();
        $this->assertSame($version, $conversation->fresh()->handling_version);
    }

    public function test_an_agent_takes_the_conversation_and_the_ai_is_blocked_on_every_path(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');

        $this->authorizeReply($token)->assertJsonPath('control.ai_allowed', true);
        $this->signIn($agent, $workspace)->post("/conversations/{$conversation->id}/take")->assertSessionHas('success');

        $conversation->refresh();
        $this->assertSame('human', $conversation->handling);
        $this->assertSame($agent->id, $conversation->assigned_user_id);

        // The automation: asking before it answers, and reporting a new message of the contact.
        $this->authorizeReply($token)->assertJsonPath('control.ai_allowed', false)->assertJsonPath('control.mode', 'human');
        $this->report($token, ['external_id' => 'wamid.NEW'])->assertCreated()->assertJsonPath('control.mode', 'human')->assertJsonPath('control.ai_allowed', false);
        // The message of the contact is kept in the same conversation.
        $this->assertSame(1, Conversation::count());
        $this->assertDatabaseHas('messages', ['external_id' => 'wamid.NEW', 'conversation_id' => $conversation->id]);
    }

    public function test_a_reply_prepared_before_control_changed_is_stale_even_if_the_ai_has_it_again(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);
        $seen = $this->report($token)->json('control.version');
        $conversation = Conversation::firstOrFail();
        $agent = $this->member($workspace, 'agente');

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/release")->assertSessionHas('success');

        $this->assertSame('ai', $conversation->fresh()->handling);
        $this->authorizeReply($token)->assertJsonPath('control.ai_allowed', true);
        $this->authorizeReply($token, ['version' => $seen])->assertJsonPath('control.ai_allowed', false);
        $this->authorizeReply($token, ['version' => $conversation->fresh()->handling_version])->assertJsonPath('control.ai_allowed', true);
    }

    public function test_the_ai_comes_back_only_when_control_is_returned_or_the_contact_writes_after_a_resolution(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $this->signIn($agent, $workspace);

        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/resolve")->assertSessionHas('success');
        $this->assertSame('resolved', $conversation->fresh()->handling);
        $this->authorizeReply($token)->assertJsonPath('control.ai_allowed', false);

        $this->report($token, ['external_id' => 'wamid.AGAIN'])->assertCreated()->assertJsonPath('control.mode', 'ai')->assertJsonPath('control.ai_allowed', true);
        $conversation->refresh();
        $this->assertSame('ai', $conversation->handling);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->resolved_at);
    }

    public function test_two_agents_cannot_hold_the_same_conversation(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $first = $this->member($workspace, 'agente');
        $second = $this->member($workspace, 'agente');

        $this->signIn($first, $workspace)->post("/conversations/{$conversation->id}/take")->assertSessionHas('success');
        $this->signIn($second, $workspace)->post("/conversations/{$conversation->id}/take")->assertSessionHas('error');
        $this->post("/conversations/{$conversation->id}/release")->assertSessionHas('error');
        $this->post("/conversations/{$conversation->id}/resolve")->assertSessionHas('error');

        $this->assertSame($first->id, $conversation->fresh()->assigned_user_id);
        $this->assertSame('human', $conversation->fresh()->handling);
    }

    // --- assigning ------------------------------------------------------------------------------------------------

    public function test_a_manager_assigns_to_an_agent_of_the_workspace_and_only_to_one(): void
    {
        $workspace = $this->workspace('WS_A');
        $other = $this->workspace('WS_B');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $manager = $this->member($workspace, 'admin');
        $agent = $this->member($workspace, 'agente');
        $reader = $this->member($workspace, 'supervisor');
        $foreign = $this->member($other, 'agente');

        $this->signIn($manager, $workspace);
        $this->post("/conversations/{$conversation->id}/assign", ['user_id' => $reader->id])->assertSessionHas('error');
        $this->post("/conversations/{$conversation->id}/assign", ['user_id' => $foreign->id])->assertSessionHas('error');
        $this->assertSame('ai', $conversation->fresh()->handling);

        $this->post("/conversations/{$conversation->id}/assign", ['user_id' => $agent->id])->assertSessionHas('success');
        $this->assertSame($agent->id, $conversation->fresh()->assigned_user_id);

        // A manager may also take it from the agent, or give it back for them.
        $this->post("/conversations/{$conversation->id}/release")->assertSessionHas('success');
        $this->assertSame('ai', $conversation->fresh()->handling);
    }

    public function test_an_agent_cannot_assign_and_a_reader_cannot_do_anything(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-conversations']);
        $reader = $this->member($workspace, 'lector');

        $this->signIn($agent, $workspace)->post("/conversations/{$conversation->id}/assign", ['user_id' => $agent->id])->assertForbidden();

        $this->signIn($reader, $workspace);
        $this->get('/conversations')->assertOk();
        foreach (['take', 'release', 'resolve', 'assign'] as $action) {
            $this->post("/conversations/{$conversation->id}/{$action}")->assertForbidden();
        }
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'hola'])->assertForbidden();
        $this->assertSame('ai', $conversation->fresh()->handling);
    }

    public function test_without_the_view_permission_nothing_is_reachable(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $this->seedRoleCatalog();
        Role::findOrCreate('solo-responder', 'web')->syncPermissions(['reply-conversations']);
        $user = $this->member($workspace, 'solo-responder');

        $this->signIn($user, $workspace);
        $this->get('/conversations')->assertForbidden();
        $this->post("/conversations/{$conversation->id}/take")->assertForbidden();
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'hola'])->assertForbidden();
    }

    // --- writing to the contact -----------------------------------------------------------------------------------

    public function test_the_agent_writes_and_the_message_reaches_whatsapp_in_the_same_conversation(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $token] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $this->metaAccepts();

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => '  Hola María, te ayudo yo.  '])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $message = Message::where('sender', 'agent')->firstOrFail();
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('Hola María, te ayudo yo.', $message->body);
        $this->assertSame('out', $message->direction);
        $this->assertSame($agent->id, $message->sender_user_id);
        $this->assertSame('sent', $message->status);
        $this->assertSame('wamid.AGENT1', $message->external_id);
        $this->assertSame(1, Conversation::count());

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/100200300/messages')
            && $request->hasHeader('Authorization', 'Bearer EAAB-SECRET-TOKEN')
            && $request['to'] === self::CONTACT && $request['type'] === 'text' && $request['text']['body'] === 'Hola María, te ayudo yo.');

        // The delivery states n8n reports from the channel's webhooks reach this same message.
        $this->withToken($token)->postJson('/api/agent/messages/status', ['channel' => 'whatsapp', 'contact_id' => self::CONTACT, 'external_id' => 'wamid.AGENT1', 'status' => 'delivered'])->assertOk();
        $this->assertSame('delivered', $message->fresh()->status);
    }

    public function test_a_refused_delivery_is_never_reported_as_sent(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Hola']);

        $message = Message::where('sender', 'agent')->firstOrFail();
        $this->assertSame('failed', $message->status);
        $this->assertNull($message->external_id);
        $this->assertStringContainsString('24 horas', $message->failure_reason);
        $this->assertStringNotContainsString('EAAB', $message->failure_reason);
    }

    public function test_a_message_waits_as_pending_until_the_channel_answers(): void
    {
        Bus::fake();
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Hola']);

        $this->assertSame('pending', Message::where('sender', 'agent')->firstOrFail()->status);
        Bus::assertDispatched(SendAgentMessage::class);
    }

    public function test_a_channel_without_a_way_to_send_says_so_and_stores_nothing(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $bot->channels()->where('channel', 'whatsapp')->update(['is_active' => false]);
        Http::fake();

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Hola'])->assertSessionHas('error');

        $this->assertSame(0, Message::where('sender', 'agent')->count());
        Http::assertNothingSent();
        $this->get("/conversations?c={$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.delivery.ok', false)->where('selected.can.reply', false));
    }

    public function test_only_the_agent_who_has_the_conversation_can_write_and_only_while_a_human_has_it(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $colleague = $this->member($workspace, 'agente');
        $this->metaAccepts();

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Sin tomar'])->assertSessionHas('error'); // the AI has it
        $this->post("/conversations/{$conversation->id}/take");
        $this->signIn($colleague, $workspace)->post("/conversations/{$conversation->id}/messages", ['body' => 'Ajeno'])->assertSessionHas('error');
        $this->signIn($agent, $workspace)->post("/conversations/{$conversation->id}/release");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Tarde'])->assertSessionHas('error'); // given back to the AI

        $this->assertSame(0, Message::where('sender', 'agent')->count());
        Http::assertNothingSent();
    }

    public function test_the_text_is_validated(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $this->signIn($agent, $workspace)->post("/conversations/{$conversation->id}/take");

        $this->post("/conversations/{$conversation->id}/messages", ['body' => ''])->assertSessionHasErrors('body');
        $this->post("/conversations/{$conversation->id}/messages", ['body' => str_repeat('a', 4097)])->assertSessionHasErrors('body');
        $this->post("/conversations/{$conversation->id}/messages", ['body' => '   '])->assertSessionHasErrors('body');
        $this->assertSame(0, Message::where('sender', 'agent')->count());
    }

    // --- the web widget -------------------------------------------------------------------------------------------

    public function test_the_widget_records_the_conversation_and_the_ai_answers_while_it_has_it(): void
    {
        [$bot, $key] = $this->liveWidget($this->workspace('WS_A'));
        Http::fake([self::HOOK => Http::response(['reply' => '¡Hola!'])]);

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => 'Hola'])
            ->assertOk()->assertExactJson(['reply' => '¡Hola!']);

        $conversation = Conversation::where('channel', 'web')->firstOrFail();
        $this->assertSame('visitor-1234', $conversation->contact_id);
        $this->assertSame(['contact', 'ai'], $conversation->messages()->orderBy('id')->pluck('sender')->all());
    }

    public function test_the_widget_does_not_call_the_ai_while_a_person_has_the_conversation(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $key] = $this->liveWidget($workspace);
        $agent = $this->member($workspace, 'agente');
        Http::fake([self::HOOK => Http::response(['reply' => 'respuesta de la IA'])]);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => 'Hola'])->assertOk();
        $conversation = Conversation::where('channel', 'web')->firstOrFail();
        $this->signIn($agent, $workspace)->post("/conversations/{$conversation->id}/take");
        Http::fake();

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => '¿Hay alguien?'])
            ->assertOk()->assertExactJson(['reply' => null, 'handling' => 'human']);

        Http::assertNothingSent();
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'body' => '¿Hay alguien?', 'sender' => 'contact']);
    }

    public function test_an_ai_reply_that_was_being_prepared_is_dropped_if_an_agent_took_over(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $key] = $this->liveWidget($workspace);
        $agent = $this->member($workspace, 'agente');
        Http::fake([self::HOOK => Http::response(['reply' => 'primera respuesta'])]);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => 'Hola'])->assertOk();
        $conversation = Conversation::where('channel', 'web')->firstOrFail();

        // While n8n is "thinking", the agent takes the conversation.
        Http::fake([self::HOOK => function () use ($conversation, $agent) {
            app(ConversationControl::class)->take($conversation, $agent);

            return Http::response(['reply' => 'respuesta tardía de la IA']);
        }]);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => 'Segunda pregunta'])
            ->assertOk()->assertExactJson(['reply' => null, 'handling' => 'human']);

        $this->assertDatabaseMissing('messages', ['body' => 'respuesta tardía de la IA']);
        $this->assertSame('human', $conversation->fresh()->handling);
    }

    public function test_the_assistant_can_ask_for_a_person_through_the_widget_reply(): void
    {
        [$bot, $key] = $this->liveWidget($this->workspace('WS_A'));
        Http::fake([self::HOOK => Http::response(['reply' => 'Te paso con un agente.', 'handoff' => true])]);

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => 'Quiero hablar con alguien'])
            ->assertOk()->assertExactJson(['reply' => 'Te paso con un agente.', 'handling' => 'pending']);

        $this->assertSame('pending', Conversation::where('channel', 'web')->firstOrFail()->handling);
    }

    public function test_an_agent_message_reaches_the_visitor_once(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $key] = $this->liveWidget($workspace);
        $agent = $this->member($workspace, 'agente');
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-1234', 'message' => 'Hola'])->assertOk();
        $conversation = Conversation::where('channel', 'web')->firstOrFail();

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Soy una persona'])->assertSessionMissing('error');
        $this->assertSame('sent', Message::where('sender', 'agent')->firstOrFail()->status);

        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-1234")
            ->assertOk()->assertJsonPath('handling', 'human')->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.text', 'Soy una persona');
        $this->assertSame('delivered', Message::where('sender', 'agent')->firstOrFail()->status);
        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-1234")->assertOk()->assertJsonCount(0, 'messages');
        // Another session sees nothing of it.
        $this->getJson("/api/widget/{$key}/messages?session_id=visitor-9999")->assertOk()->assertJsonCount(0, 'messages')->assertJsonPath('handling', 'ai');
        $this->getJson("/api/widget/{$key}/messages")->assertUnprocessable();
    }

    // --- the inbox ------------------------------------------------------------------------------------------------

    public function test_the_inbox_lists_filters_and_counts_by_who_answers(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $a = $this->conversation($bot, '573000000001');
        $b = $this->conversation($bot, '573000000002');
        $c = $this->conversation($bot, '573000000003');
        $b->forceFill(['handling' => 'pending'])->save();
        $c->forceFill(['handling' => 'resolved'])->save();
        $admin = $this->member($workspace, 'admin');

        $this->signIn($admin, $workspace);
        $this->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Conversations/Index')->where('channel', null)->has('conversations', 3)
            ->where('counts', ['ai' => 1, 'pending' => 1, 'human' => 0, 'resolved' => 1])->where('filters.status', 'all'));
        $this->get('/conversations?status=pending')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversations', 1)->where('conversations.0.contactId', '573000000002')->where('conversations.0.channel.label', 'WhatsApp')->where('filters.status', 'pending'));
        $this->get('/conversations?q=0003')->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 1)->where('conversations.0.id', $c->id));
        $this->get('/conversations?status=nope&channel=nope&chatbot=abc')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 3));
        // The page of one channel is the same module, limited to it.
        $this->get("/chatbots/{$bot->id}/channels/whatsapp/conversations?status=ai")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channel.key', 'whatsapp')->has('conversations', 1)->where('conversations.0.id', $a->id));
    }

    public function test_the_selected_conversation_tells_what_the_user_can_do(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $admin = $this->member($workspace, 'admin');

        $this->signIn($agent, $workspace)->get("/conversations?c={$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.handling', 'ai')->where('selected.can', ['take' => true, 'reply' => false, 'release' => false, 'resolve' => true, 'assign' => false])->where('agents', []));

        $this->post("/conversations/{$conversation->id}/take");
        $this->get("/conversations?c={$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.assignedUser.isMe', true)->where('selected.can', ['take' => false, 'reply' => true, 'release' => true, 'resolve' => true, 'assign' => false]));

        $this->signIn($admin, $workspace)->get("/conversations?c={$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.assignedUser.isMe', false)->where('selected.can.assign', true)->where('selected.can.reply', true)->has('agents', 2));
    }

    // --- isolation between Workspaces -----------------------------------------------------------------------------

    public function test_nothing_of_another_workspace_can_be_read_or_changed(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        [$botA, $tokenA] = $this->liveBot($a, 'Bot A');
        [$botB] = $this->liveBot($b, 'Bot B');
        $mine = $this->conversation($botA, '573000000001');
        $theirs = $this->conversation($botB, '573000000002');
        $admin = $this->member($a, 'admin');
        $agentOfB = $this->member($b, 'agente');

        $this->signIn($admin, $a);
        $this->get("/conversations?c={$theirs->id}")->assertNotFound();
        $this->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 1)->where('conversations.0.id', $mine->id));
        foreach (['take', 'release', 'resolve'] as $action) {
            $this->post("/conversations/{$theirs->id}/{$action}")->assertNotFound();
        }
        $this->post("/conversations/{$theirs->id}/assign", ['user_id' => $admin->id])->assertNotFound();
        $this->post("/conversations/{$theirs->id}/messages", ['body' => 'hola'])->assertNotFound();
        $this->post("/conversations/{$mine->id}/assign", ['user_id' => $agentOfB->id])->assertSessionHas('error');

        // The token of A cannot ask about, or ask a person for, a contact of B: it only knows its own chatbot's contacts.
        $this->authorizeReply($tokenA, ['contact_id' => '573000000002'])->assertJsonPath('control.mode', 'ai');
        $this->assertSame('ai', $theirs->fresh()->handling);
        $this->assertSame(0, $theirs->messages()->where('sender', 'agent')->count());
    }

    public function test_a_platform_administrator_looking_at_another_workspace_cannot_act_on_it(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $dev = $this->workspace('DESARROLLO_DEV');
        $client = $this->workspace('EMPRESA_ABC');
        [$bot] = $this->liveBot($client);
        $conversation = $this->conversation($bot);
        $admin = $this->member($dev, 'admin');
        $admin->forceFill(['is_superuser' => true])->save();

        $this->signIn($admin, $dev);
        $this->get("/conversations?workspace={$client->id}&c={$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.readOnly', true)->where('selected.can', ['take' => false, 'reply' => false, 'release' => false, 'resolve' => false, 'assign' => false])->where('agents', []));
        $this->post("/conversations/{$conversation->id}/take")->assertNotFound();
        $this->assertSame('ai', $conversation->fresh()->handling);
    }

    // --- audit ----------------------------------------------------------------------------------------------------

    public function test_every_change_of_control_is_audited_with_who_and_what_changed(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveBot($workspace);
        $conversation = $this->conversation($bot);
        $agent = $this->member($workspace, 'agente');
        $admin = $this->member($workspace, 'admin');

        $this->signIn($agent, $workspace);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/release");
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/resolve");
        $this->signIn($admin, $workspace);
        $this->post("/conversations/{$conversation->id}/take")->assertSessionHas('error'); // resolved: nothing recorded
        $conversation->forceFill(['handling' => 'ai'])->save();
        $this->post("/conversations/{$conversation->id}/assign", ['user_id' => $agent->id]);

        $this->assertSame(['taken', 'returned', 'taken', 'resolved', 'assigned'], AuditLog::where('resource_type', 'conversation')->orderBy('id')->pluck('action')->all());
        $log = AuditLog::where('action', 'taken')->firstOrFail();
        $this->assertSame($agent->email, $log->user_email);
        $this->assertSame($workspace->id, $log->workspace_id);
        $this->assertSame('María', $log->resource_label);
        $this->assertContains(['field' => 'Agente', 'before' => null, 'after' => $agent->name], $log->changes);
        $this->assertStringContainsString('Tomó la conversación', $log->description);
        $this->assertSame('Devolvió a la IA la conversación María', AuditLog::where('action', 'returned')->firstOrFail()->description);
    }
}
