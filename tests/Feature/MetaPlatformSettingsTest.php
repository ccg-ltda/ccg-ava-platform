<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MetaPlatformSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChannelCatalog;
use App\Services\MetaConnection;
use App\Services\PlatformMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The platform's Meta app is configured ONCE from Configuraciones (platform administration, stored encrypted) instead of editing `.env` for every account. A saved value wins over the
 * environment, an empty one falls back to it, secrets never reach the browser, the logs or the audit, and the Workspaces' own connections are untouched by any change here.
 */
class MetaPlatformSettingsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private Workspace $dev;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.app_id' => null, 'services.meta.app_secret' => null, 'services.meta.instagram_app_id' => null, 'services.meta.instagram_app_secret' => null, 'services.meta.webhook_verify_token' => null]);
        PlatformMeta::forget();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:*' => Http::response('', 404)]);
        $this->seedRoleCatalog();
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', ['dashboard', 'integrations', 'settings']);
        $this->client = $this->bookingWorkspace('META_CLIENT', ['dashboard', 'integrations', 'conversations', 'assistants', 'settings']);
    }

    private function asPlatform(): User
    {
        $this->app['auth']->forgetGuards();
        $user = $this->actAsMember($this->dev, 'admin');
        $user->forceFill(['is_superuser' => true])->save();

        return $user;
    }

    /** @return array<string, string> */
    private function values(array $overrides = []): array
    {
        return $overrides + ['app_id' => '111222333444555', 'app_secret' => self::SECRET, 'instagram_app_id' => '555666777888999', 'instagram_app_secret' => 'f0e1d2c3b4a5968778695a4b3c2d1e0f', 'webhook_verify_token' => 'token-de-verificacion-123'];
    }

    public function test_the_workspace_sees_what_is_missing_and_how_it_is_fixed_without_naming_env_variables(): void
    {
        $this->actAsMember($this->client);
        $this->assertNotNull(MetaConnection::platformIssue('instagram'));

        $channel = collect(app(ChannelCatalog::class)->all($this->client))->firstWhere('key', 'instagram');
        $this->assertFalse($channel['available']);
        $this->assertStringNotContainsString('META_', (string) MetaConnection::platformIssue('instagram'), 'a customer is never told to edit .env');
        $this->assertStringContainsString('Integraciones', (string) MetaConnection::platformIssue('instagram'));
    }

    public function test_saving_in_ava_completes_the_platform_app_for_every_workspace_without_touching_env(): void
    {
        $this->asPlatform();

        $this->put('/settings/meta', $this->values())->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertNull(MetaConnection::platformIssue('instagram'));
        $this->assertNull(MetaConnection::platformIssue('messenger'));
        $this->assertSame('111222333444555', PlatformMeta::get('app_id'));
        $this->assertSame('database', PlatformMeta::source('app_secret'));
        $this->assertTrue(collect(app(ChannelCatalog::class)->all($this->client))->firstWhere('key', 'instagram')['available'], 'the Workspace can now connect: it asked for nothing');
    }

    public function test_secrets_are_encrypted_at_rest_and_never_reach_the_browser_the_logs_or_the_audit(): void
    {
        $this->asPlatform();
        $this->put('/settings/meta', $this->values())->assertSessionHasNoErrors();

        $raw = DB::table('meta_platform_settings')->first();
        $this->assertNotSame(self::SECRET, $raw->app_secret);
        $this->assertStringNotContainsString(self::SECRET, json_encode($raw));
        $this->assertSame(self::SECRET, MetaPlatformSetting::first()->app_secret, 'decrypted only inside the server');

        $page = json_encode($this->get('/integrations')->assertOk()->viewData('page')['props']['metaPlatform'], JSON_UNESCAPED_UNICODE);
        foreach ([self::SECRET, 'f0e1d2c3b4a5968778695a4b3c2d1e0f', 'token-de-verificacion-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
        }
        $this->assertStringContainsString('••••', $page, 'only a hint of the last characters');

        $audit = json_encode(AuditLog::where('resource_type', 'meta_platform_settings')->get()->toArray());
        $this->assertStringContainsString('App de Meta', $audit);
        $this->assertStringNotContainsString(self::SECRET, $audit);
    }

    public function test_formats_are_validated_before_saving_and_a_blank_field_keeps_the_stored_value(): void
    {
        $this->asPlatform();

        $this->put('/settings/meta', ['app_id' => 'abc', 'app_secret' => 'corto'])->assertSessionHasErrors(['app_id', 'app_secret']);
        $this->put('/settings/meta', ['webhook_verify_token' => 'corto', 'graph_version' => '26'])->assertSessionHasErrors(['webhook_verify_token', 'graph_version']);
        $this->assertSame(0, MetaPlatformSetting::count(), 'nothing half saved');

        $this->put('/settings/meta', $this->values())->assertSessionHasNoErrors();
        $this->put('/settings/meta', ['app_id' => '999888777666', 'app_secret' => ''])->assertSessionHasNoErrors();

        $this->assertSame('999888777666', PlatformMeta::get('app_id'));
        $this->assertSame(self::SECRET, PlatformMeta::get('app_secret'), 'a blank secret keeps what was stored');

        $this->put('/settings/meta', ['clear' => ['instagram_app_secret']])->assertSessionHasNoErrors();
        $this->assertNotNull(MetaConnection::platformIssue('instagram'), 'clearing a value makes the channel unavailable again');
    }

    public function test_the_database_wins_and_an_empty_value_falls_back_to_the_environment(): void
    {
        config(['services.meta.app_id' => '123456789012', 'services.meta.app_secret' => self::SECRET]);
        PlatformMeta::forget();

        $this->assertSame('environment', PlatformMeta::source('app_id'));
        $this->assertSame('123456789012', PlatformMeta::get('app_id'));

        $this->asPlatform();
        $this->put('/settings/meta', ['app_id' => '999888777666'])->assertSessionHasNoErrors();

        $this->assertSame('999888777666', PlatformMeta::get('app_id'));
        $this->assertSame('environment', PlatformMeta::source('app_secret'));
    }

    public function test_only_the_platform_administration_can_see_or_change_it(): void
    {
        $this->actAsMember($this->client);

        $this->put('/settings/meta', $this->values())->assertForbidden();
        $this->post('/settings/meta/check')->assertForbidden();
        $this->assertNull($this->get('/integrations')->viewData('page')['props']['metaPlatform'], 'a client never even receives the data');
        $this->assertArrayNotHasKey('meta', $this->get('/settings')->viewData('page')['props'], 'it no longer lives in Configuraciones');
        $this->assertSame(0, MetaPlatformSetting::count());

        $this->asPlatform();
        $this->assertNotNull($this->get('/integrations')->viewData('page')['props']['metaPlatform']);
    }

    public function test_checking_with_meta_reports_a_fixed_message_never_metas_text_and_records_the_result(): void
    {
        $this->asPlatform();
        $this->put('/settings/meta', $this->values())->assertSessionHasNoErrors();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '111222333444555'], 200), '127.0.0.1:*' => Http::response('', 404)]);
        $ok = $this->postJson('/settings/meta/check')->assertOk()->json();
        $this->assertTrue($ok['ok']);
        $this->assertNull($ok['results']['instagram']['ok'], 'Instagram cannot be proven without authorizing an account, and it says so');
        $this->assertSame('ok', MetaPlatformSetting::first()->validation_status);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token SECRET-ECHO', 'code' => 190]], 400), '127.0.0.1:*' => Http::response('', 404)]);
        $bad = $this->postJson('/settings/meta/check')->assertOk()->json();
        $this->assertFalse($bad['ok']);
        $this->assertStringNotContainsString('SECRET-ECHO', json_encode($bad));
        $this->assertSame('failed', MetaPlatformSetting::first()->validation_status);

        // Changing a credential clears the verification: it no longer describes what is saved.
        $this->put('/settings/meta', ['app_id' => '777888999000'])->assertSessionHasNoErrors();
        $this->assertNull(MetaPlatformSetting::first()->validation_status);
    }

    public function test_changing_the_platform_app_never_touches_the_connections_of_the_workspaces(): void
    {
        $integration = $this->client->integrations()->create(['name' => 'Instagram', 'type' => 'instagram', 'is_active' => true, 'config' => ['account_id' => '17841400000000001'], 'secrets' => ['access_token' => 'TOKEN-CLIENT']]);

        $this->asPlatform();
        $this->put('/settings/meta', $this->values())->assertSessionHasNoErrors();
        $this->put('/settings/meta', $this->values(['app_id' => '888777666555']))->assertSessionHasNoErrors();

        $this->assertSame('TOKEN-CLIENT', $integration->fresh()->secrets['access_token']);
        $this->assertTrue($integration->fresh()->is_active);
        $this->assertSame(1, MetaPlatformSetting::count(), 'one app for the platform, never a copy per Workspace');
    }

    public function test_the_webhook_uses_the_secrets_saved_in_ava(): void
    {
        $this->asPlatform();
        $this->put('/settings/meta', $this->values())->assertSessionHasNoErrors();

        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=token-de-verificacion-123&hub_challenge=777')->assertOk()->assertSee('777');
        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=otro&hub_challenge=777')->assertForbidden();

        $raw = json_encode(['object' => 'instagram', 'entry' => []]);
        $signed = fn (string $secret) => $this->call('POST', '/api/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret)], $raw);
        $signed('f0e1d2c3b4a5968778695a4b3c2d1e0f')->assertOk();
        $signed('un-secreto-que-no-es')->assertForbidden();
    }
}
