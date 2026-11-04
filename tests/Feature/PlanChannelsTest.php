<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Workspace;
use App\Reminders\ReminderChannels;
use App\Services\ChatbotWorkflows;
use App\Services\ModuleAccess;
use App\Services\WorkspaceChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The channels a plan includes are a capability INDEPENDENT of its modules and of the user's role. Matrix of Workspaces (Web+correo, WhatsApp+Web+correo, Instagram+Messenger,
 * without WhatsApp, without Meta, all, administrative) checked in the server: the screen reflects them, and every operation (save, connect, activate, test, receive, remind) refuses
 * a channel the plan does not include even when the request is forged. Restricting a channel keeps its credentials and conversations.
 */
class PlanChannelsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'integrations', 'conversations', 'assistants', 'customers', 'reminders'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => '111222333444', 'services.meta.app_secret' => 'fb-app-secret-1234567890', 'services.meta.instagram_app_id' => '555666777888', 'services.meta.instagram_app_secret' => 'ig-app-secret-1234567890',
            'services.meta.webhook_verify_token' => 'verify-token-xyz',
            'n8n.legacy_environment' => 'all', 'n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.secret' => 'n8n-shared-SECRET',
            'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1', 'workspaces' => ['*']],
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => 'envia@empresa.co',
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:*' => Http::response('', 404)]);
        Queue::fake();
        $this->seedRoleCatalog();
    }

    /** @param  list<string>|null  $channels null = the plan never configured them */
    private function workspace(string $code, ?array $channels): Workspace
    {
        $ws = $this->bookingWorkspace($code, self::MODULES);
        $ws->plan->update(['channels' => $channels]);

        return $ws->refresh();
    }

    /** @return array<string, array{restricted: bool, available: bool}> what the Integraciones screen gets, by channel */
    private function screen(Workspace $ws): array
    {
        $this->app['auth']->forgetGuards();
        $this->actAsMember($ws);
        $props = $this->get('/integrations')->assertOk()->viewData('page')['props'];

        return collect($props['channels'])->mapWithKeys(fn ($c) => [$c['key'] => ['restricted' => $c['restricted'], 'available' => $c['available']]])->all();
    }

    public function test_the_matrix_of_workspaces_gets_exactly_the_channels_of_its_plan(): void
    {
        $webMail = $this->workspace('WEBMAIL', ['web', 'email']);
        $waWebMail = $this->workspace('WAWEBMAIL', ['whatsapp', 'web', 'email']);
        $metaOnly = $this->workspace('METAONLY', ['instagram', 'messenger']);
        $noWhatsapp = $this->workspace('NOWA', ['web', 'instagram', 'messenger', 'email']);
        $noMeta = $this->workspace('NOMETA', ['web', 'whatsapp', 'email']);
        $all = $this->workspace('ALL', WorkspaceChannels::keys());
        $legacy = $this->workspace('LEGACY', null);

        $channels = app(WorkspaceChannels::class);
        $this->assertEqualsCanonicalizing(['web', 'email'], $channels->allowed($webMail));
        $this->assertEqualsCanonicalizing(['whatsapp', 'web', 'email'], $channels->allowed($waWebMail));
        $this->assertEqualsCanonicalizing(['instagram', 'messenger'], $channels->allowed($metaOnly));
        $this->assertNotContains('whatsapp', $channels->allowed($noWhatsapp));
        $this->assertNotContains('instagram', $channels->allowed($noMeta));
        $this->assertNotContains('messenger', $channels->allowed($noMeta));
        $this->assertEqualsCanonicalizing(WorkspaceChannels::keys(), $channels->allowed($all));
        $this->assertEqualsCanonicalizing(WorkspaceChannels::keys(), $channels->allowed($legacy), 'a plan that never configured channels keeps every channel: nothing changes until an administrator chooses');

        // The screen reflects it: the restricted channel is shown as unavailable with the reason, never as something to configure.
        $screen = $this->screen($webMail);
        $this->assertFalse($screen['web']['restricted']);
        $this->assertTrue($screen['whatsapp']['restricted'] && ! $screen['whatsapp']['available']);
        $this->assertTrue($screen['instagram']['restricted'] && $screen['messenger']['restricted']);

        $screen = $this->screen($metaOnly);
        $this->assertTrue($screen['web']['restricted'] && $screen['whatsapp']['restricted']);
        $this->assertFalse($screen['instagram']['restricted']);
    }

    public function test_the_administrative_workspace_has_every_channel_whatever_its_plan_says_and_by_the_server(): void
    {
        $dev = $this->bookingWorkspace('DESARROLLO_DEV', ['dashboard']);
        $dev->plan->update(['channels' => ['web']]);

        $channels = app(WorkspaceChannels::class);
        $this->assertTrue($dev->refresh()->isAdministrative());
        $this->assertEqualsCanonicalizing(WorkspaceChannels::keys(), $channels->allowed($dev));

        $this->actAsMember($dev);
        $screen = collect($this->get('/integrations')->viewData('page')['props']['channels'])->keyBy('key');
        $this->assertFalse($screen['whatsapp']['restricted']);
    }

    public function test_a_channel_outside_the_plan_cannot_be_saved_connected_or_activated_even_with_a_forged_request(): void
    {
        $ws = $this->workspace('FORGE', ['web']);
        $this->actAsMember($ws);

        // Saving the channel's own integration (WhatsApp is not in the plan).
        $this->put('/integrations/channels/whatsapp', ['phone_number_id' => '123456789012345', 'access_token' => 'EAAB'.str_repeat('x', 40), 'business_account_id' => '999888777666'])->assertSessionHasErrors('channel');
        $this->assertSame(0, Integration::where('workspace_id', $ws->id)->where('type', 'whatsapp')->count());

        // Meta OAuth (Instagram and Messenger are not in the plan): nothing is started, no state is stored.
        $this->get('/integrations/meta/instagram/connect')->assertForbidden();
        $this->get('/integrations/meta/messenger/connect')->assertForbidden();
        $this->post('/integrations/meta/messenger/select', ['page_id' => '109876543210001'])->assertForbidden();

        // An integration that already existed (kept when the plan lost the channel) cannot be switched on or tested.
        $bot = $ws->chatbots()->create(['name' => 'Bot', 'instructions' => 'x']);
        $integration = $ws->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => false, 'config' => ['phone_number_id' => '123456789012345'], 'secrets' => ['access_token' => 'TOKEN']]);
        $this->post("/integrations/{$integration->id}/activate")->assertSessionHasErrors('channel');
        $this->assertFalse($integration->fresh()->is_active);

        // The chatbot channel cannot be turned on either.
        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true])->assertSessionHas('error');
        $this->assertSame(0, $bot->channels()->where('channel', 'whatsapp')->where('is_active', true)->count());
    }

    public function test_messages_of_a_channel_the_plan_does_not_include_are_not_received_but_nothing_is_deleted_and_it_works_again_when_included(): void
    {
        $ws = $this->workspace('KEEP', ['instagram']);
        $bot = $ws->chatbots()->create(['name' => 'Bot', 'instructions' => 'x']);
        $integration = $ws->integrations()->create(['name' => 'Instagram', 'type' => 'instagram', 'is_active' => true, 'config' => ['account_id' => '17841400000000001', 'webhook_subscribed_at' => now()->toIso8601String()], 'secrets' => ['access_token' => 'TOKEN-IG']]);
        $bot->channels()->create(['workspace_id' => $ws->id, 'channel' => 'instagram', 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'support');
        $event = fn (string $mid) => ['object' => 'instagram', 'entry' => [['id' => '17841400000000001', 'time' => 1_767_000_000_000, 'messaging' => [['sender' => ['id' => 'IGSID-1'], 'recipient' => ['id' => '17841400000000001'], 'timestamp' => 1_767_000_000_000, 'message' => ['mid' => $mid, 'text' => 'Hola']]]]]];
        $deliver = function (array $payload) {
            $raw = json_encode($payload);

            return $this->call('POST', '/api/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'ig-app-secret-1234567890')], $raw);
        };

        $deliver($event('mid.1'))->assertOk();
        $this->assertSame(1, Message::count());

        // The plan stops including Instagram: new messages are ignored; the connection, its token and the conversation stay.
        $ws->plan->update(['channels' => ['web']]);
        $deliver($event('mid.2'))->assertOk();
        $this->assertSame(1, Message::count(), 'nothing new is received');
        $this->assertSame(1, Conversation::count());
        $this->assertSame('TOKEN-IG', $integration->fresh()->secrets['access_token'], 'credentials are kept');

        // The plan includes it again: the same connection works without reconnecting.
        $ws->plan->update(['channels' => ['web', 'instagram']]);
        $deliver($event('mid.3'))->assertOk();
        $this->assertSame(2, Message::count());
    }

    public function test_reminder_channels_follow_the_plan(): void
    {
        $noEmail = $this->workspace('NOEMAIL', ['web']);
        $withEmail = $this->workspace('WITHEMAIL', ['email']);

        $offered = fn (Workspace $ws) => collect(app(ReminderChannels::class)->offered($ws))->keyBy('key');

        $this->assertFalse($offered($noEmail)['email']['available']);
        $this->assertSame('plan', app(ReminderChannels::class)->check($noEmail, 'email')['code']);
        $this->assertTrue($offered($withEmail)['email']['available']);
    }

    public function test_the_plan_editor_saves_channels_audits_them_and_only_the_platform_administration_can(): void
    {
        $dev = $this->bookingWorkspace('DESARROLLO_DEV', ['dashboard']);
        $client = $this->workspace('CLIENT', null);
        $plan = $client->plan;

        // A client's admin cannot reach the commercial administration at all.
        $this->actAsMember($client);
        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'channel_keys' => ['web']])->assertForbidden();
        $this->assertNull($plan->fresh()->channels);

        $this->app['auth']->forgetGuards();
        $user = $this->actAsMember($dev, 'plataforma');
        $user->forceFill(['is_superuser' => true])->save();

        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'channel_keys' => ['web', 'whatsapp', 'email']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['web', 'whatsapp', 'email'], $plan->fresh()->channels);

        // Unknown channel keys are refused; a request that does not send them changes nothing.
        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'channel_keys' => ['telegram']])->assertSessionHasErrors('channel_keys.0');
        $this->put("/plans/{$plan->id}", ['name' => $plan->name])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['web', 'whatsapp', 'email'], $plan->fresh()->channels);

        // The editor receives the catalog and the plan's current channels.
        $page = $this->get('/plans')->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(WorkspaceChannels::keys(), collect($page['commercial']['channels'])->pluck('key')->all());
    }

    public function test_the_plan_and_the_role_stay_two_independent_controls(): void
    {
        // Channel included by the plan, but the role cannot manage integrations: the server still refuses.
        $ws = $this->workspace('ROLE', WorkspaceChannels::keys());
        Role::findOrCreate('solo_ver', 'web')->syncPermissions(['view-integrations']);
        $this->actAsMember($ws, 'solo_ver');

        $this->put('/integrations/channels/web', ['name' => 'x'])->assertForbidden();
        $this->assertInstanceOf(Plan::class, $ws->plan);
    }

    public function test_a_read_only_web_request_works_out_the_modules_once_and_writes_and_the_console_never_reuse_it(): void
    {
        $ws = $this->bookingWorkspace('MEMO', ['dashboard', 'customers']);
        $access = app(ModuleAccess::class);
        $count = function (callable $fn): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $fn();

            return $queries;
        };

        // Console / tests (what jobs and commands are): every call is computed, so a change in the same run is seen at once.
        $this->assertEqualsCanonicalizing(['dashboard', 'customers'], $access->keys($ws));

        // Simulate a web GET: the answer is reused within that request.
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);
        $request = Request::create('/dashboard', 'GET');
        $this->app->instance('request', $request);

        $first = $count(fn () => $access->keys($ws));
        $second = $count(fn () => $access->allows($ws, 'customers') && $access->allows($ws, 'dashboard'));
        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'asked again inside the same read-only request: no query');

        // A write request never reuses it (it may change the plan and read again).
        $post = Request::create('/plans', 'POST');
        $this->app->instance('request', $post);
        $this->assertGreaterThan(0, $count(fn () => $access->keys($ws)));
        $this->assertGreaterThan(0, $count(fn () => $access->keys($ws)), 'a POST recomputes every time');

        $console->setValue($this->app, true);
    }
}
