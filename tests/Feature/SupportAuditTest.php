<?php

namespace Tests\Feature;

use App\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ConversationControl;
use App\Services\SupportDesk;
use App\Services\SupportSessions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The audit trail of the support sessions. `audit_logs.action` was varchar(16) and `closed_inactivity` (17 characters) did not fit: PostgreSQL refused the insert (SQLSTATE 22001)
 * while SQLite, which does not enforce lengths, hid it, so the schema is also checked on PostgreSQL (skipped on SQLite). The close and its audit are ONE transaction.
 */
class SupportAuditTest extends TestCase
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
        Storage::fake();
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

    private function write(string $text = 'Hola', ?int $session = null, array $files = [])
    {
        static $n = 0;

        return $this->post('/support/messages', ['message' => $text, 'message_id' => 'aud-'.str_pad((string) ++$n, 8, '0', STR_PAD_LEFT), 'session' => $session, 'files' => $files], ['Accept' => 'application/json']);
    }

    /** @return Collection<int, AuditLog> */
    private function trail(int $conversation)
    {
        return AuditLog::where('resource_type', 'conversation')->where('resource_id', $conversation)->whereIn('action', ['closed_client', 'closed_agent', 'closed_inactivity'])->get();
    }

    public function test_the_audit_action_column_fits_every_action_the_application_writes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Lengths are only enforced by PostgreSQL: run this against a disposable PostgreSQL database (see RECOMENDACIONES §58).');
        }

        $length = (int) DB::scalar("select character_maximum_length from information_schema.columns where table_name = 'audit_logs' and column_name = 'action'");
        $longest = collect(array_keys(config('audit.actions')))->map(fn (string $action) => strlen($action))->max();

        $this->assertGreaterThanOrEqual(32, $length);
        $this->assertGreaterThanOrEqual($longest, $length);
        $this->assertGreaterThan(16, strlen('closed_inactivity'), 'the action that used to be refused');
    }

    public function test_every_close_reason_is_a_registered_audit_action_with_a_label(): void
    {
        foreach (['closed_client', 'closed_agent', 'closed_inactivity'] as $action) {
            $this->assertArrayHasKey($action, config('audit.actions'));
            $this->assertNotEmpty(config("audit.actions.{$action}.label"));
        }
    }

    public function test_the_inactivity_close_is_audited_as_the_systems_event_in_the_support_workspace_even_when_a_clients_request_runs_it(): void
    {
        $client = $this->as($this->a);
        $session = $this->write()->json('session');

        $this->travel(5)->minutes();
        // The CLIENT's own poll is what finds the idle session and closes it: the event is still the system's, never the client's.
        $this->getJson('/support/thread?session='.$session)->assertOk()->assertJsonPath('state', 'closed')->assertJsonPath('reason', 'inactivity');

        $row = $this->trail($session)->sole();
        $this->assertSame(['closed_inactivity', 'system', 'success', $this->dev->id, null, 'conversation'], [$row->action, $row->actor, $row->outcome, $row->workspace_id, $row->user_id, $row->resource_type]);
        $this->assertSame('Sistema (inactividad)', $row->user_name);
        $this->assertSame('DESARROLLO_DEV', $row->workspace_code);
        $this->assertNotNull($row->created_at);
        $this->assertSame('Cerrada por inactividad', Conversation::findOrFail($session)->messages()->where('sender', 'system')->value('body'));
        $this->assertNotNull($client);
    }

    public function test_the_manual_closes_are_audited_with_their_own_actor_and_reason(): void
    {
        $client = $this->as($this->a);
        $one = $this->write('Uno')->json('session');
        $this->postJson('/support/close', ['session' => $one])->assertOk();
        $two = $this->write('Dos')->json('session');

        $agent = $this->as($this->dev, 'agente');
        $this->post("/conversations/{$two}/take");
        $this->post("/conversations/{$two}/resolve")->assertSessionHasNoErrors();

        $byClient = $this->trail($one)->sole();
        $byAgent = $this->trail($two)->sole();
        $this->assertSame(['closed_client', 'user', $client->id], [$byClient->action, $byClient->actor, $byClient->user_id]);
        $this->assertSame(['closed_agent', 'user', $agent->id], [$byAgent->action, $byAgent->actor, $byAgent->user_id]);
        $this->assertSame([$this->dev->id, $this->dev->id], [$byClient->workspace_id, $byAgent->workspace_id]);
        $this->assertSame('client', Conversation::findOrFail($one)->closed_reason);
        $this->assertSame('agent', Conversation::findOrFail($two)->closed_reason);
    }

    public function test_running_the_maintenance_twice_closes_and_audits_once(): void
    {
        $this->as($this->a);
        $session = $this->write()->json('session');
        $this->travel(5)->minutes();

        $this->artisan('conversations:maintain')->assertSuccessful();
        $this->artisan('conversations:maintain')->assertSuccessful();
        $this->getJson('/support/thread?session='.$session)->assertOk();

        $this->assertCount(1, $this->trail($session));
        $this->assertSame(1, Message::where('sender', 'system')->count());
        $this->assertSame('inactivity', Conversation::findOrFail($session)->closed_reason);
    }

    public function test_a_failing_audit_leaves_the_session_open_and_consistent_and_does_not_break_the_clients_panel(): void
    {
        $this->as($this->a);
        $session = $this->write()->json('session');
        $this->travel(5)->minutes();

        // The audit write fails (any reason): the close and its audit are one transaction, so NOTHING changes...
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function system(string $action, string $resource, ?int $resourceId, string $label, array $changes, Workspace $workspace, string $source, string $outcome = self::SUCCESS): AuditLog
            {
                throw new \RuntimeException('audit down');
            }
        });
        $this->app->forgetInstance(ConversationControl::class);
        $this->app->forgetInstance(SupportSessions::class);

        // ...and the person who only opened their panel gets an answer, not a server error.
        $this->getJson('/support/thread?session='.$session)->assertOk();
        $conversation = Conversation::findOrFail($session);
        $this->assertNull($conversation->closed_at);
        $this->assertNotSame(Conversation::RESOLVED, $conversation->handling);
        $this->assertSame(0, Message::where('sender', 'system')->count(), 'no closing line without its close');
        $this->assertSame(0, $this->trail($session)->count());

        // The scheduler does not blow up either, and it closes the session once the audit works again.
        $this->artisan('conversations:maintain')->assertSuccessful();
        $this->assertNull(Conversation::findOrFail($session)->closed_at);

        $this->app->forgetInstance(AuditLogger::class);
        $this->app->forgetInstance(ConversationControl::class);
        $this->app->forgetInstance(SupportSessions::class);
        $this->app->singleton(AuditLogger::class);
        $this->artisan('conversations:maintain')->assertSuccessful();
        $this->assertSame('inactivity', Conversation::findOrFail($session)->closed_reason);
        $this->assertCount(1, $this->trail($session));
    }

    public function test_another_workspace_can_neither_close_nor_audit_a_session_that_is_not_its_own(): void
    {
        $this->as($this->a);
        $session = $this->write()->json('session');

        $this->as($this->b);
        $this->postJson('/support/close', ['session' => $session])->assertOk(); // answered like any session that is not theirs: nothing happens
        $this->assertNull(Conversation::findOrFail($session)->closed_at);

        $this->post("/conversations/{$session}/resolve")->assertNotFound(); // the inbox of ANOTHER Workspace does not have it
        $this->post("/conversations/{$session}/take")->assertNotFound();

        $this->assertSame(0, AuditLog::where('resource_id', $session)->where('resource_type', 'conversation')->whereIn('action', ['closed_client', 'closed_agent', 'closed_inactivity', 'resolved', 'taken'])->count());
    }

    public function test_a_closed_session_keeps_its_messages_and_files_and_its_system_line_wakes_nothing(): void
    {
        $client = $this->as($this->a);
        $pdf = UploadedFile::fake()->createWithContent('factura.pdf', str_pad("%PDF-1.4\n%%EOF\n", 400, ' '));
        $session = $this->write('Con archivo', null, [$pdf])->assertOk()->json('session');
        $this->travel(5)->minutes();
        $this->artisan('conversations:maintain')->assertSuccessful();

        $conversation = Conversation::findOrFail($session);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame(1, $conversation->messages()->where('sender', 'contact')->count());
        $this->assertSame(1, MessageAttachment::where('conversation_id', $session)->count());
        $this->assertTrue(Storage::exists(MessageAttachment::firstOrFail()->storage_path));

        $line = $conversation->messages()->where('sender', 'system')->sole();
        $this->assertSame([null, false, null], [$line->status, $line->simulated, $line->external_id]);
        $this->assertSame(0, ChatbotExecution::count());
        $this->assertSame(SupportDesk::contactId($client, $this->a).'x'.$session, $conversation->contact_id);
        // The closing line did not move the clock that closed it.
        $this->assertTrue($conversation->last_message_at->lte(now()->subMinutes(4)));
    }
}
