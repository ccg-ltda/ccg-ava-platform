<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * The security headers of config/security.php: present on pages and JSON alike, a nonce that really is on the inline scripts, HSTS only over HTTPS and
 * only where it was asked for, a Content-Security-Policy that reports by default and enforces on request, and nothing that changes cookies, CSRF or webhooks.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Whatever the developer's machine has (a running Vite dev server leaves public/hot behind), these tests describe the built application.
        Vite::useHotFile(storage_path('framework/testing/vite-hot-that-does-not-exist'));

        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('The frontend build (npm run build) is needed to render the pages.');
        }
    }

    private function csp(string $header, $response): string
    {
        return (string) $response->headers->get($header);
    }

    public function test_every_page_carries_the_basic_headers_and_a_report_only_policy_by_default(): void
    {
        $response = $this->get('/legal')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertNotSame('', $this->csp('Content-Security-Policy-Report-Only', $response));
    }

    public function test_the_policy_allows_no_unsafe_script_and_no_third_party_asset_host(): void
    {
        $policy = $this->csp('Content-Security-Policy-Report-Only', $this->get('/legal'));
        $directives = collect(explode(';', $policy))->mapWithKeys(fn ($d) => [strtok(trim($d), ' ') => trim($d)]);

        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", (string) $directives->get('script-src'));
        $this->assertStringNotContainsString("'unsafe-inline'", (string) $directives->get('style-src'));
        // The only `unsafe-inline`: style ATTRIBUTES (React inline styles); it can not run script.
        $this->assertSame("style-src-attr 'unsafe-inline'", $directives->get('style-src-attr'));
        // Inter and three.js are bundled: no third-party host appears in script, style or font sources.
        $this->assertDoesNotMatchRegularExpression('#https?://#', (string) $directives->get('script-src'));
        $this->assertDoesNotMatchRegularExpression('#https?://#', (string) $directives->get('style-src'));
        $this->assertSame("font-src 'self'", $directives->get('font-src'));
        $this->assertSame("frame-ancestors 'self'", $directives->get('frame-ancestors'));
        $this->assertSame("object-src 'none'", $directives->get('object-src'));
        $this->assertSame("base-uri 'self'", $directives->get('base-uri'));
        $this->assertStringContainsString('https://checkout.wompi.co', $directives->get('form-action'));
        $this->assertStringNotContainsString('*', $policy);
    }

    public function test_the_nonce_of_the_policy_is_on_every_inline_script_and_changes_per_request(): void
    {
        $first = $this->get('/legal');
        preg_match("/'nonce-([^']+)'/", $this->csp('Content-Security-Policy-Report-Only', $first), $match);
        $nonce = $match[1] ?? null;

        $this->assertNotEmpty($nonce);
        $html = $first->getContent();

        // Every <script> without src (the theme script, the Ziggy route table) has the nonce; so do the module scripts Vite prints.
        preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
        $this->assertNotEmpty($tags[1]);

        foreach ($tags[1] as $attributes) {
            // A JSON data block (Inertia's page payload) is not executable, so the policy does not govern it.
            if (str_contains($attributes, 'application/json')) {
                continue;
            }

            if (! str_contains($attributes, 'src=') || str_contains($attributes, 'type="module"')) {
                $this->assertStringContainsString('nonce="'.$nonce.'"', $attributes, "A script without the nonce: <script{$attributes}>");
            }
        }

        preg_match("/'nonce-([^']+)'/", $this->csp('Content-Security-Policy-Report-Only', $this->get('/legal')), $second);
        $this->assertNotSame($nonce, $second[1] ?? null);
    }

    public function test_the_default_error_pages_carry_the_nonce_on_their_inline_styles(): void
    {
        $this->seedRoleCatalog();
        $this->actingAsWorkspaceMember(User::factory()->create(), 'admin');
        $page = $this->get('/plans')->assertForbidden(); // platform-only: the framework's own 403 page
        preg_match("/'nonce-([^']+)'/", $this->csp('Content-Security-Policy-Report-Only', $page), $match);

        preg_match_all('/<style\b([^>]*)>/i', $page->getContent(), $tags);
        $this->assertNotEmpty($tags[1], 'the error page has inline styles');

        foreach ($tags[1] as $attributes) {
            $this->assertStringContainsString('nonce="'.($match[1] ?? 'missing').'"', $attributes);
        }
    }

    public function test_the_policy_can_be_enforced_or_switched_off(): void
    {
        config(['security.csp.mode' => 'enforce']);
        $enforced = $this->get('/legal');
        $this->assertNotSame('', $this->csp('Content-Security-Policy', $enforced));
        $enforced->assertHeaderMissing('Content-Security-Policy-Report-Only');

        config(['security.csp.mode' => 'off']);
        $off = $this->get('/legal');
        $off->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $off->assertHeader('X-Content-Type-Options', 'nosniff'); // the rest stays
    }

    public function test_the_report_uri_is_added_only_when_it_is_a_path_or_an_https_url(): void
    {
        config(['security.csp.report_uri' => 'https://reports.example.test/csp']);
        $this->assertStringContainsString('report-uri https://reports.example.test/csp', $this->csp('Content-Security-Policy-Report-Only', $this->get('/legal')));

        config(['security.csp.report_uri' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('report-uri', $this->csp('Content-Security-Policy-Report-Only', $this->get('/legal')));
    }

    public function test_no_policy_is_sent_while_the_vite_dev_server_runs(): void
    {
        $hot = storage_path('framework/testing/vite-hot-test');
        @mkdir(dirname($hot), 0777, true);
        file_put_contents($hot, 'http://localhost:5173');
        Vite::useHotFile($hot);

        try {
            $this->get('/login')->assertHeaderMissing('Content-Security-Policy-Report-Only')->assertHeaderMissing('Content-Security-Policy');
        } finally {
            unlink($hot);
        }
    }

    public function test_hsts_is_sent_only_over_https_and_only_in_production_unless_configured(): void
    {
        $https = ['HTTPS' => 'on'];

        // Plain HTTP never gets it, whatever the environment.
        $this->app['env'] = 'production';
        $this->get('/legal')->assertHeaderMissing('Strict-Transport-Security');

        // HTTPS in production: the conservative default, without includeSubDomains or preload.
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeader('Strict-Transport-Security', 'max-age=15552000');

        // HTTPS outside production (local, testing): nothing, unless the owner asks.
        $this->app['env'] = 'local';
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeaderMissing('Strict-Transport-Security');

        config(['security.hsts.enabled' => true]);
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeader('Strict-Transport-Security', 'max-age=15552000');

        // Switched off explicitly in production.
        $this->app['env'] = 'production';
        config(['security.hsts.enabled' => false]);
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_includes_subdomains_and_preload_only_when_asked_and_valid(): void
    {
        $https = ['HTTPS' => 'on'];
        config(['security.hsts.enabled' => true]);

        // preload alone (no includeSubDomains, short max-age) is ignored.
        config(['security.hsts.preload' => true]);
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeader('Strict-Transport-Security', 'max-age=15552000');

        config(['security.hsts.include_subdomains' => true]);
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeader('Strict-Transport-Security', 'max-age=15552000; includeSubDomains');

        config(['security.hsts.max_age' => 31536000]);
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        config(['security.hsts.max_age' => 0]);
        $this->withServerVariables($https)->get('https://localhost/legal')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_json_answers_get_the_basic_headers_but_no_html_policy(): void
    {
        $response = $this->getJson('/api/widget/does-not-exist/config')->assertNotFound();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_a_header_a_controller_already_set_is_kept(): void
    {
        Route::get('/__headers-test', fn () => response('ok')->header('X-Frame-Options', 'DENY')->header('Referrer-Policy', 'no-referrer'));

        $this->get('/__headers-test')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_a_page_does_not_repeat_a_header(): void
    {
        $response = $this->get('/legal');

        foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy-Report-Only'] as $name) {
            $this->assertCount(1, (array) $response->headers->all(strtolower($name)), "{$name} must be sent once");
        }
    }

    public function test_the_headers_do_not_touch_the_session_cookie_the_csrf_token_or_the_webhooks(): void
    {
        $page = $this->get('/pre-login')->assertOk();
        $names = collect($page->headers->getCookies())->map(fn ($cookie) => $cookie->getName());

        $this->assertTrue($names->contains('XSRF-TOKEN'));
        $this->assertTrue($names->contains(config('session.cookie')));
        $this->assertStringContainsString('name="csrf-token"', $page->getContent());

        // A webhook is still judged by its own signature, and still answers as before.
        $this->postJson('/api/webhooks/wompi', [])->assertStatus(400)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/webhooks/whatsapp', [])->assertNotFound();
    }

    public function test_the_headers_can_be_switched_off(): void
    {
        config(['security.enabled' => false]);

        $this->get('/legal')->assertHeaderMissing('X-Frame-Options')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
