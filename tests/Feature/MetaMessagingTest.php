<?php

namespace Tests\Feature;

use App\Integrations\IntegrationRegistry;
use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\ChatbotWorkflows;
use App\Services\MetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Instagram Messaging and Messenger. Meta is NEVER called: every request to it is a fake (`Http::fake`) built from the shapes Meta documents (Instagram API with Instagram Login and the
 * Messenger Platform), so these tests prove Ava's side of the contract (OAuth state, tokens kept encrypted and away from the interface, signed webhooks, idempotency, isolation between
 * Workspaces, how replies and failures are read), not that Meta accepts it: that needs a real app and account (see RECOMENDACIONES.md).
 */
class MetaMessagingTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const APP_SECRET = 'fb-app-secret-1234567890';

    private const IG_SECRET = 'ig-app-secret-1234567890';

    private const IG_ID = '17841400000000001';

    private const PAGE_ID = '109876543210001';

    private Workspace $a;

    private Workspace $b;

    private Chatbot $botA;

    private Chatbot $botB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => '111222333444', 'services.meta.app_secret' => self::APP_SECRET, 'services.meta.instagram_app_id' => '555666777888', 'services.meta.instagram_app_secret' => self::IG_SECRET,
            'services.meta.webhook_verify_token' => 'verify-token-xyz',
            'n8n.legacy_environment' => 'all', 'n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.secret' => 'n8n-shared-SECRET',
            'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1', 'workspaces' => ['*']],
        ]);
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        $this->fakeHttp([]);
        Queue::fake();
        $this->seedRoleCatalog();
        Role::findOrCreate('sin_integraciones', 'web')->syncPermissions(['view-dashboard', 'view-integrations']);

        $modules = ['dashboard', 'integrations', 'conversations', 'assistants'];
        $this->a = $this->bookingWorkspace('META_A', $modules);
        $this->b = $this->bookingWorkspace('META_B', $modules);
        $this->botA = $this->a->chatbots()->create(['name' => 'Asistente A', 'instructions' => 'Atiende']);
        $this->botB = $this->b->chatbots()->create(['name' => 'Asistente B', 'instructions' => 'Atiende']);
    }

    /**
     * Replaces every earlier fake (a new stub for the same URL would never win over an old one) and keeps the SSR probe of the page shell (a local request, not Meta) answered.
     *
     * @param  array<string, mixed>  $stubs
     */
    private function fakeHttp(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs + ['127.0.0.1:*' => Http::response('', 404)]);
    }

    /** A connected channel of a Workspace: its integration, the chatbot channel that points at it and the workflow of the chatbot. */
    private function connected(Workspace $ws, Chatbot $bot, string $type, string $accountId, bool $active = true): Integration
    {
        $integration = $ws->integrations()->create(['name' => ucfirst($type), 'type' => $type, 'is_active' => $active, 'config' => ['account_id' => $accountId, 'account_name' => 'cuenta', 'webhook_subscribed_at' => now()->toIso8601String()], 'secrets' => ['access_token' => 'TOKEN-'.$type.'-'.$ws->id]]);
        $bot->channels()->create(['workspace_id' => $ws->id, 'channel' => $type, 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'support');

        return $integration;
    }

    // --- availability of the channels --------------------------------------------------------------------------------------------------

    public function test_a_channel_is_available_only_while_the_platform_has_its_meta_app_configured(): void
    {
        $this->actAsMember($this->a);
        $available = fn () => collect($this->get('/integrations')->viewData('page')['props']['channels'])->whereIn('key', ['instagram', 'messenger'])->mapWithKeys(fn ($c) => [$c['key'] => $c['available']])->all();

        $this->assertSame(['instagram' => true, 'messenger' => true], $available());

        config(['services.meta.instagram_app_secret' => null]);
        $this->assertSame(['instagram' => false, 'messenger' => true], $available());

        config(['services.meta.webhook_verify_token' => null]);
        $this->assertSame(['instagram' => false, 'messenger' => false], $available());
    }

    public function test_connecting_without_the_platform_app_is_refused_with_a_clear_reason(): void
    {
        config(['services.meta.app_id' => null]);
        $this->actAsMember($this->a);

        $this->get('/integrations/meta/messenger/connect')->assertRedirect(route('integrations.index'))->assertSessionHas('error');
        $this->assertSame(0, Integration::where('type', 'messenger')->count());
    }

    // --- Instagram: OAuth ------------------------------------------------------------------------------------------------------------

    private function startAuthorization(string $channel): string
    {
        $location = $this->get("/integrations/meta/{$channel}/connect")->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeInstagram(array $profile = ['user_id' => self::IG_ID, 'username' => 'tienda_demo', 'account_type' => 'BUSINESS'], bool $subscribed = true): void
    {
        $this->fakeHttp([
            'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'SHORT-IG', 'user_id' => 1, 'permissions' => 'instagram_business_basic,instagram_business_manage_messages']),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'LONG-IG-TOKEN', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.instagram.com/*/me*' => Http::response($profile),
            'graph.instagram.com/*/subscribed_apps' => Http::response(['success' => $subscribed], $subscribed ? 200 : 400),
        ]);
    }

    public function test_instagram_is_connected_with_a_state_bound_to_the_workspace_and_the_person(): void
    {
        $this->fakeInstagram();
        $this->actAsMember($this->a);

        $location = $this->get('/integrations/meta/instagram/connect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('555666777888', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('instagram_business_basic,instagram_business_manage_messages', $query['scope']);
        $this->assertSame(route('integrations.meta.callback'), $query['redirect_uri']);

        $this->get('/integrations/meta/callback?code=AUTHCODE&state='.$query['state'])->assertRedirect(route('integrations.index'))->assertSessionHas('success');

        $integration = Integration::where('type', 'instagram')->firstOrFail();
        $this->assertSame($this->a->id, $integration->workspace_id);
        $this->assertSame(self::IG_ID, $integration->config['account_id']);
        $this->assertSame('tienda_demo', $integration->config['account_name']);
        $this->assertSame('LONG-IG-TOKEN', $integration->secrets['access_token']);
        $this->assertNotNull($integration->config['webhook_subscribed_at']);
        $this->assertNotNull($integration->config['token_expires_at']);
        $this->assertStringNotContainsString('LONG-IG-TOKEN', (string) \DB::table('integrations')->where('id', $integration->id)->value('secrets'), 'the token is encrypted at rest');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.instagram.com/oauth/access_token') && $request['client_secret'] === self::IG_SECRET && $request['grant_type'] === 'authorization_code');
    }

    public function test_the_state_is_single_use_and_a_foreign_or_missing_one_connects_nothing(): void
    {
        $this->fakeInstagram();
        $this->actAsMember($this->a);
        $state = $this->startAuthorization('instagram');

        $this->get('/integrations/meta/callback?code=X&state=invented')->assertSessionHas('error');
        $this->get('/integrations/meta/callback?code=X')->assertSessionHas('error');
        $this->assertSame(0, Integration::count());

        // Another person (or another Workspace) cannot finish an authorization somebody else started.
        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->b);
        $this->get('/integrations/meta/callback?code=X&state='.$state)->assertSessionHas('error');
        $this->assertSame(0, Integration::count());

        // The legitimate person can, once; the same state does not work a second time.
        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->a);
        $own = $this->startAuthorization('instagram');
        $this->get('/integrations/meta/callback?code=X&state='.$own)->assertSessionHas('success');
        $this->get('/integrations/meta/callback?code=X&state='.$own)->assertSessionHas('error');
        $this->assertSame(1, Integration::count());
    }

    public function test_cancelling_in_meta_and_expired_states_connect_nothing(): void
    {
        $this->fakeInstagram();
        $this->actAsMember($this->a);
        $state = $this->startAuthorization('instagram');

        $this->get('/integrations/meta/callback?error=access_denied&error_reason=user_denied&state='.$state)->assertSessionHas('error');
        $this->assertSame(0, Integration::count());

        Cache::flush();
        $this->get('/integrations/meta/callback?code=X&state='.$state)->assertSessionHas('error');
        $this->assertSame(0, Integration::count());
    }

    public function test_a_personal_instagram_account_is_refused(): void
    {
        $this->fakeInstagram(['user_id' => self::IG_ID, 'username' => 'persona', 'account_type' => 'PERSONAL']);
        $this->actAsMember($this->a);

        $this->get('/integrations/meta/callback?code=X&state='.$this->startAuthorization('instagram'))->assertSessionHas('error');
        $this->assertSame(0, Integration::count());
    }

    public function test_an_account_already_connected_in_another_workspace_cannot_be_taken(): void
    {
        $this->connected($this->b, $this->botB, 'instagram', self::IG_ID);
        $this->fakeInstagram();
        $this->actAsMember($this->a);

        $this->get('/integrations/meta/callback?code=X&state='.$this->startAuthorization('instagram'))->assertSessionHas('error');
        $this->assertSame(0, $this->a->integrations()->count());
    }

    public function test_a_failed_webhook_subscription_is_shown_and_can_be_retried(): void
    {
        $this->fakeInstagram(subscribed: false);
        $this->actAsMember($this->a);
        $this->get('/integrations/meta/callback?code=X&state='.$this->startAuthorization('instagram'));

        $card = fn () => collect($this->get('/integrations')->viewData('page')['props']['channels'])->firstWhere('key', 'instagram');
        $this->assertSame('Conectado sin webhook', $card()['connection']['label']);

        $this->fakeInstagram();
        $this->post('/integrations/meta/instagram/subscribe')->assertSessionHas('success');
        $this->assertNotSame('Conectado sin webhook', $card()['connection']['label']);
    }

    // --- Messenger: OAuth and choosing a Page -------------------------------------------------------------------------------------------

    private function fakeMessenger(): void
    {
        $this->fakeHttp([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'USER-SHORT'])->push(['access_token' => 'USER-LONG']),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => self::PAGE_ID, 'name' => 'Mi Tienda', 'access_token' => 'PAGE-TOKEN-1'], ['id' => '109876543210002', 'name' => 'Otra página', 'access_token' => 'PAGE-TOKEN-2']]]),
            'graph.facebook.com/*/'.self::PAGE_ID.'/subscribed_apps' => Http::response(['success' => true]),
        ]);
    }

    public function test_messenger_asks_which_page_and_never_sends_the_page_tokens_to_the_browser(): void
    {
        $this->fakeMessenger();
        $this->actAsMember($this->a);

        $location = $this->get('/integrations/meta/messenger/connect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('pages_show_list,pages_messaging,pages_manage_metadata', $query['scope']);

        $this->get('/integrations/meta/callback?code=AUTH&state='.$query['state'])->assertSessionHas('success');
        $this->assertSame(0, Integration::count(), 'nothing is stored until the person chooses a Page');

        $page = $this->get('/integrations')->assertInertia(fn (AssertableInertia $p) => $p->has('metaPages', 2)->where('metaPages.0.name', 'Mi Tienda'));
        $this->assertStringNotContainsString('PAGE-TOKEN', $page->getContent());
        $this->assertStringNotContainsString('USER-LONG', $page->getContent());
    }

    public function test_the_chosen_page_is_connected_with_its_own_token_and_subscribed(): void
    {
        $this->fakeMessenger();
        $this->actAsMember($this->a);
        $this->get('/integrations/meta/callback?code=AUTH&state='.$this->startAuthorization('messenger'));

        $this->post('/integrations/meta/messenger/select', ['page_id' => self::PAGE_ID])->assertSessionHas('success');

        $integration = Integration::where('type', 'messenger')->firstOrFail();
        $this->assertSame([$this->a->id, self::PAGE_ID, 'Mi Tienda', 'PAGE-TOKEN-1'], [$integration->workspace_id, $integration->config['account_id'], $integration->config['account_name'], $integration->secrets['access_token']]);
        $this->assertNotNull($integration->config['webhook_subscribed_at']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), self::PAGE_ID.'/subscribed_apps') && $request['subscribed_fields'] === 'messages' && $request->hasHeader('Authorization', 'Bearer PAGE-TOKEN-1'));
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $p) => $p->where('metaPages', null));
    }

    public function test_a_page_that_was_not_authorized_or_a_selection_of_another_person_is_refused(): void
    {
        $this->fakeMessenger();
        $this->actAsMember($this->a);
        $this->get('/integrations/meta/callback?code=AUTH&state='.$this->startAuthorization('messenger'));
        $selection = session('meta_selection');

        $this->post('/integrations/meta/messenger/select', ['page_id' => '999999999999'])->assertSessionHas('error');
        $this->assertSame(0, Integration::count());

        // Another Workspace using the same selection id gets nothing.
        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->b);
        $this->withSession(['meta_selection' => $selection])->post('/integrations/meta/messenger/select', ['page_id' => self::PAGE_ID])->assertSessionHas('error');
        $this->assertSame(0, Integration::count());
    }

    // --- permissions and disconnecting -----------------------------------------------------------------------------------------------------

    public function test_a_role_without_manage_integrations_cannot_connect_or_disconnect(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->actAsMember($this->a, 'sin_integraciones');

        $this->get('/integrations/meta/instagram/connect')->assertForbidden();
        $this->get('/integrations/meta/callback?code=X&state=Y')->assertForbidden();
        $this->post('/integrations/meta/instagram/subscribe')->assertForbidden();
        $this->delete('/integrations/meta/instagram')->assertForbidden();
        $this->assertSame(1, Integration::count());
    }

    public function test_disconnecting_removes_the_connection_and_the_token_and_only_of_this_workspace(): void
    {
        $page = $this->connected($this->a, $this->botA, 'messenger', self::PAGE_ID);
        $this->connected($this->b, $this->botB, 'messenger', '109876543210009');
        $this->fakeHttp(['graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true, 'messaging_success' => true])]);
        $this->actAsMember($this->a);

        $this->delete('/integrations/meta/messenger')->assertSessionHas('success');

        $this->assertNull(Integration::find($page->id));
        $this->assertSame(1, Integration::where('workspace_id', $this->b->id)->count());
        $this->assertSame(0, $this->botA->channels()->count(), 'the chatbot channel went with the integration');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), self::PAGE_ID.'/subscribed_apps'));
    }

    public function test_the_forms_cannot_save_an_instagram_or_messenger_token_by_hand(): void
    {
        $this->actAsMember($this->a);

        $this->put('/integrations/channels/instagram', ['access_token' => 'MANUAL'])->assertSessionHasErrors();
        $this->put('/integrations/channels/messenger', ['access_token' => 'MANUAL'])->assertSessionHasErrors();
        $this->assertSame(0, Integration::count());
    }

    public function test_the_interface_never_receives_a_token(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->actAsMember($this->a);

        $this->assertStringNotContainsString('TOKEN-instagram', $this->get('/integrations')->getContent());
        $this->assertStringNotContainsString('TOKEN-instagram', $this->get("/chatbots/{$this->botA->id}?tab=canales")->getContent());
    }

    // --- connection test ----------------------------------------------------------------------------------------------------------------

    public function test_the_connection_test_reads_the_account_and_reports_fixed_messages(): void
    {
        $instagram = $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->actAsMember($this->a);

        $this->fakeHttp(['graph.instagram.com/*/me*' => Http::response(['user_id' => self::IG_ID, 'username' => 'tienda_demo'])]);
        $this->post("/integrations/{$instagram->id}/test");
        $this->assertTrue((bool) $instagram->fresh()->last_test_ok);
        $this->assertStringContainsString('tienda_demo', $instagram->fresh()->last_test_message);

        $this->fakeHttp(['graph.instagram.com/*/me*' => Http::response(['user_id' => '999', 'username' => 'otra'])]);
        $this->post("/integrations/{$instagram->id}/test");
        $this->assertFalse((bool) $instagram->fresh()->last_test_ok);
        $this->assertStringContainsString('distinta', $instagram->fresh()->last_test_message);

        $this->fakeHttp(['graph.instagram.com/*/me*' => Http::response(['error' => ['message' => 'SECRET TEXT OF META', 'code' => 190]], 401)]);
        $this->post("/integrations/{$instagram->id}/test");
        $this->assertStringContainsString('venció', $instagram->fresh()->last_test_message);
        $this->assertStringNotContainsString('SECRET TEXT', $instagram->fresh()->last_test_message);
    }

    // --- sending replies ---------------------------------------------------------------------------------------------------------------------

    public function test_a_reply_goes_to_the_right_endpoint_with_the_accounts_own_token(): void
    {
        $instagram = $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $messenger = $this->connected($this->a, $this->botA, 'messenger', self::PAGE_ID);
        $this->fakeHttp(['graph.instagram.com/*' => Http::response(['recipient_id' => 'IGSID1', 'message_id' => 'mid.ig.1']), 'graph.facebook.com/*' => Http::response(['recipient_id' => 'PSID1', 'message_id' => 'mid.fb.1'])]);

        $ig = app(IntegrationRegistry::class)->get('instagram')->send($instagram, 'IGSID1', 'Hola');
        $fb = app(IntegrationRegistry::class)->get('messenger')->send($messenger, 'PSID1', 'Hola');

        $this->assertSame(['mid.ig.1', 'mid.fb.1'], [$ig->externalId, $fb->externalId]);
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.instagram.com/v26.0/'.self::IG_ID.'/messages' && $r->hasHeader('Authorization', 'Bearer TOKEN-instagram-'.$this->a->id) && $r['recipient']['id'] === 'IGSID1' && $r['message']['text'] === 'Hola');
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v26.0/'.self::PAGE_ID.'/messages' && $r['messaging_type'] === 'RESPONSE' && $r['recipient']['id'] === 'PSID1');
    }

    public function test_a_refusal_is_failed_a_lost_answer_is_uncertain_and_meta_text_is_never_echoed(): void
    {
        $messenger = $this->connected($this->a, $this->botA, 'messenger', self::PAGE_ID);
        $type = app(IntegrationRegistry::class)->get('messenger');
        $send = function (int $status, array $body) use ($type, $messenger) {
            $this->fakeHttp(['graph.facebook.com/*' => Http::response($body, $status)]);

            return $type->send($messenger, 'PSID1', 'Hola');
        };

        $expired = $send(401, ['error' => ['code' => 190, 'message' => 'RAW META TEXT']]);
        $window = $send(400, ['error' => ['code' => 10, 'message' => 'RAW META TEXT']]);
        $limited = $send(429, []);
        $server = $send(503, []);

        $this->assertFalse($expired->ok || $expired->uncertain);
        $this->assertStringContainsString('venció', $expired->reason);
        $this->assertStringContainsString('ventana', $window->reason);
        $this->assertTrue($limited->retryable);
        $this->assertTrue($server->uncertain, 'a server error may have delivered it: never retried by itself');
        foreach ([$expired, $window, $limited, $server] as $result) {
            $this->assertStringNotContainsString('RAW META TEXT', (string) $result->reason);
        }
    }

    // --- the webhook -----------------------------------------------------------------------------------------------------------------------------

    private function deliver(array $payload, ?string $secret = self::IG_SECRET, ?string $signature = null): TestResponse
    {
        $raw = json_encode($payload);
        $header = $signature ?? 'sha256='.hash_hmac('sha256', $raw, (string) $secret);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $header], $raw);
    }

    private function instagramEvent(string $accountId, string $mid, array $message = ['text' => 'Hola, ¿tienen disponible?'], string $sender = 'IGSID-CLIENTE-1'): array
    {
        return ['object' => 'instagram', 'entry' => [['id' => $accountId, 'time' => 1_767_000_000_000, 'messaging' => [['sender' => ['id' => $sender], 'recipient' => ['id' => $accountId], 'timestamp' => 1_767_000_000_000, 'message' => ['mid' => $mid] + $message]]]]];
    }

    public function test_the_webhook_handshake_needs_the_verify_token(): void
    {
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-token-xyz&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();

        config(['services.meta.webhook_verify_token' => null]);
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-token-xyz&hub_challenge=1')->assertNotFound();
    }

    public function test_an_event_without_a_valid_signature_is_refused_before_anything_is_stored(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $event = $this->instagramEvent(self::IG_ID, 'mid.1');

        $this->deliver($event, signature: 'sha256=00')->assertForbidden();
        $this->deliver($event, secret: 'another-secret-entirely')->assertForbidden();
        $this->call('POST', '/api/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_an_instagram_message_is_stored_in_the_right_workspace_once_and_starts_the_chatbot(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->connected($this->b, $this->botB, 'instagram', '17841400000000002');

        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.1'))->assertOk();
        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.1'))->assertOk(); // Meta retries

        $conversation = Conversation::firstOrFail();
        $this->assertSame([$this->botA->id, 'instagram', 'IGSID-CLIENTE-1'], [$conversation->chatbot_id, $conversation->channel, $conversation->contact_id]);
        $this->assertSame(1, Message::count(), 'the repeated delivery stored nothing twice');
        $this->assertSame('Hola, ¿tienen disponible?', Message::firstOrFail()->body);
        $this->assertSame(0, Conversation::where('workspace_id', $this->b->id)->count(), 'the other Workspace received nothing');
        Queue::assertPushed(RunChatbotExecution::class, 1);
    }

    public function test_a_messenger_message_and_the_list_shaped_notification_are_handled(): void
    {
        $this->connected($this->a, $this->botA, 'messenger', self::PAGE_ID);
        $event = ['object' => 'page', 'entry' => [['id' => self::PAGE_ID, 'time' => 1_767_000_000_000, 'messaging' => [['sender' => ['id' => 'PSID-9'], 'recipient' => ['id' => self::PAGE_ID], 'timestamp' => 1_767_000_000_000, 'message' => ['mid' => 'm_abc', 'text' => 'Buenas']]]]]];

        $this->deliver([$event], self::APP_SECRET)->assertOk();

        $this->assertSame(['messenger', 'PSID-9', 'Buenas'], [Conversation::firstOrFail()->channel, Conversation::firstOrFail()->contact_id, Message::firstOrFail()->body]);
    }

    public function test_echoes_unknown_accounts_inactive_integrations_and_other_objects_are_ignored(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->connected($this->b, $this->botB, 'instagram', '17841400000000002', active: false);

        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.echo', ['text' => 'respuesta propia', 'is_echo' => true]))->assertOk();
        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.self', ['text' => 'yo'], sender: self::IG_ID))->assertOk();
        $this->deliver($this->instagramEvent('17841499999999999', 'mid.unknown'))->assertOk();
        $this->deliver($this->instagramEvent('17841400000000002', 'mid.inactive'))->assertOk();
        $this->deliver(['object' => 'whatsapp_business_account', 'entry' => []])->assertOk();
        $this->deliver(['object' => 'instagram', 'entry' => [['id' => self::IG_ID, 'messaging' => [['sender' => ['id' => 'X1'], 'read' => ['mid' => 'm']]]]]])->assertOk();

        $this->assertSame(0, Message::count());
        Queue::assertNothingPushed();
    }

    public function test_an_account_claimed_by_two_integrations_is_ignored_never_guessed(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->connected($this->b, $this->botB, 'instagram', self::IG_ID);

        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.dup'))->assertOk();

        $this->assertSame(0, Message::count());
    }

    public function test_a_workspace_without_conversations_or_assistants_in_its_plan_receives_nothing(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $this->contractModules($this->a, ['dashboard', 'integrations']);

        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.plan'))->assertOk();

        $this->assertSame(0, Message::count());
    }

    public function test_an_attachment_is_stored_as_its_type_without_downloading_anything(): void
    {
        $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);

        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.img', ['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.example.com/a.jpg']]]]))->assertOk();

        $message = Message::firstOrFail();
        $this->assertSame(['image', null], [$message->type, $message->body]);
        Http::assertNothingSent();
    }

    // --- renewing the Instagram authorization ----------------------------------------------------------------------------------------

    public function test_the_refresh_command_renews_only_what_is_close_to_expiring_and_prints_no_token(): void
    {
        $soon = $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $soon->update(['config' => $soon->config + ['connected_at' => now()->subDays(40)->toIso8601String(), 'token_expires_at' => now()->addDays(10)->toIso8601String()]]);
        $far = $this->connected($this->b, $this->botB, 'instagram', '17841400000000002');
        $far->update(['config' => $far->config + ['connected_at' => now()->subDays(5)->toIso8601String(), 'token_expires_at' => now()->addDays(55)->toIso8601String()]]);
        $this->fakeHttp(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'RENEWED-TOKEN', 'token_type' => 'bearer', 'expires_in' => 5183944])]);

        Artisan::call('meta:refresh-tokens');

        $this->assertStringNotContainsString('RENEWED-TOKEN', Artisan::output());
        $this->assertSame('RENEWED-TOKEN', $soon->fresh()->secrets['access_token']);
        $this->assertSame('TOKEN-instagram-'.$this->b->id, $far->fresh()->secrets['access_token'], 'far from expiring: untouched');
        Http::assertSentCount(1);
    }

    public function test_an_unreachable_platform_issue_is_reported_without_secrets(): void
    {
        config(['services.meta.app_id' => null]);

        $this->assertStringContainsString('App ID y secreto de Facebook', (string) MetaConnection::platformIssue('messenger'));
        $this->assertNull(MetaConnection::platformIssue('instagram'));
    }

    // --- four Workspaces with different channels -----------------------------------------------------------------------------------------

    public function test_four_workspaces_with_different_channels_each_receive_only_their_own_messages_and_see_only_their_own_connections(): void
    {
        $modules = ['dashboard', 'integrations', 'conversations', 'assistants'];
        $c = $this->bookingWorkspace('META_C', $modules);
        $d = $this->bookingWorkspace('META_D', $modules);
        $botC = $c->chatbots()->create(['name' => 'Asistente C', 'instructions' => 'Atiende']);
        $botD = $d->chatbots()->create(['name' => 'Asistente D', 'instructions' => 'Atiende']);

        $igA = '17841400000000011';
        $igB = '17841400000000012';
        $pageB = '109876543210012';
        $pageD = '109876543210014';

        // A: WhatsApp + Instagram. B: Messenger + Instagram. C: Web + WhatsApp. D: Messenger only.
        $this->a->integrations()->create(['name' => 'WhatsApp A', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '1001'], 'secrets' => ['access_token' => 'TOKEN-wa-A']]);
        $this->connected($this->a, $this->botA, 'instagram', $igA);
        $this->connected($this->b, $this->botB, 'messenger', $pageB);
        $this->connected($this->b, $this->botB, 'instagram', $igB);
        $c->integrations()->create(['name' => 'WhatsApp C', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '1003'], 'secrets' => ['access_token' => 'TOKEN-wa-C']]);
        $this->connected($d, $botD, 'messenger', $pageD);

        $this->deliver($this->instagramEvent($igA, 'mid.A1', sender: 'IGSID-A'))->assertOk();
        $this->deliver($this->instagramEvent($igB, 'mid.B1', sender: 'IGSID-B'))->assertOk();
        $messenger = fn (string $page, string $mid, string $sender) => ['object' => 'page', 'entry' => [['id' => $page, 'time' => 1_767_000_000_000, 'messaging' => [['sender' => ['id' => $sender], 'recipient' => ['id' => $page], 'timestamp' => 1_767_000_000_000, 'message' => ['mid' => $mid, 'text' => 'hola '.$mid]]]]]];
        $this->deliver($messenger($pageB, 'm.B2', 'PSID-B'), self::APP_SECRET)->assertOk();
        $this->deliver($messenger($pageD, 'm.D1', 'PSID-D'), self::APP_SECRET)->assertOk();
        // A Messenger event for D's page that names an Instagram account of A is not routed by guess either.
        $this->deliver($this->instagramEvent($pageD, 'mid.cross', sender: 'IGSID-X'))->assertOk();

        $received = fn (Workspace $ws) => Conversation::where('workspace_id', $ws->id)->orderBy('id')->get()->map(fn ($conversation) => [$conversation->channel, $conversation->contact_id])->all();
        $this->assertSame([['instagram', 'IGSID-A']], $received($this->a));
        $this->assertSame([['instagram', 'IGSID-B'], ['messenger', 'PSID-B']], $received($this->b));
        $this->assertSame([], $received($c), 'C has no Meta messaging channel: nothing reaches it');
        $this->assertSame([['messenger', 'PSID-D']], $received($d));
        $this->assertSame(4, Message::count());

        // Each Workspace sees ITS connections only: another Workspace's named connection never reaches the page, and no token ever does.
        foreach ([[$this->a, ['WhatsApp C']], [$this->b, ['WhatsApp A', 'WhatsApp C']], [$c, ['WhatsApp A']], [$d, ['WhatsApp A', 'WhatsApp C']]] as [$ws, $doesNotSee]) {
            $this->app['auth']->forgetGuards();
            $this->actAsMember($ws);
            $html = $this->get('/integrations')->assertOk()->getContent();
            $this->assertStringNotContainsString('TOKEN-', $html);

            foreach ($doesNotSee as $name) {
                $this->assertStringNotContainsString($name, $html);
            }
        }

        $this->assertSame(['instagram', 'whatsapp'], $this->a->integrations()->orderBy('type')->pluck('type')->all());
        $this->assertSame(['instagram', 'messenger'], $this->b->integrations()->orderBy('type')->pluck('type')->all());
        $this->assertSame(['whatsapp'], $c->integrations()->pluck('type')->all());
        $this->assertSame(['messenger'], $d->integrations()->pluck('type')->all());
    }

    public function test_the_same_external_account_in_two_workspaces_is_never_routed_to_either_and_each_keeps_its_own_token(): void
    {
        $a = $this->connected($this->a, $this->botA, 'instagram', self::IG_ID);
        $b = $this->connected($this->b, $this->botB, 'instagram', self::IG_ID);

        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.shared'))->assertOk();

        $this->assertSame(0, Message::count(), 'an ambiguous account delivers to nobody');
        $this->assertNotSame($a->secrets['access_token'], $b->fresh()->secrets['access_token'], 'each connection keeps its own token');

        // Disconnecting one leaves the other working: the account is no longer ambiguous.
        $b->delete();
        $this->deliver($this->instagramEvent(self::IG_ID, 'mid.after', sender: 'IGSID-Z'))->assertOk();

        $this->assertSame([$this->a->id], Conversation::pluck('workspace_id')->all());
    }
}
