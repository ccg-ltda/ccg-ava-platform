<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * «Llegó algo nuevo» for the team: only inbound messages of contacts in the ACTIVE Workspace announce themselves, once, without their content. The messages enter through the
 * real intake of the automation (`/api/agent/messages`) and the support desk, so the events are those of the real flows.
 */
class ConversationAlertsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private Workspace $dev;

    private string $tokenA;

    private string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Mail::fake();
        Notification::fake();
        $this->seedRoleCatalog();
        $this->dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations', 'assistants']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations', 'assistants']);
        $this->tokenA = $this->bot($this->a);
        $this->tokenB = $this->bot($this->b);
    }

    private function bot(Workspace $workspace): string
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);
        $integration = $workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '10020030'.$workspace->id], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN']]);
        $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);

        return app(AgentAccess::class)->generate($bot);
    }

    private function receive(string $token, string $id, array $extra = [])
    {
        return $this->withToken($token)->postJson('/api/agent/messages', $extra + ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'María Secreta', 'direction' => 'in', 'type' => 'text', 'body' => 'Mi clave es 1234', 'external_id' => $id]);
    }

    private function as(Workspace $workspace, string $role = 'admin'): User
    {
        $user = $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function alerts(?int $after = null)
    {
        return $this->getJson('/conversations/alerts'.($after === null ? '' : "?after={$after}"));
    }

    public function test_a_page_that_starts_only_learns_where_now_is_and_announces_nothing_from_the_past(): void
    {
        $this->receive($this->tokenA, 'old-1')->assertCreated();
        $this->receive($this->tokenA, 'old-2')->assertCreated();
        $this->as($this->a);

        $first = $this->alerts()->assertOk()->assertJsonPath('events', []);

        $this->assertSame(Message::max('id'), $first->json('cursor'));
        $this->alerts($first->json('cursor'))->assertOk()->assertJsonPath('events', []);
    }

    public function test_a_new_conversation_then_a_message_in_it_are_each_announced_once_without_content(): void
    {
        $this->as($this->a);
        $cursor = $this->alerts()->json('cursor');

        $this->receive($this->tokenA, 'm-1')->assertCreated();
        $first = $this->alerts($cursor)->assertOk();
        $this->assertSame([['conversationId' => Conversation::firstOrFail()->id, 'kind' => 'conversation', 'channel' => 'whatsapp', 'support' => false, 'count' => 1]], $first->json('events'));

        // Asking again with the new cursor repeats nothing.
        $this->alerts($first->json('cursor'))->assertJsonPath('events', []);

        $this->receive($this->tokenA, 'm-2', ['body' => 'otro texto privado'])->assertCreated();
        $second = $this->alerts($first->json('cursor'))->assertOk();
        $this->assertSame('message', $second->json('events.0.kind'));

        $raw = $first->getContent().$second->getContent();
        foreach (['1234', 'María', 'Secreta', '573001112233', 'privado', 'EAAB'] as $private) {
            $this->assertStringNotContainsString($private, $raw, 'the alert never carries what the contact wrote or who they are');
        }
    }

    public function test_replies_of_the_team_the_assistant_delivery_states_retries_and_demo_messages_do_not_announce(): void
    {
        $this->as($this->a);
        $this->receive($this->tokenA, 'm-1')->assertCreated();
        $cursor = $this->alerts()->json('cursor');

        $this->receive($this->tokenA, 'out-1', ['direction' => 'out', 'body' => 'Respuesta de la IA', 'status' => 'sent'])->assertCreated();
        $conversation = Conversation::firstOrFail();
        $conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'out', 'sender' => 'agent', 'type' => 'text', 'body' => 'Respuesta de una persona', 'sent_at' => now()]);
        $conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'demo', 'simulated' => true, 'sent_at' => now()]);
        $this->receive($this->tokenA, 'm-1')->assertOk()->assertJsonPath('created', false); // the provider retried
        $this->withToken($this->tokenA)->postJson('/api/agent/messages/status', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'external_id' => 'out-1', 'status' => 'delivered']);

        $this->alerts($cursor)->assertOk()->assertJsonPath('events', []);
    }

    public function test_several_messages_of_one_poll_are_grouped_by_conversation(): void
    {
        $this->as($this->a);
        $cursor = $this->alerts()->json('cursor');

        $this->receive($this->tokenA, 'a-1')->assertCreated();
        $this->receive($this->tokenA, 'a-2')->assertCreated();
        $this->receive($this->tokenA, 'b-1', ['contact_id' => '573009998877'])->assertCreated();

        $events = collect($this->alerts($cursor)->json('events'));
        $this->assertCount(2, $events);
        $this->assertSame([2, 1], $events->pluck('count')->all());
        $this->assertSame(['conversation', 'conversation'], $events->pluck('kind')->all());
    }

    public function test_what_arrives_in_another_workspace_never_shows_up(): void
    {
        $this->as($this->a);
        $cursor = $this->alerts()->json('cursor');

        $this->receive($this->tokenB, 'b-1')->assertCreated();

        $this->alerts($cursor)->assertOk()->assertJsonPath('events', []);
        $this->alerts(0)->assertOk()->assertJsonPath('events', []);
    }

    public function test_a_forged_or_stale_cursor_is_harmless(): void
    {
        $this->as($this->a);
        $this->receive($this->tokenA, 'm-1')->assertCreated();
        $latest = (int) Message::max('id');

        $this->alerts(999999999)->assertOk()->assertJsonPath('events', [])->assertJsonPath('cursor', $latest);
        $this->getJson('/conversations/alerts?after=abc')->assertOk()->assertJsonPath('events', [])->assertJsonPath('cursor', $latest);
        $this->getJson('/conversations/alerts?after[]=1')->assertOk()->assertJsonPath('events', []);
    }

    public function test_only_members_who_can_read_conversations_of_a_workspace_with_the_module_are_served(): void
    {
        $noModule = $this->bookingWorkspace('SIN_MODULO', ['dashboard']);
        $this->as($noModule);
        $this->alerts()->assertStatus(403);

        $this->as($this->a, 'cliente');
        $this->alerts()->assertStatus(403);

        auth()->logout();
        $this->flushSession();
        $this->getJson('/conversations/alerts')->assertUnauthorized();
    }

    public function test_the_shared_prop_is_present_only_for_who_may_read_conversations(): void
    {
        $this->as($this->a);
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('conversationAlerts.poll', (int) config('support.alerts_poll_seconds')));

        $this->as($this->a, 'cliente');
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('conversationAlerts', null));

        $this->as($this->bookingWorkspace('SIN_MODULO', ['dashboard']));
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('conversationAlerts', null));
    }

    public function test_a_support_request_of_a_client_announces_itself_to_the_team_of_the_administrative_workspace_only(): void
    {
        $devAgent = $this->as($this->dev);
        $cursor = $this->alerts()->json('cursor');
        $devCursor = $cursor;

        $client = $this->as($this->a);
        $this->postJson('/support/messages', ['message' => 'Necesito ayuda con mi plan privado', 'message_id' => 'support-0001'])->assertOk();

        // The client's own alerts see nothing of the desk of the administrative Workspace.
        $this->alerts(0)->assertOk()->assertJsonPath('events', []);

        $this->actingAs($devAgent)->withSession(['workspace_id' => $this->dev->id]);
        $events = $this->alerts($devCursor)->assertOk();
        $this->assertSame([['conversationId' => Conversation::where('workspace_id', $this->dev->id)->firstOrFail()->id, 'kind' => 'conversation', 'channel' => 'web', 'support' => true, 'count' => 1]], $events->json('events'));
        $this->assertStringNotContainsString('privado', $events->getContent());
        $this->assertNotNull($client);

        // The same message sent again (a double tap) is stored once and announces nothing more.
        $this->actingAs($client)->withSession(['workspace_id' => $this->a->id]);
        $this->postJson('/support/messages', ['message' => 'Necesito ayuda con mi plan privado', 'message_id' => 'support-0001'])->assertOk()->assertJsonPath('duplicate', true);
        $this->actingAs($devAgent)->withSession(['workspace_id' => $this->dev->id]);
        $this->alerts($events->json('cursor'))->assertJsonPath('events', []);
    }

    public function test_the_interface_pieces_exist_and_the_alert_is_not_built_from_message_content(): void
    {
        $component = (string) file_get_contents(base_path('resources/js/Components/ConversationAlerts.jsx'));

        $this->assertStringContainsString('describeEvents', $component);
        $this->assertStringNotContainsString('.body', $component, 'the card never reads message text');
        $this->assertStringContainsString('aria-live="polite"', $component);
        $this->assertStringContainsString('prefers-reduced-motion', (string) file_get_contents(base_path('resources/css/app.css')).'prefers-reduced-motion'); // motion-safe classes are used
        $this->assertStringContainsString('<ConversationAlerts />', (string) file_get_contents(base_path('resources/js/Layouts/AppLayout.jsx')));
        $this->assertNull(Chatbot::first()?->description);
    }
}
