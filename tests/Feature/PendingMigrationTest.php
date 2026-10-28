<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Observability\ApiCallRecorder;
use App\Payments\PaymentSettings;
use App\Payments\WompiConfig;
use App\Services\ConversationAutoClose;
use App\Services\ConversationContext;
use App\Services\ConversationControl;
use App\Services\Notifications;
use App\Services\SupportSessions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The deployment can run the new code BEFORE migration 2026_11_13_100000 is applied. Everything that depends on it must switch itself off safely: the conversation flows work
 * exactly as before, nothing returns a server error, nothing is half-written, and the pages that need the tables say so instead of failing.
 */
class PendingMigrationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        Schema::dropIfExists('api_call_logs');
        Schema::dropIfExists('workspace_notification_reads');
        Schema::dropIfExists('workspace_notifications');
        Schema::dropIfExists('payment_provider_settings');
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('context'));
        Schema::table('workspace_settings', fn (Blueprint $table) => $table->dropColumn(['conversation_autoclose_enabled', 'conversation_autoclose_minutes']));

        foreach ([Notifications::class, ApiCallRecorder::class, PaymentSettings::class, ConversationContext::class, ConversationAutoClose::class] as $class) {
            $class::forgetReady();
        }
    }

    protected function tearDown(): void
    {
        // The next test has the tables again: forget the answer «not ready» so no other test inherits it.
        foreach ([Notifications::class, ApiCallRecorder::class, PaymentSettings::class, ConversationContext::class, ConversationAutoClose::class] as $class) {
            $class::forgetReady();
        }

        parent::tearDown();
    }

    public function test_conversation_flows_work_as_before_without_the_context_column(): void
    {
        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations', 'assistants']);
        $agent = $this->member($ws, 'admin');
        $bot = $ws->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $ws->id, 'channel' => 'web', 'contact_id' => 'visitante', 'last_message_at' => now()]);
        $control = app(ConversationControl::class);

        $control->requestHuman($conversation, 'necesita una persona');
        $this->assertSame(Conversation::PENDING, $conversation->refresh()->handling);
        $control->take($conversation, $agent);
        $control->returnToAi($conversation->refresh(), $agent, true);
        $this->assertSame(Conversation::AI, $conversation->refresh()->handling);

        $this->assertSame([], app(ConversationContext::class)->get($conversation)['facts']);
        $this->assertFalse(app(ConversationContext::class)->forWorkflow($conversation)['resume']);
    }

    public function test_the_context_endpoint_and_the_settings_that_need_the_migration_say_so_instead_of_failing(): void
    {
        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'settings', 'conversations', 'assistants']);
        $agent = $this->member($ws, 'admin');
        $bot = $ws->chatbots()->create(['name' => 'Asistente']);
        $conversation = $bot->conversations()->create(['workspace_id' => $ws->id, 'channel' => 'web', 'contact_id' => 'v', 'last_message_at' => now()]);
        app(ConversationControl::class)->take($conversation, $agent);
        $this->actingAs($agent)->withSession(['workspace_id' => $ws->id]);

        $this->post("/conversations/{$conversation->id}/context", ['facts' => ['phone' => '3001234567']])->assertStatus(409);
        $this->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->where('autoClose', null)->where('wompi', null));
        $this->put('/settings/conversations', ['enabled' => true, 'minutes' => 5])->assertStatus(409);
        $this->get('/conversations?c='.$conversation->id)->assertOk();
    }

    public function test_notifications_history_and_alerts_are_empty_not_errors(): void
    {
        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'integrations', 'conversations', 'assistants']);
        $this->actingAs($this->member($ws, 'admin'))->withSession(['workspace_id' => $ws->id]);

        $this->getJson('/notifications')->assertOk()->assertJsonPath('unread', 0)->assertJsonPath('items', []);
        $this->postJson('/notifications/1/read')->assertNotFound();
        $this->postJson('/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
        $this->getJson('/integrations/calls')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/integrations/calls/1')->assertNotFound();
        $this->getJson('/conversations/alerts?after=0&n=0')->assertOk()->assertJsonPath('handoffs', []);
        $this->get('/dashboard')->assertOk();
        $this->assertNull(app(Notifications::class)->notify($ws, 'x', 'T')); // nothing is stored, nothing breaks
    }

    public function test_wompi_falls_back_to_the_environment_and_cannot_be_saved_until_the_table_exists(): void
    {
        config(['wompi.environment' => 'sandbox', 'wompi.public_key' => 'pub_test_envkey', 'wompi.integrity_secret' => 'i', 'wompi.events_secret' => 'e']);
        $this->assertSame('environment', app(WompiConfig::class)->source());
        $this->assertTrue(app(WompiConfig::class)->usable());

        $dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        $dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        $this->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->where('wompi.source', 'environment')->where('wompi.environments.sandbox.state', 'incomplete'));
        $this->put('/settings/wompi', ['environment' => 'sandbox', 'public_key' => 'pub_test_x', 'events_secret' => 'e'])->assertStatus(409);
    }

    public function test_maintenance_support_sessions_and_the_recorder_keep_working(): void
    {
        $this->assertSame(0, Artisan::call('conversations:maintain'));
        $this->assertSame(0, app(ConversationAutoClose::class)->closeIdle());

        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations']);
        $bot = $ws->chatbots()->create(['name' => 'Soporte']);
        $conversation = $bot->conversations()->create(['workspace_id' => $ws->id, 'channel' => 'web', 'contact_id' => 'c1u1', 'last_message_at' => now()->subMinutes(10)]);
        $this->assertSame(0, app(SupportSessions::class)->closeIdle()); // an ordinary conversation: never closed by the clock, as before
        $this->assertNotSame(Conversation::RESOLVED, $conversation->refresh()->handling);

        Http::fake(['*' => Http::response(['ok' => true])]);
        $response = app(ApiCallRecorder::class)->call('x', 'op', 'GET', 'https://api.example.test/x', fn () => Http::get('https://api.example.test/x'));
        $this->assertTrue($response->successful());
    }
}
