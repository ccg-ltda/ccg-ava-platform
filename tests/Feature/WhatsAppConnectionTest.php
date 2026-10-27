<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** WhatsApp is reported as connected only when credentials, webhook, Meta's verification and real traffic have each been observed; no secret reaches the page. */
class WhatsAppConnectionTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        Cache::flush();
        config(['services.meta.app_secret' => null, 'services.meta.webhook_verify_token' => null]);
        $this->workspace = $this->bookingWorkspace('CLIENTE', ['dashboard', 'integrations', 'assistants', 'conversations']);
        $this->actAsMember($this->workspace);
    }

    private function connection(): array
    {
        $response = $this->get('/integrations');
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return collect($response->viewData('page')['props']['channels'])->firstWhere('key', 'whatsapp')['connection'];
    }

    private function integration(array $test = []): Integration
    {
        return $this->workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100200300'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN']] + $test);
    }

    private function stages(): array
    {
        return collect($this->connection()['stages'])->pluck('done', 'key')->all();
    }

    public function test_without_an_integration_nothing_is_claimed_and_the_webhook_url_is_the_real_one(): void
    {
        $connection = $this->connection();

        $this->assertSame('not_configured', $connection['state']);
        $this->assertSame(url('/api/webhooks/whatsapp'), $connection['webhook']['url']);
        $this->assertSame(['credentials' => false, 'webhook_configured' => false, 'webhook_verified' => false, 'traffic' => false], $this->stages());
    }

    public function test_a_webhook_url_is_flagged_as_unreachable_for_meta_unless_it_is_public_https(): void
    {
        foreach (['http://localhost:8000/api/webhooks/whatsapp', 'https://localhost/x', 'http://zonno.example.com/x', 'https://127.0.0.1/x', 'https://192.168.1.5/x', 'https://miequipo/x', 'https://app.local/x', 'https://zonno.test/x'] as $url) {
            $this->assertFalse(WhatsAppConnection::isPublicUrl($url), $url);
        }

        foreach (['https://zonno.example.com/api/webhooks/whatsapp', 'https://abc123.ngrok-free.app/x'] as $url) {
            $this->assertTrue(WhatsAppConnection::isPublicUrl($url), $url);
        }

        $this->assertFalse($this->connection()['webhook']['public'], 'the test application is served at a local address');
    }

    public function test_saved_credentials_are_pending_until_a_real_test_runs_and_a_failed_test_is_an_error(): void
    {
        $integration = $this->integration();
        $this->assertSame('pending', $this->connection()['state']);

        $integration->forceFill(['last_tested_at' => now(), 'last_test_ok' => false, 'last_test_message' => 'Meta rechazó el token'])->save();
        $this->assertSame(['error', 'Error de verificación'], [$this->connection()['state'], $this->connection()['label']]);
    }

    public function test_one_good_test_is_not_a_connection_each_remaining_stage_must_be_observed(): void
    {
        $this->integration()->forceFill(['last_tested_at' => now(), 'last_test_ok' => true])->save();
        $this->assertSame('partial', $this->connection()['state'], 'credentials alone');

        config(['services.meta.app_secret' => 'app-secret-value', 'services.meta.webhook_verify_token' => 'verify-token-value']);
        $this->assertSame('partial', $this->connection()['state'], 'webhook configured, not verified by Meta');

        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-token-value&hub_challenge=abc')->assertOk();
        $this->assertSame('partial', $this->connection()['state'], 'verified, no real message yet');
        $this->assertSame(['credentials' => true, 'webhook_configured' => true, 'webhook_verified' => true, 'traffic' => false], $this->stages());
    }

    public function test_a_wrong_verify_token_does_not_mark_the_webhook_as_verified(): void
    {
        config(['services.meta.app_secret' => 'app-secret-value', 'services.meta.webhook_verify_token' => 'verify-token-value']);

        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc')->assertForbidden();

        $this->assertNull(app(WhatsAppConnection::class)->verifiedAt());
    }

    public function test_only_a_real_inbound_message_completes_the_connection_and_the_page_never_carries_a_secret(): void
    {
        $integration = $this->integration();
        $integration->forceFill(['last_tested_at' => now(), 'last_test_ok' => true])->save();
        config(['services.meta.app_secret' => 'app-secret-value', 'services.meta.webhook_verify_token' => 'verify-token-value']);
        $this->get('/api/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-token-value&hub_challenge=abc')->assertOk();

        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente']);
        $bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        $token = app(AgentAccess::class)->generate($bot);

        // A simulated message (demo) is not traffic.
        $conversation = $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => 'demo-1', 'last_message_at' => now()]);
        $conversation->messages()->create(['workspace_id' => $this->workspace->id, 'direction' => 'in', 'type' => 'text', 'body' => 'x', 'simulated' => true, 'sent_at' => now()]);
        $this->assertFalse($this->stages()['traffic']);

        $this->withToken($token)->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola'])->assertSuccessful();

        $connection = $this->connection();
        $this->assertSame(['connected', 'Mensaje recibido · WhatsApp conectado', 'green'], [$connection['state'], $connection['label'], $connection['tone']]);

        $page = json_encode($this->get('/integrations')->viewData('page')['props']);
        foreach (['EAAB-SECRET-TOKEN', 'app-secret-value', 'verify-token-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
        }
    }

    public function test_the_traffic_of_another_workspace_is_not_evidence_for_this_one(): void
    {
        $this->integration()->forceFill(['last_tested_at' => now(), 'last_test_ok' => true])->save();
        $other = $this->bookingWorkspace('OTRO', ['dashboard', 'integrations', 'assistants', 'conversations']);
        $bot = $other->chatbots()->create(['name' => 'Otro']);
        $conversation = $bot->conversations()->create(['workspace_id' => $other->id, 'channel' => 'whatsapp', 'contact_id' => '573009998888', 'last_message_at' => now()]);
        $conversation->messages()->create(['workspace_id' => $other->id, 'direction' => 'in', 'type' => 'text', 'body' => 'Hola', 'sent_at' => now()]);

        $this->assertFalse($this->stages()['traffic']);
    }
}
