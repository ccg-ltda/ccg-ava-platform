<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The web channel: install code, public key, and the public widget endpoints that relay to the Workspace's n8n webhook. */
class WebWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://n8n.example.com/webhook/ava-web';

    protected function setUp(): void
    {
        parent::setUp();

        // Hosts resolve to a public address, so no test touches the network or the real DNS.
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        // Nothing may reach the network, except the local Inertia SSR probes (the Vite dev server or the SSR process).
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function webIntegration(Workspace $workspace, string $hook = self::HOOK, array $origins = [], ?string $secret = null): Integration
    {
        return $workspace->integrations()->create([
            'name' => 'Web', 'type' => 'web', 'is_active' => true,
            'config' => ['webhook_url' => $hook, 'allowed_origins' => $origins],
            'secrets' => array_filter(['webhook_secret' => $secret]),
        ]);
    }

    /** A chatbot with its web channel switched on; returns [chatbot, public key]. */
    private function liveWidget(Workspace $workspace, string $name = 'Asistente', array $integration = []): array
    {
        $this->webIntegration($workspace, ...$integration);
        $bot = $workspace->chatbots()->create(['name' => $name]);
        $key = $this->activate($bot);

        return [$bot, $key];
    }

    private function activate(Chatbot $bot): string
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, 'admin');
        $this->withSession(['workspace_id' => $bot->workspace_id]);
        $bot->workspace->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);
        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('success');

        return ChatbotChannel::where('chatbot_id', $bot->id)->where('channel', 'web')->firstOrFail()->public_key;
    }

    // --- activating the channel ------------------------------------------------------------------------------

    public function test_the_web_channel_needs_its_integration_and_then_gives_the_install_code(): void
    {
        $workspace = $this->workspace('WS_A');
        $bot = $workspace->chatbots()->create(['name' => 'Asistente']);
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, 'admin');
        $workspace->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);
        $states = fn () => collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['channels'])->firstWhere('key', 'web');

        $this->assertSame('not_configured', $states()['state']);
        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('error');
        $this->assertSame(0, ChatbotChannel::count());

        $this->webIntegration($workspace);
        $this->assertSame('inactive', $states()['state']);
        $this->assertNull($states()['installCode']);

        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('success');
        $key = ChatbotChannel::firstOrFail()->public_key;
        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $key);
        $this->assertSame('active', $states()['state']);
        $this->assertSame('<script src="'.url('/widget/ava-widget.js').'" data-chatbot="'.$key.'" defer></script>', $states()['installCode']);
        $this->assertFalse($states()['testable']);
    }

    public function test_the_public_key_survives_switching_the_channel_off_and_on_and_is_unique(): void
    {
        $a = $this->workspace('WS_A');
        [$botA, $keyA] = $this->liveWidget($a);
        $this->put("/chatbots/{$botA->id}/channels/web", ['is_active' => false]);
        $this->assertNull(collect($this->get("/chatbots/{$botA->id}")->viewData('page')['props']['channels'])->firstWhere('key', 'web')['installCode']);

        $this->put("/chatbots/{$botA->id}/channels/web", ['is_active' => true]);
        $this->assertSame($keyA, ChatbotChannel::where('chatbot_id', $botA->id)->value('public_key'));

        [, $keyB] = $this->liveWidget($this->workspace('WS_B'), 'Otro');
        $this->assertNotSame($keyA, $keyB);
    }

    public function test_one_web_integration_serves_one_chatbot_of_the_workspace(): void
    {
        $workspace = $this->workspace('WS_A');
        [$one] = $this->liveWidget($workspace, 'Uno');
        $two = $workspace->chatbots()->create(['name' => 'Dos']);

        $this->put("/chatbots/{$two->id}/channels/web", ['is_active' => true])->assertSessionHas('error');
        $this->assertSame(1, ChatbotChannel::count());
        $this->assertSame($one->id, ChatbotChannel::firstOrFail()->chatbot_id);
    }

    // --- the public configuration ----------------------------------------------------------------------------

    public function test_the_widget_gets_the_chatbot_identity_and_the_workspace_global_appearance(): void
    {
        Storage::fake();
        $workspace = $this->workspace('WS_A');
        $workspace->settings()->create(['primary_color' => '#0f766e', 'appearance' => 'dark'] + WorkspaceSetting::defaults());
        [$bot, $key] = $this->liveWidget($workspace, 'Agente de ventas');
        $bot->update(['description' => 'Atiende ventas', 'avatar_path' => Storage::putFile('x', UploadedFile::fake()->image('a.png'))]);

        $response = $this->getJson("/api/widget/{$key}/config")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJson([
            'name' => 'Agente de ventas', 'description' => 'Atiende ventas', 'primaryColor' => '#0f766e', 'appearance' => 'dark',
        ])->assertJsonPath('avatarUrl', fn ($url) => str_contains($url, "/api/widget/{$key}/avatar"));

        // Nothing about the Workspace, the webhook or other chatbots goes to a public page.
        foreach (['WS_A', 'n8n.example.com', 'webhook', 'instructions', 'workspace'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $response->getContent());
        }
    }

    public function test_the_avatar_is_public_by_key_and_missing_when_there_is_none(): void
    {
        Storage::fake();
        [$bot, $key] = $this->liveWidget($this->workspace('WS_A'));

        $this->get("/api/widget/{$key}/avatar")->assertNotFound();

        $bot->update(['avatar_path' => Storage::putFile('x', UploadedFile::fake()->image('a.png'))]);
        $this->get("/api/widget/{$key}/avatar")->assertOk();
    }

    public function test_an_unknown_or_malformed_key_does_not_exist(): void
    {
        $this->getJson('/api/widget/'.str_repeat('a', 32).'/config')->assertNotFound();
        $this->getJson('/api/widget/short/config')->assertNotFound();
        $this->postJson('/api/widget/'.str_repeat('a', 32).'/messages', ['session_id' => 'abcdefgh1234', 'message' => 'hola'])->assertNotFound();
    }

    public function test_anything_switched_off_makes_the_key_disappear(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot, $key] = $this->liveWidget($workspace);
        $channel = ChatbotChannel::firstOrFail();
        $this->getJson("/api/widget/{$key}/config")->assertOk();

        $switches = [
            fn () => $channel->update(['is_active' => false]),
            fn () => $bot->update(['is_active' => false]),
            fn () => $workspace->update(['is_active' => false]),
            fn () => $workspace->organization->update(['is_active' => false]),
            fn () => $channel->integration->update(['is_active' => false]),
        ];
        $restores = [
            fn () => $channel->update(['is_active' => true]),
            fn () => $bot->update(['is_active' => true]),
            fn () => $workspace->update(['is_active' => true]),
            fn () => $workspace->organization->update(['is_active' => true]),
            fn () => $channel->integration->update(['is_active' => true]),
        ];

        foreach ($switches as $index => $off) {
            $off();
            $this->getJson("/api/widget/{$key}/config")->assertNotFound();
            $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'abcdefgh1234', 'message' => 'hola'])->assertNotFound();
            $restores[$index]();
        }

        $this->getJson("/api/widget/{$key}/config")->assertOk();
    }

    // --- relaying messages to n8n ----------------------------------------------------------------------------

    public function test_a_message_is_relayed_to_the_workspaces_webhook_and_its_reply_returned(): void
    {
        [$bot, $key] = $this->liveWidget($this->workspace('WS_A'), integration: [self::HOOK, [], 'shared-SECRET']);
        Http::fake([self::HOOK => Http::response(['reply' => '¡Hola! ¿En qué te ayudo?'])]);

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => '  Hola  '])
            ->assertOk()->assertExactJson(['reply' => '¡Hola! ¿En qué te ayudo?']);

        Http::assertSent(fn ($request) => $request->url() === self::HOOK
            && $request->method() === 'POST'
            && $request->hasHeader('X-Ava-Secret', 'shared-SECRET')
            && $request->data() === ['event' => 'message', 'channel' => 'web', 'chatbot_id' => $bot->id, 'session_id' => 'visitor-session-1', 'message' => 'Hola']);
    }

    public function test_the_visitor_cannot_choose_the_chatbot_or_the_webhook(): void
    {
        [$botA, $keyA] = $this->liveWidget($this->workspace('WS_A'), 'A', integration: ['https://n8n-a.example.com/hook']);
        [$botB] = $this->liveWidget($this->workspace('WS_B'), 'B', integration: ['https://n8n-b.example.com/hook']);
        Http::fake(['https://n8n-a.example.com/*' => Http::response(['reply' => 'de A']), 'https://n8n-b.example.com/*' => Http::response(['reply' => 'de B'])]);

        $this->postJson("/api/widget/{$keyA}/messages", [
            'session_id' => 'visitor-session-1', 'message' => 'hola',
            'chatbot_id' => $botB->id, 'webhook_url' => 'https://n8n-b.example.com/hook', 'workspace' => 'WS_B',
        ])->assertOk()->assertExactJson(['reply' => 'de A']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://n8n-a.example.com/') && $request->data()['chatbot_id'] === $botA->id);
    }

    public function test_the_message_is_validated(): void
    {
        [, $key] = $this->liveWidget($this->workspace('WS_A'));
        Http::fake();

        $this->postJson("/api/widget/{$key}/messages", [])->assertUnprocessable()->assertJsonValidationErrors(['session_id', 'message']);
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'short', 'message' => 'hola'])->assertJsonValidationErrors('session_id');
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'has spaces in it!', 'message' => 'hola'])->assertJsonValidationErrors('session_id');
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => ''])->assertJsonValidationErrors('message');
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => str_repeat('a', config('chatbots.widget.max_message') + 1)])->assertJsonValidationErrors('message');
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => ['x']])->assertJsonValidationErrors('message');

        Http::assertNothingSent();
    }

    public function test_a_failing_webhook_gives_the_visitor_a_generic_error_and_no_internal_detail(): void
    {
        [, $key] = $this->liveWidget($this->workspace('WS_A'), integration: [self::HOOK, [], 'shared-SECRET']);
        $send = fn () => $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => 'hola']);

        Http::fake([self::HOOK => Http::sequence()
            ->push('<html>stack trace shared-SECRET</html>', 500)
            ->push(['no_reply' => true])
            ->push(['reply' => '   '])
            ->push(['reply' => ['not', 'a string']])
            ->push('not json', 200)
            ->pushFailedConnection('cURL error 28: timed out '.self::HOOK)]);

        foreach (range(1, 5) as $ignored) {
            $response = $send()->assertStatus(502)->assertExactJson(['message' => 'El asistente no pudo responder.']);
            $this->assertStringNotContainsString('shared-SECRET', $response->getContent());
        }

        $send()->assertStatus(504)->assertExactJson(['message' => 'El asistente no está disponible en este momento.']);
    }

    public function test_a_webhook_pointing_at_an_internal_address_is_never_called(): void
    {
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['10.0.0.5']));
        [, $key] = $this->liveWidget($this->workspace('WS_A'), integration: ['http://internal.example.com/hook']);
        Http::fake();

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => 'hola'])->assertStatus(504);

        Http::assertNothingSent();
    }

    public function test_a_very_long_reply_is_cut(): void
    {
        [, $key] = $this->liveWidget($this->workspace('WS_A'));
        Http::fake([self::HOOK => Http::response(['reply' => str_repeat('a', 10000)])]);

        $reply = $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'visitor-session-1', 'message' => 'hola'])->assertOk()->json('reply');

        $this->assertSame((int) config('chatbots.widget.max_reply'), mb_strlen($reply));
    }

    // --- who may embed it, and abuse -------------------------------------------------------------------------

    public function test_allowed_sites_limit_who_can_use_the_widget(): void
    {
        [, $key] = $this->liveWidget($this->workspace('WS_A'), integration: [self::HOOK, ['https://www.cliente.com']]);
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $body = ['session_id' => 'visitor-session-1', 'message' => 'hola'];

        $this->postJson("/api/widget/{$key}/messages", $body)->assertForbidden();
        $this->withHeaders(['Origin' => 'https://evil.example.com'])->postJson("/api/widget/{$key}/messages", $body)->assertForbidden();
        $this->withHeaders(['Origin' => 'https://www.cliente.com.evil.com'])->getJson("/api/widget/{$key}/config")->assertForbidden();
        Http::assertNothingSent();

        $this->withHeaders(['Origin' => 'https://www.cliente.com'])->getJson("/api/widget/{$key}/config")->assertOk();
        $this->withHeaders(['Origin' => 'https://WWW.cliente.com/'])->postJson("/api/widget/{$key}/messages", $body)->assertOk();
    }

    public function test_without_a_list_any_site_may_use_it_and_cors_is_open(): void
    {
        [, $key] = $this->liveWidget($this->workspace('WS_A'));

        $this->withHeaders(['Origin' => 'https://anywhere.example.com'])->getJson("/api/widget/{$key}/config")
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');

        $this->withHeaders(['Origin' => 'https://anywhere.example.com', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'content-type'])
            ->options("/api/widget/{$key}/messages")->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_messages_are_rate_limited_per_visitor_and_widget(): void
    {
        config(['chatbots.widget.messages_per_minute' => 2]);
        [, $key] = $this->liveWidget($this->workspace('WS_A'));
        Http::fake([self::HOOK => Http::response(['reply' => 'ok'])]);
        $body = ['session_id' => 'visitor-session-1', 'message' => 'hola'];

        $this->postJson("/api/widget/{$key}/messages", $body)->assertOk();
        $this->postJson("/api/widget/{$key}/messages", $body)->assertOk();
        $this->postJson("/api/widget/{$key}/messages", $body)->assertStatus(429);
        Http::assertSentCount(2);
    }

    public function test_the_script_is_served_and_never_builds_html_from_the_network(): void
    {
        $script = file_get_contents(public_path('widget/ava-widget.js'));

        $this->assertStringContainsString('data-chatbot', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('document.write', $script);
        $this->assertStringNotContainsString('eval(', $script);
    }

    public function test_the_workspace_page_for_a_member_never_carries_the_webhook_secret(): void
    {
        $workspace = $this->workspace('WS_A');
        [$bot] = $this->liveWidget($workspace, integration: [self::HOOK, [], 'shared-SECRET-XYZ']);

        $this->get("/chatbots/{$bot->id}")->assertInertia(fn (AssertableInertia $page) => $page->component('Chatbots/Show'));
        $this->assertStringNotContainsString('shared-SECRET-XYZ', $this->get("/chatbots/{$bot->id}")->getContent());
        $this->assertStringNotContainsString('shared-SECRET-XYZ', $this->get('/integrations')->getContent());
    }
}
