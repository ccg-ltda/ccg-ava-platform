<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ChannelDelivery;
use App\Services\SupportDesk;
use App\Services\SupportSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * An outgoing message is never «Enviando» forever and never claims more than was confirmed: sent (the channel took it), delivered and read (the channel said so, each with its own
 * time), failed or «sin confirmar» (nobody answered). States only move forward, a repeated or late event changes nothing, and nothing crosses a Workspace. Everything is simulated: no
 * request leaves the machine.
 */
class MessageStatusFlowTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $a;

    private Workspace $b;

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

    private function out(string $token, string $id, array $extra = [])
    {
        return $this->withToken($token)->postJson('/api/agent/messages', $extra + ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'out', 'type' => 'text', 'body' => 'Respuesta', 'external_id' => $id, 'status' => 'sent']);
    }

    private function receipt(string $token, string $id, string $status, string $contact = '573001112233')
    {
        return $this->withToken($token)->postJson('/api/agent/messages/status', ['channel' => 'whatsapp', 'contact_id' => $contact, 'external_id' => $id, 'status' => $status]);
    }

    private function as(Workspace $workspace, string $role = 'admin', ?User $user = null): User
    {
        $user ??= $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    // --- the real channel: confirmations from the provider ----------------------------------------------------------------

    public function test_delivered_and_read_arrive_in_order_each_with_its_own_time_and_never_move_backwards(): void
    {
        $this->out($this->tokenA, 'wamid.1')->assertCreated();
        $message = Message::where('external_id', 'wamid.1')->firstOrFail();
        $this->assertNull($message->delivered_at);

        $this->travel(1)->minutes();
        $this->receipt($this->tokenA, 'wamid.1', 'delivered')->assertOk();
        $delivered = $message->fresh();
        $this->assertSame('delivered', $delivered->status);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertNull($delivered->read_at, 'delivered is not read');

        $this->travel(2)->minutes();
        $this->receipt($this->tokenA, 'wamid.1', 'read')->assertOk();
        $read = $message->fresh();
        $this->assertSame('read', $read->status);
        $this->assertTrue($read->read_at->gt($read->delivered_at));

        // A repeated or late «delivered» / «sent» changes nothing: states never go back and the times stay.
        $this->travel(1)->minutes();
        $this->receipt($this->tokenA, 'wamid.1', 'delivered');
        $this->receipt($this->tokenA, 'wamid.1', 'sent');
        $this->receipt($this->tokenA, 'wamid.1', 'read');
        $again = $message->fresh();
        $this->assertSame('read', $again->status);
        $this->assertTrue($again->delivered_at->equalTo($read->delivered_at));
        $this->assertTrue($again->read_at->equalTo($read->read_at));
    }

    public function test_a_read_that_arrives_before_the_delivery_still_ends_read_with_a_delivery_time(): void
    {
        $this->out($this->tokenA, 'wamid.2')->assertCreated();

        $this->receipt($this->tokenA, 'wamid.2', 'read')->assertOk(); // out of order: Meta may skip «delivered»
        $this->receipt($this->tokenA, 'wamid.2', 'delivered');         // and then send it late

        $message = Message::where('external_id', 'wamid.2')->firstOrFail();
        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->read_at);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_a_failure_after_a_delivery_does_not_undo_it_and_a_failure_before_one_is_recorded(): void
    {
        $this->out($this->tokenA, 'wamid.3')->assertCreated();
        $this->receipt($this->tokenA, 'wamid.3', 'delivered');
        $this->receipt($this->tokenA, 'wamid.3', 'failed');
        $this->assertSame('delivered', Message::where('external_id', 'wamid.3')->value('status'));

        $this->out($this->tokenA, 'wamid.4')->assertCreated();
        $this->receipt($this->tokenA, 'wamid.4', 'failed');
        $this->assertSame('failed', Message::where('external_id', 'wamid.4')->value('status'));
    }

    public function test_a_state_of_another_message_contact_or_workspace_changes_nothing_and_an_incoming_message_has_no_receipts(): void
    {
        $this->out($this->tokenA, 'wamid.5')->assertCreated();
        $this->out($this->tokenA, 'wamid.6', ['contact_id' => '573009998877'])->assertCreated();
        $this->out($this->tokenB, 'wamid.5')->assertCreated(); // the same provider id in another Workspace is another message

        $this->receipt($this->tokenA, 'wamid.5', 'read', '573009998877')->assertNotFound(); // right id, wrong contact
        $this->receipt($this->tokenB, 'wamid.5', 'delivered')->assertOk();

        $this->assertSame(['sent', 'sent'], Message::where('workspace_id', $this->a->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame('delivered', Message::where('workspace_id', $this->b->id)->value('status'));
        $this->assertNull(Message::where('workspace_id', $this->a->id)->value('read_at'));

        $this->withToken($this->tokenA)->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola', 'external_id' => 'in-1'])->assertCreated();
        $this->receipt($this->tokenA, 'in-1', 'read')->assertNotFound(); // an incoming message is never «read» by a receipt
        $this->assertNull(Message::where('external_id', 'in-1')->value('status'));
    }

    // --- never «Enviando» forever ------------------------------------------------------------------------------------

    public function test_a_message_nobody_confirmed_is_settled_as_unconfirmed_not_left_sending_and_not_called_sent(): void
    {
        $conversation = $this->a->chatbots()->firstOrFail()->conversations()->create(['workspace_id' => $this->a->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233']);
        $old = $conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'out', 'sender' => 'agent', 'type' => 'text', 'body' => 'Antiguo', 'status' => Message::PENDING, 'sent_at' => now()]);
        $this->travel(3)->minutes();
        $recent = $conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'out', 'sender' => 'agent', 'type' => 'text', 'body' => 'Reciente', 'status' => Message::PENDING, 'sent_at' => now()]);

        $this->artisan('conversations:maintain')->assertSuccessful();

        $this->assertSame(Message::UNCONFIRMED, $old->fresh()->status);
        $this->assertStringContainsString('No se recibió confirmación', (string) $old->fresh()->failure_reason);
        $this->assertSame(Message::PENDING, $recent->fresh()->status, 'a message still inside the window keeps trying');

        // A late worker that finally picks the old job up sends NOTHING: only a pending message is ever handed to a channel.
        app(ChannelDelivery::class)->deliver($old->fresh());
        $this->assertSame(Message::UNCONFIRMED, $old->fresh()->status);
    }

    public function test_the_agent_reply_in_a_support_request_is_handed_over_right_after_the_response_not_left_for_a_queue(): void
    {
        $client = $this->as($this->a);
        $this->postJson('/support/messages', ['message' => 'Hola', 'message_id' => 'status-0001'])->assertOk();
        $conversation = Conversation::where('contact_id', SupportDesk::contactId($client, $this->a))->firstOrFail();

        $this->as($this->dev, 'agente');
        $this->post("/conversations/{$conversation->id}/take");
        config(['queue.default' => 'database']); // an instance whose queue nobody consumes
        $this->post("/conversations/{$conversation->id}/messages", ['body' => 'Te ayudo'])->assertSessionHasNoErrors();

        $this->assertSame('sent', Message::where('sender', 'agent')->firstOrFail()->status, 'it did not wait for a worker that does not exist');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // --- the support panel: delivered when it has it, read only when it is seen ------------------------------------------------

    public function test_the_team_message_is_read_only_when_the_panel_is_open_and_visible(): void
    {
        $client = $this->as($this->a);
        $session = $this->postJson('/support/messages', ['message' => 'Hola', 'message_id' => 'status-0002'])->json('session');
        $agent = $this->as($this->dev, 'agente');
        $this->post("/conversations/{$session}/take");
        $this->post("/conversations/{$session}/messages", ['body' => 'Te ayudo']);
        $message = Message::where('sender', 'agent')->firstOrFail();
        $this->assertSame('sent', $message->status);

        $this->as($this->a, 'admin', $client);
        $this->getJson('/support/thread')->assertOk(); // loaded in the background: it has the message, nobody saw it
        $this->assertSame(['delivered', null], [$message->fresh()->status, $message->fresh()->read_at]);
        $this->assertNotNull($message->fresh()->delivered_at);

        $this->getJson('/support/thread?seen=1')->assertOk(); // open and visible
        $this->assertSame('read', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->read_at);

        // The team sees the state and the time of each confirmation.
        $this->as($this->dev, 'agente', $agent);
        $this->get('/conversations?c='.$session)->assertInertia(fn ($page) => $page
            ->where('selected.items.1.status', 'read')->where('selected.items.1.time', fn ($t) => $t !== null)->where('selected.items.1.readTime', fn ($t) => $t !== null)->where('selected.items.1.deliveredTime', fn ($t) => $t !== null));
        $this->assertNotNull(SupportSessions::minutes());
    }

    public function test_no_false_read_receipts_a_message_in_a_closed_session_or_a_demo_keeps_what_it_has(): void
    {
        $client = $this->as($this->a);
        $session = $this->postJson('/support/messages', ['message' => 'Hola', 'message_id' => 'status-0003'])->json('session');
        $this->as($this->dev, 'agente');
        $this->post("/conversations/{$session}/take");
        $this->post("/conversations/{$session}/messages", ['body' => 'Te ayudo']);
        $this->post("/conversations/{$session}/resolve");

        // The client never opened the panel after the reply: nothing was read, and closing did not mark anything read.
        $this->assertSame('sent', Message::where('sender', 'agent')->firstOrFail()->status);
        $this->assertNull(Message::where('sender', 'agent')->firstOrFail()->read_at);
        $this->assertNotNull($client);
    }
}
