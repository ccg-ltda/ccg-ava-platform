<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ConversationAutoClose;
use App\Services\ConversationControl;
use App\Services\ConversationMessenger;
use App\Services\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** Automatic close by inactivity, configurable per Workspace. Uses the test clock (travel): no real waiting. */
class ConversationAutoCloseTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Queue::fake();
        $this->seedRoleCatalog();
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'settings', 'conversations', 'assistants']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'settings', 'conversations', 'assistants']);
        $this->agent = $this->member($this->a, 'admin');
    }

    private function save(Workspace $workspace, bool $enabled, int $minutes): void
    {
        app(ConversationAutoClose::class)->save($workspace, ['enabled' => $enabled, 'minutes' => $minutes]);
    }

    private function conversation(Workspace $workspace, string $handling = Conversation::AI, string $contact = '573001112233'): Conversation
    {
        $bot = $workspace->chatbots()->first();

        if (! $bot) {
            $bot = $workspace->chatbots()->create(['name' => 'Asistente']);
            $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'is_active' => true, 'public_key' => strtolower(Str::random(32))]);
        }

        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'web', 'contact_id' => $contact, 'last_message_at' => now()]);
        $conversation->forceFill(['handling' => $handling])->save();
        $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Hola', 'sent_at' => now()]);

        return $conversation;
    }

    private function sweep(): int
    {
        return app(ConversationAutoClose::class)->closeIdle();
    }

    public function test_without_a_saved_setting_ordinary_conversations_are_never_closed_by_the_clock(): void
    {
        $conversation = $this->conversation($this->a);
        $this->travel(600)->minutes();

        $this->assertSame(0, $this->sweep());
        $this->assertSame(Conversation::AI, $conversation->refresh()->handling);
        $this->assertFalse(app(ConversationAutoClose::class)->settings($this->a)['configured']);
        $this->assertSame(4, app(ConversationAutoClose::class)->settings($this->a)['minutes']); // the compatible initial value
    }

    public function test_it_closes_after_the_configured_interval_and_not_before(): void
    {
        $this->save($this->a, true, 10);
        $conversation = $this->conversation($this->a);

        $this->travel(9)->minutes();
        $this->assertSame(0, $this->sweep());
        $this->assertNotSame(Conversation::RESOLVED, $conversation->refresh()->handling);

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->sweep());
        $this->assertSame(Conversation::RESOLVED, $conversation->refresh()->handling);
    }

    public function test_turning_it_off_closes_nothing_and_each_workspace_has_its_own_interval(): void
    {
        $this->save($this->a, false, 1);
        $this->save($this->b, true, 30);
        $one = $this->conversation($this->a);
        $two = $this->conversation($this->b);

        $this->travel(5)->minutes();
        $this->assertSame(0, $this->sweep());
        $this->assertNotSame(Conversation::RESOLVED, $one->refresh()->handling);
        $this->assertNotSame(Conversation::RESOLVED, $two->refresh()->handling); // B waits 30, A's interval does not apply to it

        $this->travel(30)->minutes();
        $this->assertSame(1, $this->sweep());
        $this->assertNotSame(Conversation::RESOLVED, $one->refresh()->handling);
        $this->assertSame(Conversation::RESOLVED, $two->refresh()->handling);
    }

    public function test_real_activity_of_the_contact_the_ai_and_the_agent_restarts_the_clock(): void
    {
        $this->save($this->a, true, 10);
        $conversation = $this->conversation($this->a);

        // A message of the contact.
        $this->travel(8)->minutes();
        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'sigo', 'sent_at' => now()]);
        $this->travel(8)->minutes();
        $this->assertSame(0, $this->sweep());

        // A reply of the AI.
        $conversation->forceFill(['last_message_at' => now()])->save();
        $this->travel(8)->minutes();
        $this->assertSame(0, $this->sweep());

        // «Sigo trabajando en tu solicitud» from an agent, through the real messenger.
        app(ConversationControl::class)->take($conversation->refresh(), $this->agent);
        $this->travel(5)->minutes();
        app(ConversationMessenger::class)->send($conversation->refresh(), $this->agent, true, 'Sigo trabajando en tu solicitud, dame un momento');
        $this->travel(8)->minutes();
        $this->assertSame(0, $this->sweep());
        $this->assertSame(Conversation::HUMAN, $conversation->refresh()->handling);

        $this->travel(3)->minutes();
        $this->assertSame(1, $this->sweep());
    }

    public function test_notifications_state_changes_audit_and_reads_do_not_restart_the_clock(): void
    {
        $this->save($this->a, true, 10);
        $conversation = $this->conversation($this->a);
        $stamp = $conversation->last_message_at->toIso8601String();

        $this->travel(6)->minutes();
        app(Notifications::class)->notify($this->a, 'x', 'Aviso', null, null);
        app(ConversationControl::class)->requestHuman($conversation->refresh(), 'cambio de estado'); // a state change + notification + audit
        $this->assertSame($stamp, $conversation->refresh()->last_message_at->toIso8601String());

        $this->travel(5)->minutes();
        $this->assertSame(1, $this->sweep());
        $this->assertSame(Conversation::RESOLVED, $conversation->refresh()->handling);
    }

    public function test_an_ai_execution_in_flight_keeps_it_open_but_a_stuck_one_does_not(): void
    {
        $this->save($this->a, true, 5);
        $conversation = $this->conversation($this->a);
        $message = $conversation->messages()->first();
        ChatbotExecution::insert([[
            'correlation_id' => (string) Str::uuid(), 'workspace_id' => $this->a->id, 'chatbot_id' => $conversation->chatbot_id, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
            'workflow_key' => 'support', 'n8n_workflow_id' => 'wf', 'workflow_version' => '1', 'handling_version' => 0, 'status' => ChatbotExecution::RUNNING, 'attempts' => 1,
            'created_at' => now()->addMinutes(5), 'updated_at' => now()->addMinutes(5),
        ]]);

        // Six quiet minutes, but the execution started a minute ago: valid processing, not abandoned.
        $this->travel(6)->minutes();
        $this->assertSame(0, $this->sweep());

        // Long after the time an execution may take, a blocked workflow does not hold the conversation open.
        $this->travel(30)->minutes();
        $this->assertSame(1, $this->sweep());
    }

    public function test_a_message_that_arrives_while_closing_wins_and_closing_twice_does_it_once(): void
    {
        $this->save($this->a, true, 5);
        $conversation = $this->conversation($this->a);
        $this->travel(6)->minutes();

        // The job selected a STALE copy; meanwhile the contact wrote. The decision is re-made under the lock on the fresh row.
        $stale = Conversation::find($conversation->id);
        Conversation::whereKey($conversation->id)->update(['last_message_at' => now()]);
        $this->assertFalse(app(ConversationControl::class)->resolveForInactivity($stale, fn (Conversation $locked) => app(ConversationAutoClose::class)->idle($locked)));
        $this->assertNotSame(Conversation::RESOLVED, $conversation->refresh()->handling);

        $this->travel(6)->minutes();
        $this->assertSame(1, $this->sweep());
        $this->assertSame(0, $this->sweep());
        $this->assertSame(1, AuditLog::where('action', 'closed_inactivity')->where('resource_id', $conversation->id)->count());
    }

    public function test_closing_keeps_history_sends_nothing_and_the_contact_reopens_it(): void
    {
        $this->save($this->a, true, 5);
        $conversation = $this->conversation($this->a);
        $messages = Message::count();
        $this->travel(6)->minutes();

        $this->sweep();

        $this->assertSame($messages, Message::count()); // no closing line, no goodbye to the contact
        Http::assertNothingSent();
        $audit = AuditLog::where('action', 'closed_inactivity')->firstOrFail();
        $this->assertNull($audit->user_id); // the system's event, never a person's
        $this->assertSame($this->a->id, $audit->workspace_id);

        app(ConversationControl::class)->reopen($conversation->refresh());
        $this->assertSame(Conversation::AI, $conversation->refresh()->handling);
    }

    public function test_demo_resolved_and_foreign_conversations_are_left_alone(): void
    {
        $this->save($this->a, true, 5);
        $demo = $this->conversation($this->a, Conversation::AI, 'demo-1');
        $demo->forceFill(['demo_key' => 'scenario'])->save();
        $resolved = $this->conversation($this->a, Conversation::RESOLVED, 'old-1');
        $other = $this->conversation($this->b); // B never configured it

        $this->travel(60)->minutes();
        $this->assertSame(0, $this->sweep());
        $this->assertSame(Conversation::AI, $demo->refresh()->handling);
        $this->assertNotSame(Conversation::RESOLVED, $other->refresh()->handling);
        $this->assertNotNull($resolved);
    }

    public function test_the_settings_are_validated_saved_per_workspace_audited_and_protected(): void
    {
        $this->actingAs($this->agent)->withSession(['workspace_id' => $this->a->id]);
        $this->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->where('autoClose.configured', false)->where('autoClose.minutes', 4)->where('autoClose.min', 1));

        $this->put('/settings/conversations', ['enabled' => true, 'minutes' => 0])->assertSessionHasErrors('minutes');
        $this->put('/settings/conversations', ['enabled' => true, 'minutes' => 5000])->assertSessionHasErrors('minutes');
        $this->put('/settings/conversations', ['enabled' => true, 'minutes' => 'abc'])->assertSessionHasErrors('minutes');
        $this->put('/settings/conversations', ['enabled' => true, 'minutes' => 15, 'workspace_id' => $this->b->id])->assertSessionHasNoErrors();

        $this->assertSame(['enabled' => true, 'minutes' => 15, 'configured' => true], app(ConversationAutoClose::class)->settings($this->a));
        $this->assertFalse(app(ConversationAutoClose::class)->settings($this->b)['configured']); // the id sent by the browser is ignored
        $this->assertTrue(AuditLog::where('resource_type', 'settings')->where('workspace_id', $this->a->id)->exists());
        $this->get('/settings')->assertInertia(fn ($p) => $p->where('autoClose.configured', true)->where('autoClose.minutes', 15));

        // A role without edit-settings cannot change it; a Workspace without Conversaciones does not even get the tab.
        $this->actingAs($this->member($this->a, 'cliente'))->withSession(['workspace_id' => $this->a->id]);
        $this->put('/settings/conversations', ['enabled' => false, 'minutes' => 1])->assertForbidden();
        $plain = $this->bookingWorkspace('SIN_CONV', ['dashboard', 'settings']);
        $this->actingAs($this->member($plain, 'admin'))->withSession(['workspace_id' => $plain->id]);
        $this->get('/settings')->assertInertia(fn ($p) => $p->where('autoClose', null));
        $this->put('/settings/conversations', ['enabled' => true, 'minutes' => 5])->assertForbidden();
    }
}
