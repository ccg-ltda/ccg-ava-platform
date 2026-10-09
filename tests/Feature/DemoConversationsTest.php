<?php

namespace Tests\Feature;

use App\Exceptions\DemoNotAllowed;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DemoConversations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The demo environment of Conversations: generator, cleanup, guards and the simulator inside the inbox. */
class DemoConversationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        // No test may reach a provider: any request that is made fails the test.
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

    private function demo(): DemoConversations
    {
        return app(DemoConversations::class);
    }

    /** A real (not demo) conversation, to prove the demo tools never touch it. */
    private function realConversation(Workspace $workspace): Conversation
    {
        $bot = $workspace->chatbots()->create(['name' => 'Bot real']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Real', 'last_message_at' => now()]);
        $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola real', 'sent_at' => now()]);

        return $conversation;
    }

    // --- generator ------------------------------------------------------------------------------------------------

    public function test_the_generator_creates_marked_scenarios_for_every_state_and_channel(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $this->member($dev, 'admin');

        $result = $this->demo()->generate($dev);

        $this->assertSame(11, $result['created']);
        $demo = Conversation::whereNotNull('demo_key')->get();
        $this->assertCount(11, $demo);
        $this->assertEqualsCanonicalizing(['ai', 'pending', 'human', 'resolved'], $demo->pluck('handling')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['web', 'whatsapp'], $demo->pluck('channel')->unique()->all());
        $this->assertTrue($demo->every(fn (Conversation $c) => $c->workspace_id === $dev->id));
        // Every message is marked, and every outgoing state of the scenarios is represented.
        $this->assertSame(0, Message::where('simulated', false)->count());
        $this->assertEqualsCanonicalizing(['sent', 'delivered', 'read', 'failed'], Message::where('direction', 'out')->pluck('status')->unique()->values()->all());
        $this->assertGreaterThan(30, Conversation::where('demo_key', 'wa-long-history')->firstOrFail()->messages()->count());
        // Dates are spread over days.
        $this->assertGreaterThan(5, abs(now()->diffInDays(Conversation::min('last_message_at'))));
        $bot = Chatbot::firstOrFail();
        $this->assertTrue($bot->is_demo);
        $this->assertSame(0, $bot->channels()->count(), 'the demo chatbot has no channel and touches no integration');
        $this->assertSame(0, $dev->integrations()->count());
    }

    public function test_human_scenarios_are_assigned_to_a_real_agent_of_the_workspace_or_wait_as_pending(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');

        $this->assertSame(3, $this->demo()->generate($dev)['unassigned']);
        $this->assertSame(0, Conversation::where('handling', 'human')->count());
        $this->assertSame(0, Conversation::whereNotNull('assigned_user_id')->count());

        $this->demo()->clean($dev);
        $agent = $this->member($dev, 'agente');

        $this->assertSame(0, $this->demo()->generate($dev)['unassigned']);
        $humans = Conversation::where('handling', 'human')->get();
        $this->assertCount(3, $humans);
        $this->assertTrue($humans->every(fn (Conversation $c) => $c->assigned_user_id === $agent->id));
    }

    public function test_generating_twice_changes_nothing(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $this->member($dev, 'admin');
        $this->demo()->generate($dev);
        $before = [Conversation::count(), Message::count(), Chatbot::count()];

        $second = $this->demo()->generate($dev);

        $this->assertSame(['created' => 0, 'existing' => 11, 'unassigned' => 0], $second);
        $this->assertSame($before, [Conversation::count(), Message::count(), Chatbot::count()]);
    }

    public function test_cleaning_removes_only_demo_data_and_never_real_conversations(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $real = $this->realConversation($dev);
        $this->demo()->generate($dev);

        $removed = $this->demo()->clean($dev);

        $this->assertSame(11, $removed);
        $this->assertSame(0, Conversation::whereNotNull('demo_key')->count());
        $this->assertSame([$real->id], Conversation::pluck('id')->all());
        $this->assertSame(1, Message::count());
        $this->assertSame(['Bot real'], Chatbot::pluck('name')->all(), 'the demo chatbot goes, the real one stays');
        $this->assertSame(0, $this->demo()->clean($dev), 'cleaning twice is harmless');
    }

    public function test_the_demo_chatbot_stays_while_a_real_conversation_lives_on_it(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $this->demo()->generate($dev);
        $bot = Chatbot::firstOrFail();
        $real = $bot->conversations()->create(['workspace_id' => $dev->id, 'channel' => 'whatsapp', 'contact_id' => '573009990000', 'last_message_at' => now()]);

        $this->demo()->clean($dev);

        $this->assertTrue($real->fresh()->exists);
        $this->assertSame(1, Chatbot::count());
    }

    public function test_reset_rebuilds_the_scenarios_and_drops_what_was_simulated(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $this->demo()->generate($dev);
        $conversation = Conversation::where('demo_key', 'web-faq-ai')->firstOrFail();
        $conversation->messages()->create(['workspace_id' => $dev->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'extra', 'simulated' => true, 'sent_at' => now()]);

        $this->assertSame(11, $this->demo()->reset($dev)['created']);

        $this->assertSame(4, Conversation::where('demo_key', 'web-faq-ai')->firstOrFail()->messages()->count());
    }

    // --- guards ---------------------------------------------------------------------------------------------------

    public function test_only_the_administrative_workspace_can_hold_demo_data(): void
    {
        $client = $this->workspace('EMPRESA_ABC');
        $this->workspace('DESARROLLO_DEV');

        foreach (['generate', 'clean', 'reset'] as $action) {
            try {
                $this->demo()->{$action}($client);
                $this->fail("{$action} must refuse a client Workspace");
            } catch (DemoNotAllowed $e) {
                $this->assertStringContainsString('administrativo', $e->getMessage());
            }
        }

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Chatbot::count());
    }

    public function test_nothing_runs_in_production(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $this->demo()->generate($dev);
        $conversation = Conversation::where('demo_key', 'web-faq-ai')->firstOrFail();

        $this->app['env'] = 'production';
        $this->assertFalse($this->demo()->enabled());

        try {
            $this->demo()->generate($dev);
            $this->fail('generate must refuse production');
        } catch (DemoNotAllowed) {
            // expected
        }
        $this->assertSame(11, Conversation::count());

        // Production enforces CSRF, so the request carries a valid token and the 404 is the demo guard's own.
        $this->signIn($admin, $dev)->withSession(['workspace_id' => $dev->id, '_token' => 't'])->withHeader('X-CSRF-TOKEN', 't');
        foreach (['generate', 'reset', 'clean'] as $action) {
            $this->post("/conversations/demo/{$action}")->assertNotFound();
        }
        $this->post("/conversations/{$conversation->id}/simulate/incoming", ['body' => 'x'])->assertNotFound();
        $this->post("/conversations/{$conversation->id}/simulate/ai-reply")->assertNotFound();
        $this->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page->where('demo', null));
        $this->assertSame(11, Conversation::count());

        $this->artisan('demo:conversations', ['action' => 'clean'])->assertFailed();
        $this->assertSame(11, Conversation::count());
    }

    public function test_the_command_generates_cleans_and_validates(): void
    {
        $this->workspace('DESARROLLO_DEV');

        $this->artisan('demo:conversations', ['action' => 'generate'])->assertSuccessful();
        $this->assertSame(11, Conversation::count());
        $this->artisan('demo:conversations', ['action' => 'generate'])->assertSuccessful();
        $this->assertSame(11, Conversation::count());
        $this->artisan('demo:conversations', ['action' => 'reset'])->assertSuccessful();
        $this->assertSame(11, Conversation::count());
        $this->artisan('demo:conversations', ['action' => 'clean'])->assertSuccessful();
        $this->assertSame(0, Conversation::count());
        $this->artisan('demo:conversations', ['action' => 'bogus'])->assertFailed();
    }

    public function test_the_command_fails_clearly_without_the_administrative_workspace(): void
    {
        $this->artisan('demo:conversations', ['action' => 'generate'])->assertFailed();

        $this->assertSame(0, Conversation::count());
    }

    // --- the inbox ------------------------------------------------------------------------------------------------

    public function test_the_inbox_shows_demo_scenarios_with_counts_filters_and_markers(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $this->realConversation($dev);
        $this->demo()->generate($dev);

        $this->signIn($admin, $dev)->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('demo.canManage', true)->where('demo.demo', 11)->where('demo.total', 12)
            ->where('counts.pending', 2)->where('counts.human', 3)->where('counts.resolved', 2)
            ->has('conversations', 12)->where('conversations', fn ($rows) => collect($rows)->where('demo', true)->count() === 11));
        $this->get('/conversations?status=human')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversations', 3)->where('conversations.0.assignedName', $admin->name));
    }

    public function test_a_demo_conversation_is_labelled_and_a_real_one_is_not(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $real = $this->realConversation($dev);
        $this->demo()->generate($dev);
        $demo = Conversation::where('demo_key', 'wa-claim-human')->firstOrFail();

        $this->signIn($admin, $dev)->get("/conversations?c={$demo->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.demo', true)->where('selected.simulate', true)->where('selected.items.0.simulated', true)->where('selected.delivery.ok', true));
        $this->get("/conversations?c={$real->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.demo', false)->where('selected.simulate', false)->where('selected.items.0.simulated', false));
    }

    public function test_client_workspaces_and_unprivileged_users_get_no_demo_tools(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $client = $this->workspace('EMPRESA_ABC');
        $agent = $this->member($dev, 'agente');
        $clientAdmin = $this->member($client, 'admin');
        $this->demo()->generate($dev);
        $demo = Conversation::where('demo_key', 'web-faq-ai')->firstOrFail();

        // An agent (no manage-conversations) sees the panel without buttons and cannot call anything.
        $this->signIn($agent, $dev)->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page->where('demo.canManage', false));
        $this->post('/conversations/demo/clean')->assertForbidden();
        $this->post("/conversations/{$demo->id}/simulate/incoming", ['body' => 'x'])->assertForbidden();
        $this->get("/conversations?c={$demo->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('selected.simulate', false));

        // A client Workspace's admin: refused to generate (not administrative) and cannot reach the other Workspace's data.
        $this->signIn($clientAdmin, $client)->get('/conversations')->assertInertia(fn (AssertableInertia $page) => $page->where('demo.canManage', false)->has('conversations', 0));
        $this->post('/conversations/demo/generate')->assertSessionHas('error');
        $this->post('/conversations/demo/clean')->assertSessionHas('error');
        $this->post("/conversations/{$demo->id}/simulate/incoming", ['body' => 'x'])->assertNotFound();
        $this->assertSame(11, Conversation::count());
    }

    public function test_generate_reset_and_clean_work_from_the_interface(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $real = $this->realConversation($dev);

        $this->signIn($admin, $dev);
        $this->post('/conversations/demo/generate')->assertSessionHas('success');
        $this->assertSame(11, Conversation::whereNotNull('demo_key')->count());
        $this->post('/conversations/demo/reset')->assertSessionHas('success');
        $this->assertSame(11, Conversation::whereNotNull('demo_key')->count());
        $this->post('/conversations/demo/clean')->assertSessionHas('success');
        $this->assertSame([$real->id], Conversation::pluck('id')->all());
        $this->post('/conversations/demo/bogus')->assertNotFound();
    }

    // --- the simulator --------------------------------------------------------------------------------------------

    public function test_simulating_traffic_goes_through_the_real_rules_and_marks_everything_as_simulated(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $this->demo()->generate($dev);
        $conversation = Conversation::where('demo_key', 'web-only-incoming-ai')->firstOrFail();
        $this->signIn($admin, $dev);

        $this->post("/conversations/{$conversation->id}/simulate/incoming", ['body' => 'Hola, ¿alguien?'])->assertSessionHas('success');
        $this->post("/conversations/{$conversation->id}/simulate/ai-reply")->assertSessionHas('success');

        $last = $conversation->messages()->orderByDesc('id')->first();
        $this->assertSame(['contact', 'ai'], $conversation->messages()->orderByDesc('id')->limit(2)->pluck('sender')->reverse()->values()->all());
        $this->assertTrue($last->simulated);
        $this->assertStringContainsString('simulada', $last->body);

        // The AI is blocked exactly like a real automatic reply once a person asked for or holds the conversation.
        $this->post("/conversations/{$conversation->id}/simulate/handoff")->assertSessionHas('success');
        $this->assertSame('pending', $conversation->fresh()->handling);
        $count = $conversation->messages()->count();
        $this->post("/conversations/{$conversation->id}/simulate/ai-reply")->assertSessionHas('error');
        $this->assertSame($count, $conversation->messages()->count());
    }

    public function test_a_simulated_message_reopens_a_resolved_demo_conversation_with_the_ai(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $this->demo()->generate($dev);
        $conversation = Conversation::where('demo_key', 'wa-resolved-yesterday')->firstOrFail();
        $this->assertSame('resolved', $conversation->handling);

        $this->signIn($admin, $dev)->post("/conversations/{$conversation->id}/simulate/incoming", ['body' => 'Una duda más'])->assertSessionHas('success');

        $conversation->refresh();
        $this->assertSame('ai', $conversation->handling);
        $this->assertNull($conversation->assigned_user_id);
    }

    public function test_the_agent_flow_works_on_demo_conversations_and_nothing_is_sent_out(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $agent = $this->member($dev, 'agente');
        $this->demo()->generate($dev);
        $conversation = Conversation::where('demo_key', 'wa-order-ai')->firstOrFail();

        $this->signIn($agent, $dev);
        $this->post("/conversations/{$conversation->id}/take")->assertSessionHas('success');
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Soy un agente de prueba'])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $message = Message::where('sender', 'agent')->where('body', 'Soy un agente de prueba')->firstOrFail();
        $this->assertTrue($message->simulated);
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->external_id, 'a simulated send has no provider id');
        Http::assertNothingSent();

        $this->post("/conversations/{$conversation->id}/release")->assertSessionHas('success');
        $this->assertSame('ai', $conversation->fresh()->handling);
    }

    public function test_delivery_states_can_be_simulated_only_on_simulated_outgoing_messages(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $this->demo()->generate($dev);
        $conversation = Conversation::where('demo_key', 'wa-order-ai')->firstOrFail();
        $out = $conversation->messages()->where('direction', 'out')->firstOrFail();
        $in = $conversation->messages()->where('direction', 'in')->firstOrFail();
        $other = Conversation::where('demo_key', 'web-faq-ai')->firstOrFail()->messages()->where('direction', 'out')->firstOrFail();

        $this->signIn($admin, $dev);
        $this->post("/conversations/{$conversation->id}/messages/{$out->id}/simulate-status", ['status' => 'read'])->assertSessionHas('success');
        $this->assertSame('read', $out->fresh()->status);
        $this->post("/conversations/{$conversation->id}/messages/{$out->id}/simulate-status", ['status' => 'failed']);
        $this->assertStringContainsString('Fallo simulado', $out->fresh()->failure_reason);
        $this->post("/conversations/{$conversation->id}/messages/{$out->id}/simulate-status", ['status' => 'bogus'])->assertSessionHasErrors('status');
        $this->post("/conversations/{$conversation->id}/messages/{$in->id}/simulate-status", ['status' => 'read'])->assertSessionHas('error');
        // A message of another conversation is not found through this one.
        $this->post("/conversations/{$conversation->id}/messages/{$other->id}/simulate-status", ['status' => 'read'])->assertNotFound();
    }

    public function test_the_simulator_refuses_a_real_conversation(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $real = $this->realConversation($dev);

        $this->signIn($admin, $dev);
        $this->post("/conversations/{$real->id}/simulate/incoming", ['body' => 'x'])->assertNotFound();
        $this->post("/conversations/{$real->id}/simulate/ai-reply")->assertNotFound();
        $this->post("/conversations/{$real->id}/simulate/handoff")->assertNotFound();
        $this->assertSame(1, $real->messages()->count());
        Http::assertNothingSent();
    }

    public function test_real_conversations_and_the_agent_api_still_work_next_to_demo_data(): void
    {
        $dev = $this->workspace('DESARROLLO_DEV');
        $admin = $this->member($dev, 'admin');
        $real = $this->realConversation($dev);
        $this->demo()->generate($dev);

        $this->signIn($admin, $dev)->get("/conversations?c={$real->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selected.handling', 'ai')->where('selected.items.0.body', 'Hola real'));
        $this->post("/conversations/{$real->id}/take")->assertSessionHas('success');
        // The real WhatsApp conversation still needs a real channel: demo availability does not leak onto it.
        $this->post("/conversations/{$real->id}/messages", ['body' => 'hola'])->assertSessionHas('error');
        $this->assertSame(0, $real->messages()->where('sender', 'agent')->count());
    }
}
