<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Integraciones: generic HTTP/REST connections owned by the active Workspace. */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_live_SUPER-SECRET-VALUE-123';

    protected function setUp(): void
    {
        parent::setUp();

        // Hosts resolve to a public address, so no test touches the network or the real DNS.
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        // Nothing may reach the network, except the local Inertia SSR probes (the Vite dev server or the SSR process).
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:13714/*', 'http://localhost:13714/*', 'http://127.0.0.1:5173/*', 'http://localhost:5173/*']);
    }

    private function actAs(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(): Workspace
    {
        return Workspace::create(['organization_id' => Organization::firstOrFail()->id, 'code' => 'OTHER_WS', 'name' => 'Other']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'API de pruebas',
            'provider' => 'Proveedor X',
            'description' => 'Conexión genérica',
            'type' => 'http',
            'is_active' => true,
            'base_url' => 'https://api.example.com/v1',
            'endpoint' => '/status',
            'method' => 'GET',
            'timeout' => 10,
            'auth_type' => 'bearer',
            'auth_secret' => self::SECRET,
            'headers' => [],
            'query' => [],
            'body' => '',
        ], $overrides);
    }

    private function makeIntegration(array $overrides = [], ?Workspace $workspace = null): Integration
    {
        $this->post('/integrations', $this->payload($overrides))->assertSessionHasNoErrors();

        return ($workspace ?? Workspace::first())->integrations()->latest('id')->firstOrFail();
    }

    // --- listing ---------------------------------------------------------------------------------------------

    public function test_the_page_starts_empty_and_lists_only_the_active_workspaces_integrations(): void
    {
        $this->actAs();
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Integrations/Index')->has('integrations', 0)->has('catalog.methods', 5)->where('catalog.types.0.value', 'http'));

        $this->makeIntegration(['name' => 'Mía']);
        $other = $this->otherWorkspace();
        $other->integrations()->create(['name' => 'Ajena', 'type' => 'http', 'config' => ['base_url' => 'https://x.test', 'endpoint' => '', 'method' => 'GET', 'timeout' => 5, 'auth' => ['type' => 'none'], 'headers' => [], 'query' => [], 'body' => null]]);

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('integrations', 1)->where('integrations.0.name', 'Mía')->where('integrations.0.typeLabel', 'API HTTP / REST')
            ->where('integrations.0.isActive', true)->where('integrations.0.lastTest', null)->where('integrations.0.summary.host', 'api.example.com'));
    }

    // --- create / edit ---------------------------------------------------------------------------------------

    public function test_an_integration_is_created_inside_the_active_workspace(): void
    {
        $this->actAs();

        $this->post('/integrations', $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success', 'Integración creada correctamente');

        $integration = Workspace::first()->integrations()->firstOrFail();
        $this->assertSame(Workspace::first()->id, $integration->workspace_id);
        $this->assertSame(['API de pruebas', 'Proveedor X', 'http', true], [$integration->name, $integration->provider, $integration->type, $integration->is_active]);
        $this->assertSame(['https://api.example.com/v1', '/status', 'GET', 10], [$integration->config['base_url'], $integration->config['endpoint'], $integration->config['method'], $integration->config['timeout']]);
        $this->assertSame('bearer', $integration->config['auth']['type']);
    }

    public function test_the_client_cannot_choose_the_workspace_of_a_new_integration(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();

        $this->post('/integrations', $this->payload(['workspace_id' => $other->id]))->assertSessionHasNoErrors();

        $this->assertSame(0, $other->integrations()->count());
        $this->assertSame(1, Workspace::first()->integrations()->count());
    }

    public function test_names_are_unique_per_workspace_only(): void
    {
        $this->actAs();
        $this->makeIntegration();
        $this->post('/integrations', $this->payload())->assertSessionHasErrors('name');

        $other = $this->otherWorkspace();
        $other->integrations()->create(['name' => 'Libre', 'type' => 'http', 'config' => ['base_url' => 'https://x.test']]);
        $this->post('/integrations', $this->payload(['name' => 'Libre']))->assertSessionHasNoErrors();
    }

    public function test_editing_changes_the_settings_and_keeps_the_secret_when_left_blank(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration();
        $integration->forceFill(['last_tested_at' => now(), 'last_test_ok' => true, 'last_test_message' => 'ok'])->save();

        $this->put("/integrations/{$integration->id}", $this->payload(['name' => 'Renombrada', 'method' => 'POST', 'body' => '{"a":1}', 'auth_secret' => '']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Integración actualizada correctamente');

        $fresh = $integration->fresh();
        $this->assertSame('Renombrada', $fresh->name);
        $this->assertSame('POST', $fresh->config['method']);
        $this->assertSame('{"a":1}', $fresh->config['body']);
        $this->assertSame(self::SECRET, $fresh->secrets['auth'], 'Blank keeps the stored credential.');
        $this->assertNull($fresh->last_tested_at, 'A changed configuration clears the old test result.');

        $this->put("/integrations/{$integration->id}", $this->payload(['auth_secret' => 'nuevo-token-456']))->assertSessionHasNoErrors();
        $this->assertSame('nuevo-token-456', $integration->fresh()->secrets['auth']);
    }

    public function test_changing_the_authentication_type_requires_a_new_credential(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration();

        $this->put("/integrations/{$integration->id}", $this->payload(['auth_type' => 'basic', 'auth_username' => 'user', 'auth_secret' => '']))->assertSessionHasErrors('auth_secret');
        $this->put("/integrations/{$integration->id}", $this->payload(['auth_type' => 'none', 'auth_secret' => '']))->assertSessionHasNoErrors();

        $this->assertNull($integration->fresh()->secrets['auth']);
    }

    public function test_the_type_of_an_existing_integration_cannot_change(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration();

        $this->put("/integrations/{$integration->id}", $this->payload(['type' => 'other']))->assertSessionHasNoErrors();

        $this->assertSame('http', $integration->fresh()->type);
    }

    // --- secrets ---------------------------------------------------------------------------------------------

    public function test_credentials_are_encrypted_at_rest_and_never_in_config(): void
    {
        $this->actAs();
        $this->makeIntegration(['headers' => [['name' => 'X-Token', 'value' => 'header-secret-999', 'secret' => true], ['name' => 'Accept', 'value' => 'application/json', 'secret' => false]],
            'query' => [['name' => 'sig', 'value' => 'query-secret-777', 'secret' => true]]]);

        $row = DB::table('integrations')->first();
        foreach ([self::SECRET, 'header-secret-999', 'query-secret-777'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $row->secrets, 'Secrets are encrypted in the column.');
            $this->assertStringNotContainsString($secret, (string) $row->config, 'Secrets never go to the config JSON.');
        }
        $this->assertStringContainsString('application\/json', (string) $row->config);

        $integration = Integration::first();
        $this->assertSame(self::SECRET, $integration->secrets['auth']);
        $this->assertSame('header-secret-999', $integration->secrets['headers']['x-token']);
        $this->assertSame('query-secret-777', $integration->secrets['query']['sig']);
        $this->assertArrayNotHasKey('secrets', $integration->toArray(), 'The secrets are hidden from serialization.');
    }

    public function test_secrets_are_never_sent_to_the_frontend(): void
    {
        $this->actAs();
        $this->makeIntegration(['headers' => [['name' => 'X-Token', 'value' => 'header-secret-999', 'secret' => true]], 'query' => [['name' => 'sig', 'value' => 'query-secret-777', 'secret' => true]]]);

        $response = $this->get('/integrations');
        $html = $response->getContent();
        foreach ([self::SECRET, 'header-secret-999', 'query-secret-777'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrations.0.form.auth_secret', '')->where('integrations.0.form.auth_secret_set', true)
            ->where('integrations.0.form.headers.0.value', '')->where('integrations.0.form.headers.0.secret_set', true)
            ->where('integrations.0.form.query.0.secret_set', true));

        // Not after a save or a failed validation either.
        $this->put('/integrations/'.Integration::first()->id, $this->payload(['method' => 'WRONG']))->assertSessionHasErrors('method');
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }

    public function test_a_secret_header_left_blank_keeps_its_value_and_a_new_one_needs_a_value(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration(['headers' => [['name' => 'X-Token', 'value' => 'header-secret-999', 'secret' => true]]]);

        $this->put("/integrations/{$integration->id}", $this->payload(['auth_secret' => '', 'headers' => [['name' => 'X-Token', 'value' => '', 'secret' => true]]]))->assertSessionHasNoErrors();
        $this->assertSame('header-secret-999', $integration->fresh()->secrets['headers']['x-token']);

        $this->put("/integrations/{$integration->id}", $this->payload(['auth_secret' => '', 'headers' => [['name' => 'X-New', 'value' => '', 'secret' => true]]]))->assertSessionHasErrors('headers.0.value');
        $this->post('/integrations', $this->payload(['name' => 'Otra', 'headers' => [['name' => 'X-New', 'value' => '', 'secret' => true]]]))->assertSessionHasErrors('headers.0.value');
    }

    // --- validation ------------------------------------------------------------------------------------------

    public function test_invalid_configurations_are_rejected(): void
    {
        $this->actAs();

        $bad = [
            ['name' => ''],
            ['base_url' => ''],
            ['base_url' => 'ftp://api.example.com'],
            ['base_url' => 'not a url'],
            ['base_url' => 'https://user:pass@api.example.com'],
            ['base_url' => 'https://api.example.com/?key=1'],
            ['endpoint' => 'has space'],
            ['method' => 'TRACE'],
            ['timeout' => 0],
            ['timeout' => 31],
            ['auth_type' => 'oauth2'],
            ['auth_type' => 'api_key', 'auth_name' => '', 'auth_location' => 'header'],
            ['auth_type' => 'api_key', 'auth_name' => 'X-Key', 'auth_location' => 'cookie'],
            ['auth_type' => 'basic', 'auth_username' => ''],
            ['auth_secret' => ''],
            ['headers' => [['name' => 'Bad Name', 'value' => 'x']]],
            ['headers' => [['name' => 'Host', 'value' => 'x']]],
            ['headers' => [['name' => 'Content-Length', 'value' => '1']]],
            ['headers' => [['name' => 'X-A', 'value' => 'x'], ['name' => 'x-a', 'value' => 'y']]],
            ['query' => [['name' => 'a', 'value' => '1'], ['name' => 'a', 'value' => '2']]],
            ['method' => 'POST', 'body' => '{not json'],
            ['type' => 'soap'],
            ['description' => str_repeat('a', 501)],
        ];

        foreach ($bad as $override) {
            $this->post('/integrations', $this->payload($override))->assertSessionHasErrors();
        }

        $this->assertSame(0, Integration::count());
    }

    public function test_the_number_of_headers_and_parameters_is_limited(): void
    {
        $this->actAs();
        $rows = fn (int $n) => array_map(fn ($i) => ['name' => "H{$i}", 'value' => 'x'], range(1, $n));

        $this->post('/integrations', $this->payload(['headers' => $rows(21)]))->assertSessionHasErrors('headers');
        $this->post('/integrations', $this->payload(['headers' => $rows(20)]))->assertSessionHasNoErrors();
    }

    public function test_a_get_does_not_keep_a_body(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration(['method' => 'GET', 'body' => '{"a":1}']);

        $this->assertNull($integration->config['body']);
    }

    // --- activation ------------------------------------------------------------------------------------------

    public function test_integrations_can_be_deactivated_and_reactivated(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration();

        $this->post("/integrations/{$integration->id}/deactivate")->assertSessionHas('success', 'Integración desactivada correctamente');
        $this->assertFalse($integration->fresh()->is_active);
        $this->post("/integrations/{$integration->id}/activate")->assertSessionHas('success', 'Integración activada correctamente');
        $this->assertTrue($integration->fresh()->is_active);
    }

    public function test_integrations_have_no_delete_action(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration();

        $this->delete("/integrations/{$integration->id}")->assertStatus(405);
        $this->assertSame(1, Integration::count());
    }

    // --- isolation and permissions --------------------------------------------------------------------------

    public function test_another_workspaces_integration_cannot_be_read_changed_or_tested(): void
    {
        $this->actAs();
        $other = $this->otherWorkspace();
        $foreign = $other->integrations()->create(['name' => 'Ajena', 'type' => 'http', 'is_active' => true, 'secrets' => ['auth' => 'foreign-secret'],
            'config' => ['base_url' => 'https://x.test', 'endpoint' => '', 'method' => 'GET', 'timeout' => 5, 'auth' => ['type' => 'bearer'], 'headers' => [], 'query' => [], 'body' => null]]);

        $this->put("/integrations/{$foreign->id}", $this->payload(['name' => 'Hack']))->assertNotFound();
        $this->post("/integrations/{$foreign->id}/activate")->assertNotFound();
        $this->post("/integrations/{$foreign->id}/deactivate")->assertNotFound();
        $this->post("/integrations/{$foreign->id}/test")->assertNotFound();

        $this->assertSame('Ajena', $foreign->fresh()->name);
        $this->assertTrue($foreign->fresh()->is_active);
        $this->assertStringNotContainsString('foreign-secret', $this->get('/integrations')->getContent());
        Http::assertNothingSent();
    }

    public function test_only_roles_with_manage_settings_can_use_integrations(): void
    {
        $integration = null;
        foreach (['supervisor', 'cliente'] as $role) {
            $this->actAs($role);
            $integration ??= Workspace::first()->integrations()->create(['name' => 'X', 'type' => 'http', 'config' => ['base_url' => 'https://x.test']]);

            $this->get('/integrations')->assertForbidden();
            $this->post('/integrations', $this->payload())->assertForbidden();
            $this->put("/integrations/{$integration->id}", $this->payload())->assertForbidden();
            $this->post("/integrations/{$integration->id}/activate")->assertForbidden();
            $this->post("/integrations/{$integration->id}/test")->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertSame(1, Integration::count());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/integrations')->assertRedirect('/login');
        $this->post('/integrations', $this->payload())->assertRedirect('/login');
    }

    public function test_non_numeric_ids_are_not_found(): void
    {
        $this->actAs();

        $this->post('/integrations/abc/test')->assertNotFound();
        $this->put('/integrations/abc', $this->payload())->assertNotFound();
    }

    // --- connection test -------------------------------------------------------------------------------------

    public function test_the_request_is_built_from_the_configuration(): void
    {
        $this->actAs();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $integration = $this->makeIntegration([
            'method' => 'POST', 'endpoint' => '/orders?fixed=1', 'body' => '{"hello":"world"}',
            'headers' => [['name' => 'X-Plain', 'value' => 'abc', 'secret' => false], ['name' => 'X-Token', 'value' => 'hdr-secret', 'secret' => true]],
            'query' => [['name' => 'page', 'value' => '2', 'secret' => false], ['name' => 'sig', 'value' => 'qry-secret', 'secret' => true]],
        ]);

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('success');

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://api.example.com/v1/orders?')
                && $query === ['fixed' => '1', 'page' => '2', 'sig' => 'qry-secret']
                && $request->header('Authorization') === ['Bearer '.self::SECRET]
                && $request->header('X-Plain') === ['abc']
                && $request->header('X-Token') === ['hdr-secret']
                && $request->header('Content-Type') === ['application/json']
                && $request->body() === '{"hello":"world"}';
        });
        Http::assertSentCount(1);
    }

    public function test_every_http_method_is_used_as_configured(): void
    {
        $this->actAs();
        Http::fake(['*' => Http::response('', 204)]);

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $i => $method) {
            $integration = $this->makeIntegration(['name' => "M{$i}", 'method' => $method, 'body' => in_array($method, ['POST', 'PUT', 'PATCH'], true) ? '{"x":1}' : '']);
            $this->post("/integrations/{$integration->id}/test");

            Http::assertSent(fn (Request $request) => $request->method() === $method && ($method === 'GET' ? $request->body() === '' : true));
        }
    }

    public function test_each_authentication_scheme_is_applied(): void
    {
        $this->actAs();
        Http::fake(['*' => Http::response('', 200)]);

        $cases = [
            ['auth_type' => 'none', 'auth_secret' => ''],
            ['auth_type' => 'api_key', 'auth_name' => 'X-API-Key', 'auth_location' => 'header', 'auth_secret' => 'k-header'],
            ['auth_type' => 'api_key', 'auth_name' => 'api_key', 'auth_location' => 'query', 'auth_secret' => 'k-query'],
            ['auth_type' => 'bearer', 'auth_secret' => 'tok-1'],
            ['auth_type' => 'basic', 'auth_username' => 'ana', 'auth_secret' => 'pw-1'],
        ];

        foreach ($cases as $i => $case) {
            $integration = $this->makeIntegration(['name' => "A{$i}"] + $case);
            $this->post("/integrations/{$integration->id}/test")->assertSessionHas('success');
        }

        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization') && ! $r->hasHeader('X-API-Key') && ! str_contains($r->url(), 'api_key=') && $r->url() === 'https://api.example.com/v1/status');
        Http::assertSent(fn (Request $r) => $r->header('X-API-Key') === ['k-header']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api_key=k-query'));
        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer tok-1']);
        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Basic '.base64_encode('ana:pw-1')]);
    }

    public function test_the_result_is_understandable_and_stored_as_a_short_summary(): void
    {
        $this->actAs();
        $integration = $this->makeIntegration();

        $status = 200;
        Http::fake(function () use (&$status) {
            return Http::response(['secret' => 'response-body-data'], $status);
        });
        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('success');
        $fresh = $integration->fresh();
        $this->assertTrue($fresh->last_test_ok);
        $this->assertSame(200, $fresh->last_test_status);
        $this->assertStringContainsString('Conexión correcta', $fresh->last_test_message);
        $this->assertStringNotContainsString('response-body-data', (string) json_encode($fresh->getAttributes()), 'Responses are never stored.');

        foreach ([[401, 'rechazó las credenciales'], [403, 'rechazó las credenciales'], [404, 'revisa la URL base'], [500, 'HTTP 500'], [302, 'redirección']] as [$code, $text]) {
            $status = $code;
            $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');
            $this->assertStringContainsString($text, $integration->fresh()->last_test_message);
            $this->assertFalse($integration->fresh()->last_test_ok);
            $this->assertSame($code, $integration->fresh()->last_test_status);
        }

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->where('integrations.0.lastTest.ok', false)->where('integrations.0.lastTest.status', 302));
    }

    public function test_connection_failures_are_explained_without_leaking_the_url_or_credentials(): void
    {
        $this->actAs();
        Log::spy();
        $integration = $this->makeIntegration(['auth_type' => 'api_key', 'auth_name' => 'api_key', 'auth_location' => 'query', 'auth_secret' => self::SECRET]);

        $raw = '';
        $failure = null;
        Http::fake(function () use (&$raw, &$failure) {
            throw $failure ?? new ConnectionException($raw.' for https://api.example.com/v1/status?api_key='.self::SECRET);
        });

        foreach ([
            ['cURL error 28: Operation timed out after 10001 milliseconds', 'Tiempo de espera agotado'],
            ['cURL error 60: SSL certificate problem', 'certificado SSL'],
            ['cURL error 6: Could not resolve host', 'No se pudo resolver'],
            ['cURL error 7: Failed to connect', 'No se pudo conectar'],
        ] as [$message, $expected]) {
            $raw = $message;
            $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');

            $message = $integration->fresh()->last_test_message;
            $this->assertStringContainsString($expected, $message);
            $this->assertStringNotContainsString(self::SECRET, $message);
            $this->assertStringNotContainsString('api.example.com', $message);
        }

        $failure = new \RuntimeException('boom '.self::SECRET);
        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');
        $this->assertSame('No se pudo completar la solicitud.', $integration->fresh()->last_test_message);

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_an_inactive_integration_is_never_called(): void
    {
        $this->actAs();
        Http::fake();
        $integration = $this->makeIntegration(['is_active' => false]);

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error', 'Activa la integración para probar la conexión.');

        Http::assertNothingSent();
        $this->assertNull($integration->fresh()->last_tested_at);
    }

    // --- server-side request safety --------------------------------------------------------------------------

    public function test_internal_addresses_are_refused_before_any_request_is_sent(): void
    {
        $this->actAs();
        Http::fake();

        foreach (['http://127.0.0.1:6379', 'http://10.0.0.5/admin', 'http://169.254.169.254/latest/meta-data', 'http://192.168.1.10', 'http://[::1]/', 'http://100.64.0.1', 'http://198.19.0.1', 'http://[::ffff:127.0.0.1]/', 'http://[64:ff9b::7f00:1]/', 'http://0.0.0.0'] as $i => $url) {
            $integration = $this->makeIntegration(['name' => "I{$i}", 'base_url' => $url]);
            $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');
            $this->assertStringContainsString('direcciones internas', $integration->fresh()->last_test_message);
        }

        // A public-looking name that resolves to an internal address is refused too.
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn () => ['10.1.2.3']));
        $integration = $this->makeIntegration(['name' => 'Rebind', 'base_url' => 'https://evil.example.com']);
        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');

        // An unresolvable host is an error, not a request.
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn () => []));
        $integration = $this->makeIntegration(['name' => 'NoHost', 'base_url' => 'https://nowhere.example.com']);
        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');
        $this->assertStringContainsString('resolver el host', $integration->fresh()->last_test_message);

        Http::assertNothingSent();
    }

    public function test_private_hosts_can_be_allowed_explicitly_for_local_development(): void
    {
        $this->actAs();
        config(['integrations.allow_private_hosts' => true]);
        Http::fake(['*' => Http::response('', 200)]);
        $integration = $this->makeIntegration(['base_url' => 'http://127.0.0.1:9999']);

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('success');

        Http::assertSentCount(1);
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->actAs();
        Http::fake(['api.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']), '*' => Http::response('SHOULD NOT BE CALLED', 200)]);
        $integration = $this->makeIntegration();

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error');

        Http::assertSentCount(1);
    }
}
