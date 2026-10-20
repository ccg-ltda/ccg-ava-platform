<?php

namespace Tests\Feature;

use App\Jobs\SendAgentMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ChannelDelivery;
use App\Services\ConversationInbox;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A plan with Conversaciones but without Asistentes is a consultation plan: people read, take, assign and resolve the
 * conversations that exist, but nothing that needs an assistant works (answering, giving a conversation back to the AI, the
 * agent API, the widget, the webhook, the queued executions). Asistentes still needs Conversaciones.
 */
class ConsultationPlanTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $client;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $organization = Organization::create(['name' => 'CCG']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_UNO', 'name' => 'Cliente Uno']);
        $this->agent = User::factory()->create();
        $this->client->users()->attach($this->agent->id, ['role' => 'admin']);
        $this->actingAs($this->agent)->withSession(['workspace_id' => $this->client->id]);
    }

    /** @param  list<string>  $keys */
    private function contract(array $keys): void
    {
        $plan = Plan::create(['name' => 'Plan '.Plan::count()]);
        $plan->modules()->sync(Module::whereIn('key', $keys)->pluck('id'));
        $this->client->update(['plan_id' => $plan->id]);
    }

    private function conversation(string $handling = 'ai'): Conversation
    {
        $bot = $this->client->chatbots()->first() ?? $this->client->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $conversation = $bot->conversations()->create(['workspace_id' => $this->client->id, 'channel' => 'web', 'contact_id' => 'visitor-'.Conversation::count(), 'contact_name' => 'Visitante']);
        $conversation->forceFill(['handling' => $handling, 'last_message_at' => now()])->save();
        $conversation->messages()->create(['workspace_id' => $this->client->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => now()]);

        return $conversation;
    }

    private function abilities(Conversation $conversation): array
    {
        return app(ConversationInbox::class)->detail($conversation->fresh(), $this->client->settingsOrDefault(), $this->agent, ['take-conversations', 'reply-conversations', 'release-conversations', 'resolve-conversations', 'manage-conversations', 'view-conversations'], false);
    }

    public function test_the_combination_is_allowed_but_assistants_still_need_conversations(): void
    {
        $access = app(ModuleAccess::class);

        $this->contract(['conversations', 'dashboard']);
        $this->assertEqualsCanonicalizing(['conversations', 'dashboard'], $access->keys($this->client->fresh()));

        $this->contract(['assistants', 'dashboard']);
        $this->assertSame(['dashboard'], $access->keys($this->client->fresh()));
    }

    public function test_a_consultation_plan_can_read_take_assign_and_resolve(): void
    {
        $this->contract(['conversations']);
        $conversation = $this->conversation();

        $this->get('/conversations')->assertOk();
        $this->post("/conversations/{$conversation->id}/take")->assertSessionHas('success');
        $this->assertSame('human', $conversation->fresh()->handling);
        $colleague = User::factory()->create();
        $this->client->users()->attach($colleague->id, ['role' => 'agente']);
        $this->post("/conversations/{$conversation->id}/assign", ['user_id' => $colleague->id])->assertSessionHas('success');
        $this->assertSame($colleague->id, $conversation->fresh()->assigned_user_id);
        $this->post("/conversations/{$conversation->id}/resolve")->assertSessionHas('success');
        $this->assertSame('resolved', $conversation->fresh()->handling);
    }

    public function test_a_consultation_plan_cannot_send_messages_and_nothing_is_queued_or_sent(): void
    {
        $this->contract(['conversations']);
        Queue::fake();
        Http::fake();
        $conversation = $this->conversation('human');
        $conversation->forceFill(['assigned_user_id' => $this->agent->id])->save();

        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Hola, soy una persona'])->assertSessionHas('error');

        $this->assertSame(0, $conversation->messages()->where('direction', 'out')->count());
        Queue::assertNotPushed(SendAgentMessage::class);
        Http::assertNothingSent();

        $page = $this->abilities($conversation);
        $this->assertFalse($page['delivery']['ok']);
        $this->assertFalse($page['can']['reply']);
    }

    public function test_a_consultation_plan_cannot_give_a_conversation_back_to_the_ai(): void
    {
        $this->contract(['conversations']);
        $conversation = $this->conversation('human');
        $conversation->forceFill(['assigned_user_id' => $this->agent->id])->save();

        $this->assertFalse($this->abilities($conversation)['can']['release']);
        $this->post("/conversations/{$conversation->id}/release")->assertSessionHas('error');
        $this->assertSame('human', $conversation->fresh()->handling);

        $pending = $this->conversation('pending');
        $this->post("/conversations/{$pending->id}/release")->assertSessionHas('error');
        $this->assertSame('pending', $pending->fresh()->handling);
    }

    public function test_with_assistants_and_conversations_the_same_actions_are_allowed(): void
    {
        $this->contract(['assistants', 'conversations']);
        $conversation = $this->conversation('human');
        $conversation->forceFill(['assigned_user_id' => $this->agent->id])->save();

        $this->assertTrue($this->abilities($conversation)['can']['release']);
        $this->post("/conversations/{$conversation->id}/release")->assertSessionHas('success');
        $this->assertSame('ai', $conversation->fresh()->handling);
    }

    public function test_a_queued_message_is_not_delivered_when_the_plan_has_no_assistants(): void
    {
        $this->contract(['conversations']);
        Http::fake();
        $conversation = $this->conversation('human');
        $message = $conversation->messages()->create(['workspace_id' => $this->client->id, 'direction' => 'out', 'sender' => 'agent', 'sender_user_id' => $this->agent->id, 'type' => 'text', 'body' => 'Pendiente', 'status' => Message::PENDING, 'sent_at' => now()]);

        app(ChannelDelivery::class)->deliver($message);

        $this->assertSame('failed', $message->fresh()->status);
        $this->assertStringContainsString('Asistentes', $message->fresh()->failure_reason);
        Http::assertNothingSent();
    }

    public function test_the_assistants_pages_the_agent_api_and_the_public_widget_stay_closed(): void
    {
        $this->contract(['conversations']);
        $bot = $this->client->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $token = app(AgentAccess::class)->generate($bot);

        $this->get('/chatbots')->assertForbidden();
        $this->get("/chatbots/{$bot->id}")->assertForbidden();
        $this->get("/chatbots/{$bot->id}/channels/web/conversations")->assertForbidden();
        $this->post("/chatbots/{$bot->id}/agent-token")->assertForbidden();

        $headers = ['Authorization' => "Bearer {$token}"];
        $this->getJson('/api/agent/config', $headers)->assertForbidden();
        $this->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '1', 'direction' => 'in', 'text' => 'x', 'message_id' => 'm1'], $headers)->assertForbidden();
        $this->postJson('/api/agent/conversations/authorize', [], $headers)->assertForbidden();
        $this->postJson('/api/agent/conversations/handoff', [], $headers)->assertForbidden();
        $this->assertSame(0, $this->client->conversations()->count());
    }
}
