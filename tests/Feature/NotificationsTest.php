<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceNotification;
use App\Services\ConversationAlerts;
use App\Services\ConversationControl;
use App\Services\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The common notification system and the handoff notification: persisted once per real transition to a person, per-user read state, strict Workspace isolation
 * (ids forged in the URL are 404), and a client's AI-handled messages never counted or announced as «needs a person».
 */
class NotificationsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations', 'assistants']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations', 'assistants']);
    }

    private function as(Workspace $workspace, string $role = 'admin'): User
    {
        $user = $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function conversation(Workspace $workspace, string $contact = '573001112233'): Conversation
    {
        $bot = $workspace->chatbots()->first() ?? $workspace->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'contact_id' => $contact, 'last_message_at' => now()]);
        $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => now()]);

        return $conversation;
    }

    public function test_the_ai_answering_creates_no_pending_no_notification_and_no_alert(): void
    {
        $user = $this->as($this->a);
        $this->conversation($this->a);

        $this->assertSame(0, app(ConversationAlerts::class)->pending($this->a, $user));
        $this->assertSame(0, WorkspaceNotification::count());
        $this->getJson('/notifications')->assertOk()->assertJsonPath('unread', 0)->assertJsonPath('items', []);
        $this->getJson('/conversations/alerts')->assertOk()->assertJsonPath('events', [])->assertJsonPath('handoffs', []);
    }

    public function test_a_real_transition_to_a_person_notifies_once_and_a_new_message_does_not_repeat_it(): void
    {
        $user = $this->as($this->a);
        $conversation = $this->conversation($this->a);
        $control = app(ConversationControl::class);

        $cursor = $this->getJson('/conversations/alerts')->json('handoffCursor');

        $control->requestHuman($conversation, 'El cliente pide una persona');
        $this->assertSame(1, WorkspaceNotification::where('type', 'conversation_pending')->count());
        $this->assertSame(1, app(ConversationAlerts::class)->pending($this->a, $user));

        // Repeating the request (already pending) is not a transition; neither is another message of the contact.
        $control->requestHuman($conversation->refresh());
        $conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Sigo aquí', 'sent_at' => now()]);
        $this->assertSame(1, WorkspaceNotification::count());

        $alerts = $this->getJson('/conversations/alerts?after=0&n='.$cursor)->assertOk();
        $this->assertCount(1, $alerts->json('handoffs'));
        $this->assertSame($conversation->id, $alerts->json('handoffs.0.conversationId'));
        $this->getJson('/conversations/alerts?after=0&n='.$alerts->json('handoffCursor'))->assertJsonPath('handoffs', []);

        $item = $this->getJson('/notifications')->json('items.0');
        $this->assertSame('Una conversación necesita tu atención', $item['title']);
        $this->assertSame('/conversations?c='.$conversation->id, $item['url']);
        $this->assertStringNotContainsString('Hola', json_encode($item));
    }

    public function test_the_counter_follows_take_return_and_resolve_and_history_is_kept(): void
    {
        $user = $this->as($this->a);
        $conversation = $this->conversation($this->a);
        $control = app(ConversationControl::class);
        $alerts = app(ConversationAlerts::class);

        $control->requestHuman($conversation);
        $this->assertSame(1, $alerts->pending($this->a, $user));

        $control->take($conversation->refresh(), $user);
        $this->assertSame(1, $alerts->pending($this->a, $user)); // mine and the contact spoke last

        $conversation->refresh()->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'out', 'sender' => 'agent', 'type' => 'text', 'body' => 'Atendido', 'sent_at' => now()->addSecond()]);
        $this->assertSame(0, $alerts->pending($this->a, $user)); // answered

        $control->returnToAi($conversation->refresh(), $user, true);
        $this->assertSame(0, $alerts->pending($this->a, $user));

        $control->requestHuman($conversation->refresh());
        $control->resolve($conversation->refresh(), $user, true);
        $this->assertSame(0, $alerts->pending($this->a, $user));
        // Two real transitions to a person => two notifications, none deleted by resolving.
        $this->assertSame(2, WorkspaceNotification::count());
    }

    public function test_read_state_is_explicit_per_user_and_opening_the_list_reads_nothing(): void
    {
        $first = $this->as($this->a);
        $second = $this->member($this->a, 'admin');
        $n = app(Notifications::class)->notify($this->a, 'test', 'Aviso', 'Texto', '/dashboard');
        app(Notifications::class)->notify($this->a, 'test', 'Otro', null, null);

        $this->getJson('/notifications')->assertJsonPath('unread', 2);
        $this->getJson('/notifications')->assertJsonPath('unread', 2); // looking does not read

        $this->postJson("/notifications/{$n->id}/read")->assertOk()->assertJsonPath('unread', 1);
        $this->postJson("/notifications/{$n->id}/read")->assertOk()->assertJsonPath('unread', 1); // idempotent

        // The other member still has both unread.
        $this->actingAs($second)->withSession(['workspace_id' => $this->a->id]);
        $this->getJson('/notifications')->assertJsonPath('unread', 2);

        $this->actingAs($first)->withSession(['workspace_id' => $this->a->id]);
        $this->postJson('/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_dedupe_key_notifies_once_per_business_transition(): void
    {
        $service = app(Notifications::class);
        $this->assertNotNull($service->notify($this->a, 'x', 'T', null, null, null, null, null, null, 'k1'));
        $this->assertNull($service->notify($this->a, 'x', 'T', null, null, null, null, null, null, 'k1'));
        $this->assertNotNull($service->notify($this->b, 'x', 'T', null, null, null, null, null, null, 'k1')); // same key, other Workspace
        $this->assertSame(2, WorkspaceNotification::count());
    }

    public function test_a_workspace_never_sees_or_touches_the_notifications_of_another(): void
    {
        $service = app(Notifications::class);
        $mine = $service->notify($this->a, 'x', 'Mia', null, null);
        $theirs = $service->notify($this->b, 'x', 'Ajena SECRETA', null, null);
        $targeted = $service->notify($this->a, 'x', 'Para otra persona', null, null, null, $this->member($this->a, 'admin'));

        $user = $this->as($this->a);
        $json = $this->getJson('/notifications')->assertOk();
        $this->assertSame([$mine->id], collect($json->json('items'))->pluck('id')->all());
        $this->assertStringNotContainsString('SECRETA', $json->getContent());

        // A forged id (other Workspace, or addressed to somebody else) behaves like one that does not exist, and nothing is marked.
        $this->postJson("/notifications/{$theirs->id}/read")->assertNotFound();
        $this->postJson("/notifications/{$targeted->id}/read")->assertNotFound();
        $this->postJson('/notifications/999999/read')->assertNotFound();
        $this->postJson('/notifications/read-all')->assertOk();
        $this->assertDatabaseMissing('workspace_notification_reads', ['notification_id' => $theirs->id]);
        $this->assertDatabaseMissing('workspace_notification_reads', ['notification_id' => $targeted->id]);

        // The Workspace sent by the browser is ignored: only the session's counts.
        $this->postJson('/notifications/read-all', ['workspace_id' => $this->b->id])->assertOk();
        $this->assertDatabaseMissing('workspace_notification_reads', ['notification_id' => $theirs->id]);
        $this->assertNotNull($user);
    }

    public function test_permission_scoped_notifications_need_that_permission(): void
    {
        app(Notifications::class)->notify($this->a, 'conversation_pending', 'Atención', null, null, 'view-conversations');
        $this->as($this->a, 'cliente'); // the role `cliente` cannot read conversations
        $this->getJson('/notifications')->assertJsonPath('unread', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/notifications')->assertUnauthorized();
        $this->postJson('/notifications/read-all')->assertUnauthorized();
    }

    public function test_administrative_workspace_gets_the_bell_but_keeps_its_own_toast_feed(): void
    {
        $dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        $dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        $bot = $dev->chatbots()->create(['name' => 'Soporte']);
        $conversation = $bot->conversations()->create(['workspace_id' => $dev->id, 'channel' => 'web', 'contact_id' => 'visitante', 'last_message_at' => now()]);
        app(ConversationControl::class)->requestHuman($conversation);

        $this->getJson('/notifications')->assertJsonPath('unread', 1); // in the bell...
        $this->getJson('/conversations/alerts?after=0&n=0')->assertJsonPath('handoffs', []); // ...but no second toast: support keeps its own alerts
    }
}
