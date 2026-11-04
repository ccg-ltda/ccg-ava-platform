<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Integrations\WooCommerceWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * WooCommerce is reached through the generic HTTP/REST connection (Basic auth with the consumer key and secret) and its webhooks are verified with their own signature.
 * Local and simulated: NO real store takes part (see docs/integracion-woocommerce.md for the real test).
 */
class WooCommerceIntegrationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    public function test_the_webhook_signature_is_the_base64_hmac_of_the_raw_body(): void
    {
        $body = '{"id":727,"status":"processing","billing":{"email":"a@b.co"}}';
        $signature = base64_encode(hash_hmac('sha256', $body, 's3cret', true));

        $this->assertTrue(WooCommerceWebhook::verify($body, $signature, 's3cret'));
        $this->assertFalse(WooCommerceWebhook::verify($body.' ', $signature, 's3cret'), 'A single changed byte invalidates it.');
        $this->assertFalse(WooCommerceWebhook::verify($body, $signature, 'otro'));
        $this->assertFalse(WooCommerceWebhook::verify($body, null, 's3cret'));
        $this->assertFalse(WooCommerceWebhook::verify($body, '', 's3cret'));
        $this->assertFalse(WooCommerceWebhook::verify($body, $signature, ''));
    }

    public function test_a_store_is_connected_with_the_generic_http_connection_and_the_test_reads_the_real_endpoint(): void
    {
        $this->seedRoleCatalog();
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::fake(['*' => Http::response([['id' => 1, 'name' => 'Camiseta']], 200, ['X-WP-Total' => '12'])]);
        $workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'integrations', 'settings']);
        $this->actAsMember($workspace, 'admin');

        $this->post('/integrations', [
            'type' => 'http', 'name' => 'WooCommerce', 'base_url' => 'https://tienda.example', 'endpoint' => '/wp-json/wc/v3/products', 'method' => 'GET', 'timeout' => 10,
            'auth_type' => 'basic', 'auth_username' => 'ck_'.str_repeat('a', 40), 'auth_secret' => 'cs_'.str_repeat('b', 40), 'query' => [['name' => 'per_page', 'value' => '1']],
        ])->assertSessionHasNoErrors();

        $integration = $workspace->integrations()->firstOrFail();
        $this->assertNotSame('cs_'.str_repeat('b', 40), $integration->getRawOriginal('secrets'), 'The consumer secret is encrypted at rest.');
        $this->assertArrayNotHasKey('auth_secret', $integration->config);

        $this->post("/integrations/{$integration->id}/test")->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://tienda.example/wp-json/wc/v3/products')
            && str_contains($request->url(), 'per_page=1')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_'.str_repeat('a', 40).':cs_'.str_repeat('b', 40))));
    }
}
