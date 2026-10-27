<?php

namespace Tests\Feature;

use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SupportDesk;
use App\Services\SupportSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The life of a support request: it ends when the person or the team finishes it or after 4 quiet minutes (server clock), it is never opened again, the person's next message starts a
 * NEW conversation, and CCG keeps every session with its messages, who closed it, when and why. The clock is controlled with travel(): no test waits four real minutes.
 */
class SupportSessionsTest extends TestCase
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
        $this->dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations']);
    }

    private function as(Workspace $workspace, string $role = 'admin', ?User $user = null): User
    {
        $user ??= $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function write(string $text = 'Necesito ayuda', ?int $session = null)
    {
        static $n = 0;

        return $this->postJson('/support/messages', ['message' => $text, 'message_id' => 'ses-'.str_pad((string) ++$n, 8, '0', STR_PAD_LEFT), 'session' => $session]);
    }

    private function open(User $user, Workspace $workspace): ?Conversation
    {
        return Conversation::where('contact_id', SupportDesk::contactId($user, $workspace))->first();
    }

    // --- manual closing ----------------------------------------------------------------------------------------------

    public function test_the_client_ends_the_session_keeping_everything_and_the_next_message_opens_a_new_conversation(): void
    {
        $client = $this->as($this->a);
        $first = $this->write('Primer problema')->assertOk()->json('session');
        $conversation = Conversation::findOrFail($first);

        $this->postJson('/support/close', ['session' => $first])->assertOk()->assertJsonPath('state', 'closed')->assertJsonPath('reason', 'client');

        $conversation->refresh();
        $this->assertSame([Conversation::RESOLVED, 'client', $client->id], [$conversation->handling, $conversation->closed_reason, $conversation->closed_by_user_id]);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame(1, $conversation->messages()->where('sender', 'contact')->count(), 'the history is kept');
        $this->assertSame(['system'], $conversation->messages()->where('direction', 'out')->pluck('sender')->all());
        $this->assertNull($this->open($client, $this->a), 'nothing is open under the person own key');

        // Writing to the closed session is refused; writing without a session opens a brand new conversation with its own id and none of the old messages.
        $this->write('Sigo aquí', $first)->assertStatus(422);
        $second = $this->write('Segundo problema')->assertOk()->assertJsonCount(1, 'messages')->json('session');
        $this->assertNotSame($first, $second);
        $this->assertSame(2, Conversation::count());
        $this->assertSame(1, Conversation::findOrFail($first)->messages()->where('sender', 'contact')->count());
    }

    public function test_closing_twice_does_it_once_and_a_stranger_can_not_close_anyones_session(): void
    {
        $client = $this->as($this->a);
        $session = $this->write()->json('session');

        $this->postJson('/support/close', ['session' => $session])->assertOk();
        $closedAt = Conversation::findOrFail($session)->closed_at;
        $this->travel(30)->seconds();
        $this->postJson('/support/close', ['session' => $session])->assertOk()->assertJsonPath('state', 'closed');
        $this->assertTrue($closedAt->equalTo(Conversation::findOrFail($session)->closed_at), 'the second close changed nothing');
        $this->assertSame(1, Message::where('sender', 'system')->count());

        $other = $this->write('Otra')->json('session');
        $this->as($this->b);
        $this->postJson('/support/close', ['session' => $other])->assertOk(); // answers like any session that is not theirs: nothing happens
        $this->assertNull(Conversation::findOrFail($other)->closed_at, 'another Workspace can not end it');
        $this->assertNotNull($client);
    }

    public function test_the_agent_ends_a_session_and_ccg_keeps_it_open_closed_by_client_agent_or_clock(): void
    {
        $client = $this->as($this->a);
        $one = $this->write('Uno')->json('session');
        $this->postJson('/support/close', ['session' => $one])->assertOk();
        $two = $this->write('Dos')->json('session');

        $agent = $this->as($this->dev, 'agente');
        $this->post("/conversations/{$two}/take");
        $this->post("/conversations/{$two}/resolve")->assertSessionHasNoErrors();
        $this->as($this->a, 'admin', $client);
        $three = $this->write('Tres')->json('session');
        $this->travel(5)->minutes();
        $this->as($this->dev, 'agente', $agent);
        $this->artisan('conversations:maintain')->assertSuccessful();

        $reasons = Conversation::orderBy('id')->pluck('closed_reason', 'id')->all();
        $this->assertSame(['client', 'agent', 'inactivity'], [$reasons[$one], $reasons[$two], $reasons[$three]]);

        // CCG still reads all three, closed, with their messages and who/when/why, from the inbox it already uses.
        $this->get('/conversations?c='.$one)->assertOk()->assertInertia(fn ($page) => $page->where('selected.closed.reason', 'client')->where('selected.closed.label', 'Finalizada por el cliente')->has('selected.items', 2));
        $this->get('/conversations?c='.$two)->assertInertia(fn ($page) => $page->where('selected.closed.reason', 'agent')->where('selected.closed.by', $agent->name));
        $this->get('/conversations?c='.$three)->assertInertia(fn ($page) => $page->where('selected.closed.reason', 'inactivity')->where('selected.closed.by', null));
        $this->post("/conversations/{$one}/messages", ['body' => 'No debería'])->assertSessionHas('error'); // nobody writes to a closed session
    }

    // --- four minutes of inactivity -------------------------------------------------------------------------------------

    public function test_a_session_with_no_messages_for_four_minutes_is_closed_by_the_server_and_the_window_is_told(): void
    {
        $client = $this->as($this->a);
        $session = $this->write()->json('session');

        $this->travel(3)->minutes();
        $this->artisan('conversations:maintain')->assertSuccessful();
        $this->assertNull(Conversation::findOrFail($session)->closed_at, 'three minutes is not enough');

        $this->travel(61)->seconds();
        $this->artisan('conversations:maintain')->assertSuccessful();
        $closed = Conversation::findOrFail($session);
        $this->assertSame(['inactivity', Conversation::RESOLVED, null], [$closed->closed_reason, $closed->handling, $closed->closed_by_user_id]);

        $this->getJson('/support/thread?session='.$session)->assertJsonPath('state', 'closed')->assertJsonPath('reason', 'inactivity')
            ->assertJsonPath('label', fn ($text) => str_contains($text, 'inactividad'));
        $this->assertNotNull($client);
    }

    public function test_a_valid_message_from_either_side_restarts_the_four_minutes_but_polling_and_the_closing_line_do_not(): void
    {
        $client = $this->as($this->a);
        $session = $this->write('Hola')->json('session');

        $this->travel(3)->minutes();
        $this->getJson('/support/thread?session='.$session)->assertOk(); // a poll, even with the window "seen"
        $this->getJson('/support/thread?session='.$session.'&seen=1')->assertOk();
        $this->write('Sigo aquí', $session)->assertOk(); // a real message at minute 3
        $this->travel(3)->minutes();                      // minute 6 from the start, 3 from the last message
        $this->artisan('conversations:maintain');
        $this->assertNull(Conversation::findOrFail($session)->closed_at);

        $agent = $this->as($this->dev, 'agente');
        $this->post("/conversations/{$session}/take");
        $this->travel(2)->minutes();
        $this->post("/conversations/{$session}/messages", ['body' => 'Te ayudo']); // the team writing also counts
        $this->travel(3)->minutes();
        $this->artisan('conversations:maintain');
        $this->assertNull(Conversation::findOrFail($session)->closed_at);

        $this->travel(2)->minutes();
        $this->artisan('conversations:maintain');
        $this->assertSame('inactivity', Conversation::findOrFail($session)->closed_reason);
        $this->assertNotNull($client);
        $this->assertNotNull($agent);
    }

    public function test_reading_or_writing_closes_an_idle_session_even_if_the_scheduler_never_ran(): void
    {
        $client = $this->as($this->a);
        $session = $this->write('Hola')->json('session');
        $this->travel(5)->minutes();

        // The window asks again: the server closes the session it finds idle and says so.
        $this->getJson('/support/thread?session='.$session)->assertJsonPath('state', 'closed')->assertJsonPath('reason', 'inactivity');
        $this->assertSame('inactivity', Conversation::findOrFail($session)->closed_reason);

        // A message sent from the stale window is refused (not appended to the closed session, not lost silently); a fresh one starts a new conversation.
        $this->write('Tarde', $session)->assertStatus(422);
        $this->write('Nueva')->assertOk();
        $this->assertSame(2, Conversation::count());
        $this->assertNotNull($client);
    }

    public function test_a_message_that_arrives_after_the_limit_starts_a_new_session_and_never_lands_in_the_closed_one(): void
    {
        $client = $this->as($this->a);
        $old = $this->write('Hola')->json('session');
        $this->travel(4)->minutes();
        $this->travel(1)->seconds();

        $new = $this->write('Llego tarde')->assertOk()->json('session'); // no session id: a person who closed the tab and came back

        $this->assertNotSame($old, $new);
        $this->assertSame('inactivity', Conversation::findOrFail($old)->closed_reason);
        $this->assertSame(['Hola'], Conversation::findOrFail($old)->messages()->where('sender', 'contact')->pluck('body')->all());
        $this->assertSame(['Llego tarde'], Conversation::findOrFail($new)->messages()->where('sender', 'contact')->pluck('body')->all());
        $this->assertNotNull($client);
    }

    public function test_the_scheduler_closes_each_idle_session_once_and_touches_nothing_else(): void
    {
        $this->as($this->a);
        $session = $this->write()->json('session');
        $this->as($this->b);
        $fresh = $this->write('Reciente')->json('session');
        $this->travel(5)->minutes();
        Conversation::whereKey($fresh)->update(['last_message_at' => now()]); // the other person wrote just now

        $this->assertSame(1, app(SupportSessions::class)->closeIdle());
        $this->assertSame(0, app(SupportSessions::class)->closeIdle(), 'a second run does nothing');
        $this->assertSame(1, Message::where('sender', 'system')->count(), 'one closing line, once');
        $this->assertNull(Conversation::findOrFail($fresh)->closed_at);
        $this->assertSame('inactivity', Conversation::findOrFail($session)->closed_reason);
    }

    public function test_the_closing_line_is_a_system_message_that_wakes_nothing_and_counts_as_no_news(): void
    {
        Bus::fake();
        $this->as($this->a);
        $session = $this->write('Hola')->json('session');
        $this->postJson('/support/close', ['session' => $session])->assertOk();

        $line = Message::where('sender', 'system')->firstOrFail();
        $this->assertSame(['out', null, false], [$line->direction, $line->status, $line->simulated]);
        $this->assertNull($line->external_id);
        Bus::assertNothingDispatched();
        $this->assertSame(0, ChatbotExecution::count(), 'the assistant is not woken');

        // It is not news for the team: the alerts only count messages written BY a contact.
        $this->as($this->dev);
        $cursor = $this->getJson('/conversations/alerts')->json('cursor');
        $this->assertSame([], $this->getJson('/conversations/alerts?after='.$cursor)->json('events'));
        $this->assertSame(0, Message::where('sender', 'system')->where('direction', 'in')->count());
    }

    public function test_a_client_only_ever_sees_its_current_session_and_never_the_internal_history(): void
    {
        $client = $this->as($this->a);
        $old = $this->write('Mensaje de la sesión vieja')->json('session');
        $this->postJson('/support/close', ['session' => $old])->assertOk();
        $this->write('Mensaje nuevo')->assertOk();

        $thread = $this->getJson('/support/thread')->assertOk();
        $this->assertSame(['Mensaje nuevo'], collect($thread->json('messages'))->pluck('text')->all());
        $this->assertStringNotContainsString('vieja', $thread->getContent());

        // Another person's session id gives nothing, from the thread or from closing it.
        $this->as($this->b);
        $this->getJson('/support/thread?session='.$old)->assertOk()->assertJsonPath('state', 'none');
        $this->assertNotNull($client);
    }

    public function test_the_four_minutes_come_from_the_configuration_and_the_command_is_scheduled(): void
    {
        $this->assertSame(4, SupportSessions::minutes());
        $this->assertStringContainsString("Schedule::command('conversations:maintain')->everyMinute()", (string) file_get_contents(base_path('routes/console.php')));
    }
}
