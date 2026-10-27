<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Workspace;
use App\Services\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The webhook of a Workspace's own WhatsApp number: the form saves Access Token, WABA ID, Client ID, Client Secret and Verify Token (secrets write-only), Zonno generates the
 * public URL, answers Meta's handshake with that integration's token, verifies each event's signature with that integration's Client Secret and uses only events of its own number.
 * Nothing here reaches Meta: the HTTP client refuses any request.
 */
class WhatsAppIntegrationWebhookTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const SECRET = 'client-secret-of-the-meta-app-0123';

    private const VERIFY = 'my-verify-token-123';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['127.0.0.1*' => Http::response('', 404), 'localhost*' => Http::response('', 404)]);
        Cache::flush();
        config(['services.meta.app_secret' => null, 'services.meta.webhook_verify_token' => null]);
        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('CLIENTE', ['dashboard', 'integrations', 'assistants', 'conversations']);
        $this->actAsMember($this->workspace);
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'phone_number_id' => '100200300', 'waba_id' => '555666777', 'access_token' => 'EAAB-TOKEN-VALUE', 'client_id' => '888999000', 'client_secret' => self::SECRET, 'verify_token' => self::VERIFY,
        ];
    }

    private function saveForm(array $extra = []): Integration
    {
        $this->put('/integrations/channels/whatsapp', $this->form($extra))->assertSessionHasNoErrors();

        return Integration::where('type', 'whatsapp')->firstOrFail();
    }

    private function url(Integration $integration): string
    {
        return '/api/webhooks/whatsapp/'.$integration->config['webhook_key'];
    }

    private function signed(Integration $integration, array $payload, string $secret = self::SECRET)
    {
        $raw = json_encode($payload);

        return $this->call('POST', $this->url($integration), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret)], $raw);
    }

    private function payload(string $phoneId, string $id = 'wamid.A1', string $text = 'Hola'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phoneId], 'contacts' => [['wa_id' => '573001112233', 'profile' => ['name' => 'Ana']]],
            'messages' => [['id' => $id, 'from' => '573001112233', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function connect(Integration $integration)
    {
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente']);
        $bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);

        return $bot;
    }

    public function test_the_form_saves_every_field_generates_the_public_url_and_never_shows_a_secret_again(): void
    {
        $integration = $this->saveForm();

        $this->assertSame(['555666777', '888999000'], [$integration->config['waba_id'], $integration->config['client_id']]);
        $this->assertSame(48, strlen($integration->config['webhook_key']));
        $this->assertSame(['EAAB-TOKEN-VALUE', self::SECRET, self::VERIFY], [$integration->secrets['access_token'], $integration->secrets['client_secret'], $integration->secrets['verify_token']]);
        $this->assertStringNotContainsString(self::SECRET, (string) $integration->getRawOriginal('secrets'), 'secrets are encrypted at rest');

        $page = json_encode($this->get('/integrations')->assertOk()->viewData('page')['props']);
        foreach (['EAAB-TOKEN-VALUE', self::SECRET, self::VERIFY] as $secret) {
            $this->assertStringNotContainsString($secret, $page);
        }
        $this->assertStringContainsString(str_replace('/', '\/', url($this->url($integration))), $page);
        $this->assertStringContainsString('"client_secret_set":true', $page);

        // Editing without retyping the secrets keeps them, and the public URL does not change.
        $this->saveForm(['access_token' => '', 'client_secret' => '', 'verify_token' => '']);
        $again = $integration->fresh();
        $this->assertSame([self::SECRET, self::VERIFY, $integration->config['webhook_key']], [$again->secrets['client_secret'], $again->secrets['verify_token'], $again->config['webhook_key']]);
    }

    public function test_invalid_form_values_are_refused(): void
    {
        $this->put('/integrations/channels/whatsapp', $this->form(['waba_id' => 'abc']))->assertSessionHasErrors('waba_id');
        $this->put('/integrations/channels/whatsapp', $this->form(['verify_token' => 'short']))->assertSessionHasErrors('verify_token');
        $this->put('/integrations/channels/whatsapp', $this->form(['verify_token' => 'has spaces in it']))->assertSessionHasErrors('verify_token');
        $this->put('/integrations/channels/whatsapp', $this->form(['client_secret' => 'tooshort']))->assertSessionHasErrors('client_secret');
        $this->assertSame(0, Integration::count());
    }

    public function test_meta_handshake_uses_the_verify_token_of_that_integration_and_marks_only_it_as_verified(): void
    {
        $integration = $this->saveForm();
        $query = fn ($token) => $this->url($integration).'?hub_mode=subscribe&hub_challenge=abc123&hub_verify_token='.$token;

        $this->get($query('wrong-token-value'))->assertForbidden();
        $this->assertNull($integration->fresh()->config['webhook_verified_at'] ?? null);

        $this->get($query(self::VERIFY))->assertOk()->assertSee('abc123', false);
        $this->assertNotNull($integration->fresh()->config['webhook_verified_at']);

        // Changing the verify token asks Meta to verify again.
        $this->saveForm(['verify_token' => 'another-token-456']);
        $this->assertNull($integration->fresh()->config['webhook_verified_at'] ?? null);
    }

    public function test_an_unknown_key_an_inactive_integration_or_one_without_secrets_answer_404(): void
    {
        $this->get('/api/webhooks/whatsapp/'.str_repeat('a', 48).'?hub_mode=subscribe&hub_challenge=x&hub_verify_token=y')->assertNotFound();
        $this->get('/api/webhooks/whatsapp/short')->assertNotFound();

        $integration = $this->saveForm();
        $integration->forceFill(['secrets' => ['access_token' => 'EAAB']])->save();
        $this->get($this->url($integration).'?hub_mode=subscribe&hub_challenge=x&hub_verify_token='.self::VERIFY)->assertNotFound();
        $this->signed($integration, $this->payload('100200300'))->assertNotFound();

        $integration->forceFill(['is_active' => false, 'secrets' => ['access_token' => 'EAAB', 'verify_token' => self::VERIFY, 'client_secret' => self::SECRET]])->save();
        $this->get($this->url($integration).'?hub_mode=subscribe&hub_challenge=x&hub_verify_token='.self::VERIFY)->assertNotFound();
    }

    public function test_an_event_needs_the_signature_of_that_integrations_client_secret(): void
    {
        $integration = $this->saveForm();
        $bot = $this->connect($integration);

        $this->signed($integration, $this->payload('100200300'), 'another-secret-value-9999')->assertForbidden();
        $this->call('POST', $this->url($integration), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->payload('100200300')))->assertForbidden();
        $this->assertSame(0, $bot->conversations()->count());

        $this->signed($integration, $this->payload('100200300'))->assertOk();
        $this->assertSame(1, $bot->conversations()->count());
        $this->assertSame('Hola', $bot->conversations()->first()->messages()->value('body'));
    }

    public function test_a_repeated_delivery_stores_the_message_once_and_events_of_other_numbers_are_ignored(): void
    {
        $integration = $this->saveForm();
        $bot = $this->connect($integration);

        $this->signed($integration, $this->payload('100200300', 'wamid.SAME'))->assertOk();
        $this->signed($integration, $this->payload('100200300', 'wamid.SAME'))->assertOk();
        $this->assertSame(1, $bot->conversations()->first()->messages()->count());

        // A signed event about a number that is not this integration's is not processed (even if another Workspace owns it).
        $other = $this->bookingWorkspace('OTRO', ['dashboard', 'integrations', 'assistants', 'conversations']);
        $foreign = $other->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '999000111'], 'secrets' => ['access_token' => 'x']]);
        $otherBot = $other->chatbots()->create(['name' => 'Otro']);
        $otherBot->channels()->create(['workspace_id' => $other->id, 'channel' => 'whatsapp', 'integration_id' => $foreign->id, 'is_active' => true]);

        $this->signed($integration, $this->payload('999000111', 'wamid.X'))->assertOk();
        $this->assertSame(0, $otherBot->conversations()->count());
    }

    public function test_a_flood_against_one_integration_is_limited_without_starving_another(): void
    {
        config(['integrations.whatsapp.webhook_per_minute' => 3]);
        $first = $this->saveForm();
        $other = $this->bookingWorkspace('OTRO', ['dashboard', 'integrations', 'assistants', 'conversations']);
        $second = $other->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '777000111', 'webhook_key' => bin2hex(random_bytes(24))],
            'secrets' => ['access_token' => 'x', 'client_secret' => self::SECRET, 'verify_token' => self::VERIFY],
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->signed($first, $this->payload('100200300', 'wamid.F'.$i))->assertOk();
        }

        $this->signed($first, $this->payload('100200300', 'wamid.F9'))->assertStatus(429);
        $this->signed($second, $this->payload('777000111', 'wamid.G1'))->assertOk();
    }

    public function test_the_status_moves_through_each_stage_and_is_connected_only_after_a_real_message(): void
    {
        $integration = $this->saveForm();
        $bot = $this->connect($integration);
        $state = fn () => collect($this->get('/integrations')->viewData('page')['props']['channels'])->firstWhere('key', 'whatsapp')['connection'];

        $this->assertSame('pending', $state()['state']);

        $integration->forceFill(['last_tested_at' => now(), 'last_test_ok' => true])->save();
        $this->assertSame('Webhook pendiente de verificar en Meta', $state()['label']);

        $this->get($this->url($integration).'?hub_mode=subscribe&hub_challenge=c&hub_verify_token='.self::VERIFY)->assertOk();
        $this->assertSame('Webhook verificado · esperando un mensaje real', $state()['label']);
        $this->assertSame('partial', $state()['state']);

        $this->signed($integration, $this->payload('100200300'))->assertOk();
        $this->assertSame(['connected', 'Mensaje recibido · WhatsApp conectado'], [$state()['state'], $state()['label']]);
        $this->assertSame(url($this->url($integration)), $state()['webhook']['url']);
        $this->assertTrue(app(WhatsAppConnection::class)->integrationConfigured($integration->fresh()));
        $this->assertSame(1, $bot->conversations()->count());
    }
}
