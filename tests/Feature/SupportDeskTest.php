<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SupportDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The support of the administrators of a client Workspace: it lands in Conversaciones of the administrative Workspace (DESARROLLO_DEV), the destination is decided by the
 * server, only the role `admin` of the active Workspace can use it, and a client can never read or write anything that is not its own. Nothing leaves the application.
 */
class SupportDeskTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Mail::fake();
        Notification::fake();
        $this->seedRoleCatalog();
        $organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations']);
    }

    private function as(Workspace $workspace, string $role = 'admin', ?User $user = null): User
    {
        $user ??= $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function write(string $text = 'Necesito ayuda con mi plan', ?string $id = null, array $extra = [])
    {
        static $n = 0;

        return $this->postJson('/support/messages', ['message' => $text, 'message_id' => $id ?? 'msg-'.str_pad((string) ++$n, 8, '0', STR_PAD_LEFT)] + $extra);
    }

    /** The conversation of this person in the administrative Workspace. */
    private function requestOf(User $user, Workspace $workspace): ?Conversation
    {
        return Conversation::where('workspace_id', $this->dev->id)->where('contact_id', SupportDesk::contactId($user, $workspace))->first();
    }

    // --- who may use it -------------------------------------------------------------------------------------------

    public function test_an_administrator_opens_a_request_that_lands_in_the_administrative_workspace_waiting_for_a_person(): void
    {
        $admin = $this->as($this->a);

        $this->write('Necesito ayuda con mi plan')->assertOk()->assertJsonPath('duplicate', false)->assertJsonPath('state', 'waiting')->assertJsonCount(1, 'messages');

        $conversation = $this->requestOf($admin, $this->a);
        $this->assertNotNull($conversation);
        $this->assertSame([$this->dev->id, 'web', Conversation::PENDING], [$conversation->workspace_id, $conversation->channel, $conversation->handling]);
        $this->assertSame("Salón CLIENTE_A · {$admin->name}", $conversation->contact_name);
        $this->assertSame('Soporte Zonno', $conversation->chatbot->name);
        $this->assertFalse((bool) $conversation->chatbot->is_active, 'the desk never answers with AI');
        $this->assertSame(['contact'], $conversation->messages()->pluck('sender')->all());
        $this->assertSame(0, Conversation::where('workspace_id', $this->a->id)->count(), 'nothing is kept in the client Workspace');
        $this->assertNull($conversation->assigned_user_id, 'nobody has taken it: the person is told it is only waiting');
    }

    public function test_only_the_role_administrator_of_a_client_workspace_can_use_it_and_the_server_refuses_everybody_else(): void
    {
        foreach (['cliente', 'agente', 'supervisor', 'soporte'] as $role) {
            $this->as($this->a, $role);
            $this->getJson('/support/thread')->assertForbidden();
            $this->write()->assertForbidden();
        }

        // CCG's own team answers, it does not ask: the administrator of the administrative Workspace has no panel.
        $this->as($this->dev, 'admin');
        $this->getJson('/support/thread')->assertForbidden();
        $this->write()->assertForbidden();

        auth()->logout();
        $this->flushSession();
        $this->getJson('/support/thread')->assertUnauthorized();
        $this->write()->assertUnauthorized();

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Chatbot::where('workspace_id', $this->dev->id)->count(), 'a refused request creates nothing, not even the chatbot');
    }

    public function test_the_button_is_drawn_only_for_administrators_and_a_demoted_user_loses_the_access(): void
    {
        $admin = $this->as($this->a);
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('supportDesk.state', 'ready')->where('help.chat', true)->where('help.chatState', 'ready'));

        $this->as($this->a, 'cliente');
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('supportDesk', null)->where('help.chat', false)->where('help.chatState', 'restricted'));

        // The same person, when they stop being an administrator of that Workspace, can no longer reach the endpoints.
        $this->a->users()->updateExistingPivot($admin->id, ['role' => 'cliente']);
        $this->as($this->a, 'cliente', $admin);
        $this->getJson('/support/thread')->assertForbidden();
        $this->write()->assertForbidden();

        $this->as($this->dev, 'admin');
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('supportDesk', null)->where('help.chatState', 'internal'));
    }

    public function test_the_workspace_the_person_and_the_conversation_are_never_taken_from_the_request(): void
    {
        $admin = $this->as($this->a);
        $other = $this->member($this->b);
        $this->write('Hola', null, ['workspace_id' => $this->b->id, 'user_id' => $other->id, 'contact_id' => 'c'.$this->b->id.'u'.$other->id, 'conversation_id' => 999, 'chatbot_id' => 1, 'destination' => $this->a->id])->assertOk();

        $this->assertNotNull($this->requestOf($admin, $this->a));
        $this->assertNull($this->requestOf($other, $this->b));
        $this->assertSame(1, Conversation::count());
        $this->assertSame($this->dev->id, Conversation::first()->workspace_id);
    }

    public function test_the_message_is_validated(): void
    {
        $this->as($this->a);

        $this->postJson('/support/messages', ['message_id' => 'msg-00000001'])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/support/messages', ['message' => '   ', 'message_id' => 'msg-00000001'])->assertStatus(422);
        $this->postJson('/support/messages', ['message' => str_repeat('a', 2001), 'message_id' => 'msg-00000001'])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postJson('/support/messages', ['message' => 'Hola', 'message_id' => 'x'])->assertStatus(422)->assertJsonValidationErrors('message_id');
        $this->postJson('/support/messages', ['message' => 'Hola'])->assertStatus(422);
        $this->assertSame(0, Conversation::count());
    }

    // --- isolation ---------------------------------------------------------------------------------------------------

    public function test_two_client_workspaces_never_see_each_others_messages(): void
    {
        $adminA = $this->as($this->a);
        $this->write('Secreto de A: factura 123')->assertOk();
        $adminB = $this->as($this->b);
        $this->write('Secreto de B: contrato 456')->assertOk();

        $threadB = $this->getJson('/support/thread')->assertOk();
        $this->assertStringContainsString('Secreto de B', $threadB->getContent());
        $this->assertStringNotContainsString('Secreto de A', $threadB->getContent());

        $this->as($this->a, 'admin', $adminA);
        $threadA = $this->getJson('/support/thread')->assertOk();
        $this->assertStringContainsString('Secreto de A', $threadA->getContent());
        $this->assertStringNotContainsString('Secreto de B', $threadA->getContent());

        $this->assertNotSame($this->requestOf($adminA, $this->a)->id, $this->requestOf($adminB, $this->b)->id);
        // The thread has no parameter to choose a conversation: whatever is sent, the answer is the session's own.
        $this->getJson('/support/thread?conversation_id='.$this->requestOf($adminB, $this->b)->id.'&workspace_id='.$this->b->id)->assertOk()->assertJsonMissing(['text' => 'Secreto de B: contrato 456']);
    }

    public function test_two_administrators_of_the_same_workspace_have_their_own_conversation(): void
    {
        $first = $this->as($this->a);
        $this->write('Pregunta del primero')->assertOk();
        $second = $this->as($this->a);
        $this->write('Pregunta del segundo')->assertOk();

        $this->assertSame(2, Conversation::count());
        $this->assertStringNotContainsString('primero', $this->getJson('/support/thread')->getContent());
        $this->as($this->a, 'admin', $first);
        $this->assertStringNotContainsString('segundo', $this->getJson('/support/thread')->getContent());
        $this->assertNotNull($this->requestOf($second, $this->a));
    }

    public function test_a_client_cannot_reach_the_internal_inbox(): void
    {
        $admin = $this->as($this->a);
        $this->write()->assertOk();
        $conversation = $this->requestOf($admin, $this->a);

        $this->get('/conversations')->assertOk()->assertInertia(fn ($page) => $page->where('conversations', []));
        $this->post("/conversations/{$conversation->id}/take")->assertNotFound();
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Intruso'])->assertNotFound();
        $this->assertSame(0, Message::where('sender', 'agent')->count());
    }

    // --- the team answers in Conversaciones ----------------------------------------------------------------------

    public function test_the_team_finds_the_request_in_conversaciones_knows_where_it_came_from_and_the_answer_reaches_only_that_person(): void
    {
        $adminA = $this->as($this->a);
        $this->write('Necesito ayuda con mi plan')->assertOk();
        $adminB = $this->as($this->b);
        $this->write('Otra consulta')->assertOk();
        $conversation = $this->requestOf($adminA, $this->a);

        $agent = $this->as($this->dev, 'agente');
        $this->get('/conversations')->assertOk()->assertInertia(fn ($page) => $page->has('conversations', 2)
            ->where('conversations.1.support.workspaceName', 'Salón CLIENTE_A')->where('conversations.1.support.workspaceCode', 'CLIENTE_A')->where('conversations.1.support.userName', $adminA->name)
            ->where('conversations.1.handling', 'pending')->where('conversations.1.channel.key', 'support')->where('conversations.1.channel.label', 'Soporte'));
        $this->get("/conversations?c={$conversation->id}")->assertInertia(fn ($page) => $page->where('selected.support.userName', $adminA->name)->where('selected.channel.key', 'support')->where('selected.can.release', false)->where('selected.delivery.ok', true));

        $this->post("/conversations/{$conversation->id}/take")->assertSessionHasNoErrors();
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Hola, te ayudo con tu plan'])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $reply = Message::where('sender', 'agent')->firstOrFail();
        $this->assertSame([$conversation->id, $agent->id, 'sent'], [$reply->conversation_id, $reply->sender_user_id, $reply->status], 'delivered to the thread, not failed');

        $this->as($this->a, 'admin', $adminA);
        $thread = $this->getJson('/support/thread')->assertOk()->assertJsonPath('state', 'attending')->assertJsonPath('agent', $agent->name);
        $this->assertSame([true, false], collect($thread->json('messages'))->pluck('mine')->all());
        $this->assertSame('Hola, te ayudo con tu plan', $thread->json('messages.1.text'));
        $this->assertSame($agent->name, $thread->json('messages.1.author'));
        $this->assertSame('delivered', $reply->fresh()->status, 'reading it in the panel marks it delivered');

        $this->as($this->b, 'admin', $adminB);
        $other = $this->getJson('/support/thread')->assertOk()->assertJsonPath('state', 'waiting');
        $this->assertStringNotContainsString('te ayudo', $other->getContent());
    }

    public function test_resolving_and_writing_again_reuses_the_conversation_and_reopens_it_for_a_person(): void
    {
        $admin = $this->as($this->a);
        $this->write('Primera consulta')->assertOk();
        $conversation = $this->requestOf($admin, $this->a);

        $this->as($this->dev, 'agente');
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/resolve")->assertSessionHasNoErrors();

        $this->as($this->a, 'admin', $admin);
        $this->getJson('/support/thread')->assertJsonPath('state', 'resolved');
        $this->write('Segunda consulta')->assertOk()->assertJsonPath('state', 'waiting');

        $this->assertSame(1, Conversation::count(), 'no duplicate conversation');
        $this->assertSame([Conversation::PENDING, 2], [$conversation->fresh()->handling, $conversation->messages()->where('sender', 'contact')->count()]);
        $this->assertNull($conversation->fresh()->assigned_user_id, 'it is waiting for a person again, not «attended»');
    }

    public function test_a_repeated_request_is_stored_once(): void
    {
        $this->as($this->a);
        $this->write('Hola', 'same-message-1')->assertOk()->assertJsonPath('duplicate', false);
        $this->write('Hola', 'same-message-1')->assertOk()->assertJsonPath('duplicate', true)->assertJsonCount(1, 'messages');

        $this->assertSame([1, 1], [Conversation::count(), Message::count()]);
    }

    // --- destination and availability ----------------------------------------------------------------------------

    public function test_without_the_administrative_workspace_the_panel_says_it_is_unavailable_and_nothing_is_created_elsewhere(): void
    {
        $this->dev->update(['is_active' => false]);
        $this->as($this->a);

        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('supportDesk.state', 'unavailable')->where('help.chatState', 'unavailable'));
        $this->write()->assertStatus(422)->assertJsonValidationErrors('message');
        $this->getJson('/support/thread')->assertOk()->assertJsonPath('state', 'none');
        $this->assertSame(0, Conversation::count());
    }

    public function test_when_the_public_chat_is_configured_and_live_its_chatbot_receives_the_requests(): void
    {
        $this->dev->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => 'https://n8n.example.com/webhook/x', 'allowed_origins' => []], 'secrets' => []]);
        $bot = $this->dev->chatbots()->create(['name' => 'Chat público de CCG']);
        $this->as($this->dev, 'admin')->forceFill(['is_superuser' => true])->save();
        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true]);
        $key = ChatbotChannel::where('chatbot_id', $bot->id)->value('public_key');
        config(['support.public_chat_key' => $key]);
        auth()->logout();

        $admin = $this->as($this->a);
        $this->write()->assertOk();

        $this->assertSame($bot->id, $this->requestOf($admin, $this->a)->chatbot_id, 'one conversation per person across the public widget and this panel');
        $this->assertSame(0, Chatbot::where('name', 'Soporte Zonno')->count());
    }

    public function test_a_public_visitor_can_neither_create_nor_read_a_support_conversation_and_stays_a_web_conversation(): void
    {
        $admin = $this->as($this->a);
        $this->write('Mensaje del cliente')->assertOk();
        $key = SupportDesk::contactId($admin, $this->a);
        $bot = $this->requestOf($admin, $this->a)->chatbot;
        $this->dev->integrations()->create(['name' => 'Web', 'type' => 'web', 'is_active' => true, 'config' => ['webhook_url' => 'https://n8n.example.com/webhook/x', 'allowed_origins' => []], 'secrets' => []]);
        $channel = $bot->channels()->create(['workspace_id' => $this->dev->id, 'channel' => 'web', 'integration_id' => $this->dev->integrations()->first()->id, 'is_active' => true, 'public_key' => bin2hex(random_bytes(16))]);
        $bot->forceFill(['is_active' => true])->save();

        // The reserved key cannot be used as a visitor's session id, neither to write nor to poll somebody else's replies.
        $this->postJson("/api/widget/{$channel->public_key}/messages", ['session_id' => $key.'-visitor', 'message' => 'ok-not-reserved'])->assertStatus(200);
        $this->postJson("/api/widget/{$channel->public_key}/messages", ['session_id' => 'c99999u99999', 'message' => 'Me hago pasar por soporte'])->assertStatus(422);
        $this->getJson("/api/widget/{$channel->public_key}/messages?session_id={$key}")->assertStatus(422);

        // An anonymous visitor's conversation in the same inbox is Web, never Soporte.
        $agent = $this->as($this->dev, 'agente');
        $this->get('/conversations')->assertInertia(fn ($page) => $page->where('conversations', fn ($items) => collect($items)->firstWhere('contactId', $key.'-visitor')['channel']['key'] === 'web' && collect($items)->firstWhere('contactId', $key)['channel']['key'] === 'support'));
        $this->assertNotNull($agent);
    }

    public function test_nothing_is_sent_outside_the_application(): void
    {
        $admin = $this->as($this->a);
        $this->write()->assertOk();
        $this->as($this->dev, 'agente');
        $conversation = $this->requestOf($admin, $this->a);
        $this->post("/conversations/{$conversation->id}/take");
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Respuesta']);

        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_the_role_name_used_for_the_check_is_the_system_administrator_role(): void
    {
        $this->assertSame('admin', SupportDesk::ADMIN_ROLE);
        $this->assertNotNull(Role::findByName(SupportDesk::ADMIN_ROLE, 'web'));
    }
}
