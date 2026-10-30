<?php

namespace Tests\Feature;

use App\Console\Commands\AgentToolsPruneCommand;
use App\Exceptions\DemoNotAllowed;
use App\Models\AgentToolCall;
use App\Models\Chatbot;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\DemoConversations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * «Actividad técnica» of a conversation: the trace of what the assistant really asked Ava to do, scoped to ONE conversation and ONE Workspace, behind its own permission,
 * read only, paginated in the server and free of message text and secrets. The rows here are the ones AgentToolTrace writes when the real tool routes run.
 */
class ConversationActivityTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];

    private Workspace $workspace;

    private Workspace $other;

    private Chatbot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        Role::findOrCreate('sin_actividad', 'web')->syncPermissions(['view-conversations']);
        $this->workspace = $this->bookingWorkspace('ACT_A', self::MODULES);
        $this->other = $this->bookingWorkspace('ACT_B', self::MODULES);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Asistente']);
        $this->conversation = $this->conversation($this->bot, '573001112233');
    }

    private function conversation(Chatbot $bot, string $contact): Conversation
    {
        return $bot->conversations()->create(['workspace_id' => $bot->workspace_id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => 'Laura'])->refresh();
    }

    private function traceCall(Conversation $conversation, array $attributes = []): AgentToolCall
    {
        return AgentToolCall::create($attributes + [
            'workspace_id' => $conversation->workspace_id, 'chatbot_id' => $conversation->chatbot_id, 'conversation_id' => $conversation->id,
            'tool' => 'booking.availability', 'kind' => 'read', 'auth' => 'execution', 'status' => 'succeeded', 'http_status' => 200, 'duration_ms' => 42, 'finished_at' => now(),
        ]);
    }

    public function test_the_panel_lists_only_the_calls_of_this_conversation_with_their_outcome_and_no_secrets(): void
    {
        $execution = ChatbotExecution::create([
            'correlation_id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'chatbot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'message_id' => $this->conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'TEXTO-PRIVADO', 'external_id' => 'w1', 'sent_at' => now()])->id,
            'workflow_key' => 'support', 'n8n_workflow_id' => 'wf1', 'handling_version' => 0, 'status' => 'succeeded', 'attempts' => 2, 'started_at' => now()->subSecond(), 'finished_at' => now(),
        ]);
        $this->traceCall($this->conversation, ['execution_ref' => $execution->correlation_id]);
        $this->traceCall($this->conversation, ['tool' => 'booking.book', 'kind' => 'write', 'status' => 'failed', 'http_status' => 500, 'reason' => 'provider_error', 'replayed' => true]);
        $elsewhere = $this->conversation($this->bot, '573009998877');
        $this->traceCall($elsewhere, ['tool' => 'orders.store', 'kind' => 'write']);
        $this->actAsMember($this->workspace);

        $response = $this->getJson("/conversations/{$this->conversation->id}/activity")->assertOk();

        $this->assertCount(2, $response->json('calls'));
        $this->assertSame(['booking.book', 'booking.availability'], array_column($response->json('calls'), 'tool'));
        $this->assertSame('failed', $response->json('calls.0.status'));
        $this->assertTrue($response->json('calls.0.replayed'));
        $this->assertSame(2, $response->json('runs.0.attempts'));
        $this->assertSame('succeeded', $response->json('calls.1.run.status'));
        $this->assertStringNotContainsString('TEXTO-PRIVADO', $response->getContent());
        $this->assertStringNotContainsString('573001112233', $response->getContent());
    }

    public function test_an_empty_conversation_returns_an_empty_page_and_the_filter_and_pagination_work_in_the_server(): void
    {
        $this->actAsMember($this->workspace);
        $this->getJson("/conversations/{$this->conversation->id}/activity")->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('calls', []);

        foreach (range(1, 17) as $i) {
            $this->traceCall($this->conversation, ['status' => $i % 2 ? 'succeeded' : 'rejected']);
        }

        $first = $this->getJson("/conversations/{$this->conversation->id}/activity")->assertOk();
        $this->assertSame([17, 15, 2], [$first->json('meta.total'), count($first->json('calls')), $first->json('meta.last_page')]);
        $this->assertCount(2, $this->getJson("/conversations/{$this->conversation->id}/activity?page=2")->json('calls'));
        $this->assertSame(8, $this->getJson("/conversations/{$this->conversation->id}/activity?status=rejected")->json('meta.total'));
        $this->getJson("/conversations/{$this->conversation->id}/activity?status=hacked")->assertUnprocessable();
    }

    public function test_it_needs_its_own_permission_and_another_workspace_conversation_does_not_exist(): void
    {
        $this->traceCall($this->conversation);
        $foreign = $this->conversation($this->other->chatbots()->create(['name' => 'Otro']), '573000000000');
        $this->traceCall($foreign);

        $this->actAsMember($this->workspace, 'sin_actividad');
        $this->getJson("/conversations/{$this->conversation->id}/activity")->assertForbidden();
        $this->get('/conversations?c='.$this->conversation->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('selected.activity', false));

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->workspace);
        $this->getJson("/conversations/{$foreign->id}/activity")->assertNotFound();
        $this->getJson("/conversations/{$this->conversation->id}/activity")->assertOk();
        $this->get('/conversations?c='.$this->conversation->id)->assertInertia(fn (AssertableInertia $page) => $page->where('selected.activity', true));
    }

    public function test_the_panel_never_writes_and_a_workspace_without_conversations_cannot_open_it(): void
    {
        $this->traceCall($this->conversation);
        $this->actAsMember($this->workspace);

        $this->postJson("/conversations/{$this->conversation->id}/activity")->assertStatus(405);
        $this->deleteJson("/conversations/{$this->conversation->id}/activity")->assertStatus(405);
        $this->assertSame(1, AgentToolCall::count());

        $this->contractModules($this->workspace, ['dashboard']);
        $this->getJson("/conversations/{$this->conversation->id}/activity")->assertForbidden();
    }

    public function test_the_real_tool_route_leaves_a_row_that_the_panel_shows(): void
    {
        $token = app(AgentAccess::class)->generate($this->bot);
        $this->withToken($token)->getJson('/api/agent/booking/services?contact_id=573001112233&channel=whatsapp');

        $this->assertGreaterThanOrEqual(1, AgentToolCall::where('conversation_id', $this->conversation->id)->count());

        $this->actAsMember($this->workspace);
        $this->assertSame('booking.services', $this->getJson("/conversations/{$this->conversation->id}/activity")->json('calls.0.tool'));
    }

    public function test_the_demonstration_conversation_shows_a_flagged_simulated_journey_that_is_never_stored_as_real_activity(): void
    {
        $dev = $this->bookingWorkspace(Workspace::normalizeCode((string) config('workspace.admin_code')), self::MODULES);
        $demo = app(DemoConversations::class);

        $this->assertTrue($demo->generateActivityDemo($dev));
        $this->assertFalse($demo->generateActivityDemo($dev), 'idempotent');
        $conversation = Conversation::where('workspace_id', $dev->id)->where('demo_key', DemoConversations::ACTIVITY_KEY)->firstOrFail();
        $this->assertSame(DemoConversations::ACTIVITY_TITLE, $conversation->contact_name);
        $this->assertTrue($conversation->messages()->where('simulated', false)->doesntExist(), 'every message of the demonstration is marked simulated');

        $this->actAsMember($dev);
        $response = $this->getJson("/conversations/{$conversation->id}/activity")->assertOk();

        $this->assertTrue($response->json('simulated'));
        $this->assertCount(6, $response->json('calls'));
        $this->assertSame([6, 1], [$response->json('calls.0.step'), $response->json('calls.5.step')]);
        $this->assertSame('failed', collect($response->json('calls'))->firstWhere('httpStatus', 503)['status']);
        $this->assertTrue(collect($response->json('calls'))->contains('retryOfPrevious', true));
        $this->assertStringContainsString('simulada', $response->json('calls.0.label'));
        $this->assertSame(1, $this->getJson("/conversations/{$conversation->id}/activity?status=failed")->json('meta.total'));

        // Nothing of it lives in the tables of real activity, so no report, metric or reconciler can read it.
        $this->assertSame([0, 0], [AgentToolCall::count(), ChatbotExecution::count()]);

        // A REAL conversation of the same Workspace never receives the script, and the demonstration can be removed on its own.
        $real = $this->conversation($dev->chatbots()->first(), '573005550000');
        $this->assertFalse($this->getJson("/conversations/{$real->id}/activity")->json('simulated'));
        $this->assertSame(1, $demo->cleanActivityDemo($dev));
        $this->assertSame(0, Conversation::where('demo_key', DemoConversations::ACTIVITY_KEY)->count());
        $this->assertNotNull(Conversation::find($real->id), 'only the demonstration conversation is removed');
    }

    public function test_the_demonstration_is_refused_outside_the_administrative_workspace_and_outside_demo_environments(): void
    {
        $demo = app(DemoConversations::class);

        $this->expectException(DemoNotAllowed::class);
        $demo->generateActivityDemo($this->workspace);
    }

    public function test_the_demonstration_script_is_not_served_in_production_even_for_a_marked_conversation(): void
    {
        $dev = $this->bookingWorkspace(Workspace::normalizeCode((string) config('workspace.admin_code')), self::MODULES);
        app(DemoConversations::class)->generateActivityDemo($dev);
        $conversation = Conversation::where('demo_key', DemoConversations::ACTIVITY_KEY)->firstOrFail();
        config(['demo.environments' => ['local']]); // «testing» is no longer a demo environment
        $this->actAsMember($dev);

        $this->assertFalse($this->getJson("/conversations/{$conversation->id}/activity")->json('simulated'));
    }

    public function test_the_prune_command_removes_only_old_finished_calls(): void
    {
        $old = $this->traceCall($this->conversation);
        $oldOpen = $this->traceCall($this->conversation, ['status' => 'started']);
        $recent = $this->traceCall($this->conversation);
        AgentToolCall::whereIn('id', [$old->id, $oldOpen->id])->update(['created_at' => now()->subDays(config('chatbots.tool_trace_retention_days') + 1)]);

        Artisan::call('agent:tools:prune');

        $this->assertNull(AgentToolCall::find($old->id));
        $this->assertNotNull(AgentToolCall::find($oldOpen->id), 'a call without an outcome is kept for the reconciler');
        $this->assertNotNull(AgentToolCall::find($recent->id));
        $this->assertTrue(class_exists(AgentToolsPruneCommand::class));
    }
}
