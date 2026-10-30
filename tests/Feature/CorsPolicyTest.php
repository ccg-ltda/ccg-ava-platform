<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * config/cors.php: CORS only where a browser script on another site legitimately calls Ava (the chat widget and the public booking API), never with
 * credentials, and never on the application, the agent API, the webhooks or the CSRF cookie route. CORS is not authentication: these tests do not
 * replace the signature, token or permission tests of those routes.
 */
class CorsPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cliente.example';

    public function test_the_policy_never_combines_a_wildcard_with_credentials(): void
    {
        $config = config('cors');

        $this->assertFalse($config['supports_credentials']);
        $this->assertSame(['api/widget/*', 'api/booking/*'], $config['paths']);
        $this->assertSame(['GET', 'POST', 'OPTIONS'], $config['allowed_methods']);
        $this->assertNotContains('*', $config['allowed_headers']);
        $this->assertEqualsCanonicalizing(['Accept', 'Content-Type', 'X-Zonno-Context'], $config['allowed_headers']);
    }

    public function test_the_widget_preflight_from_a_customer_site_is_answered_without_credentials(): void
    {
        $response = $this->call('OPTIONS', '/api/widget/clave-de-prueba/messages', [], [], [], [
            'HTTP_ORIGIN' => self::SITE,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-zonno-context',
        ]);

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeaderMissing('Access-Control-Allow-Credentials');
        $this->assertStringContainsStringIgnoringCase('x-zonno-context', (string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_a_preflight_asking_for_a_header_or_method_that_is_not_allowed_gets_no_permission(): void
    {
        $header = $this->call('OPTIONS', '/api/widget/clave-de-prueba/messages', [], [], [], [
            'HTTP_ORIGIN' => self::SITE, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);
        $this->assertStringNotContainsStringIgnoringCase('authorization', (string) $header->headers->get('Access-Control-Allow-Headers'));

        $method = $this->call('OPTIONS', '/api/widget/clave-de-prueba/messages', [], [], [], [
            'HTTP_ORIGIN' => self::SITE, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE',
        ]);
        $this->assertStringNotContainsString('DELETE', (string) $method->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_public_widget_and_booking_answers_are_readable_from_any_site(): void
    {
        $this->getJson('/api/widget/clave-de-prueba/config', ['Origin' => self::SITE])->assertHeader('Access-Control-Allow-Origin', '*');
        $this->getJson('/api/booking/clave-de-prueba/config', ['Origin' => self::SITE])->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_the_application_the_agent_api_the_webhooks_and_the_csrf_route_get_no_cors_headers(): void
    {
        foreach (['/login', '/pre-login', '/legal', '/sanctum/csrf-cookie', '/up'] as $uri) {
            $this->get($uri, ['Origin' => self::SITE])->assertHeaderMissing('Access-Control-Allow-Origin');
        }

        $this->getJson('/api/agent/config', ['Origin' => self::SITE])->assertUnauthorized()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->call('OPTIONS', '/api/agent/config', [], [], [], ['HTTP_ORIGIN' => self::SITE, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])->assertHeaderMissing('Access-Control-Allow-Origin');

        // A webhook is still reached by the provider (a server, no Origin) and still judged by its own signature.
        $this->postJson('/api/webhooks/wompi', [], ['Origin' => self::SITE])->assertStatus(400)->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->postJson('/api/webhooks/wompi', [])->assertStatus(400);
        $this->postJson('/api/webhooks/whatsapp', [])->assertNotFound();
    }

    public function test_the_origins_can_be_narrowed_by_configuration_without_changing_code(): void
    {
        config(['cors.allowed_origins' => ['https://uno.example']]);

        $this->getJson('/api/widget/clave-de-prueba/config', ['Origin' => 'https://uno.example'])->assertHeader('Access-Control-Allow-Origin', 'https://uno.example');
        // With a single allowed origin the library always answers with THAT origin; the browser then refuses the mismatch. The other origin is never echoed.
        $other = $this->getJson('/api/widget/clave-de-prueba/config', ['Origin' => 'https://otro.example']);
        $this->assertNotSame('https://otro.example', $other->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $other->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_widget_still_enforces_its_own_allowed_sites_whatever_cors_says(): void
    {
        // CORS only decides what a browser may read; the server decides who may talk to the channel (an unknown key is 404 regardless of the Origin).
        $this->getJson('/api/widget/clave-de-prueba/config', ['Origin' => self::SITE])->assertNotFound();
    }
}
