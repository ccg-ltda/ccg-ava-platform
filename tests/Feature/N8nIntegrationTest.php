<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** n8n as an integration of the Workspace: Ava -> n8n with the instance URL and an API Key, verified for real. */
class N8nIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'n8n_api_SUPER-SECRET-KEY-123';

    private const URL = 'https://miempresa.app.n8n.cloud';

    protected function setUp(): void
    {
        parent::setUp();

        // Hosts resolve to a public address, so no test touches the network or the real DNS.
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function actAs(string $role = 'admin', bool $superuser = false, ?Workspace $in = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        if ($in) {
            $in->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            $this->withSession(['workspace_id' => $in->id]);
        }

        return $user;
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function save(array $data = [])
    {
        return $this->put('/integrations/n8n', $data + ['base_url' => self::URL, 'api_key' => self::KEY]);
    }

    private function connection(): array
    {
        return $this->get('/integrations')->viewData('page')['props']['automation']['connection'];
    }

    private function test(Integration $integration)
    {
        return $this->post("/integrations/{$integration->id}/test");
    }

    // --- saving ----------------------------------------------------------------------------------------------

    public function test_without_a_connection_the_card_says_so_and_asks_for_nothing_false(): void
    {
        $this->actAs();

        $this->assertSame(['state' => 'not_configured', 'id' => null], $this->connection());
    }

    public function test_the_instance_and_the_api_key_are_saved_for_the_active_workspace(): void
    {
        $this->actAs();
        $workspace = Workspace::first();

        $this->save()->assertSessionHasNoErrors()->assertSessionHas('success');

        $integration = $workspace->integrations()->where('type', 'n8n')->firstOrFail();
        $this->assertSame(['n8n', true, ['base_url' => self::URL]], [$integration->name, $integration->is_active, $integration->config]);
        $this->assertSame(self::KEY, $integration->secrets['api_key']);
        $this->assertSame(1, Integration::count());
    }

    public function test_saving_is_not_a_connection(): void
    {
        $this->actAs();
        Http::fake();

        $this->save();

        $found = $this->connection();
        $this->assertSame('configured', $found['state']);
        $this->assertNull($found['lastTest']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'n8n'));
    }

    public function test_the_address_is_validated_and_reduced_to_the_instance(): void
    {
        $this->actAs();

        foreach (['', 'miempresa.app.n8n.cloud', 'ftp://miempresa.app.n8n.cloud', 'https://user:pass@miempresa.app.n8n.cloud', 'not a url', ['x']] as $bad) {
            $this->save(['base_url' => $bad])->assertSessionHasErrors('base_url');
        }
        $this->assertSame(0, Integration::count());

        $this->save(['base_url' => '  HTTPS://MiEmpresa.App.n8n.cloud/home/workflows?x=1#y '])->assertSessionHasNoErrors();
        $this->assertSame(self::URL, Integration::firstOrFail()->config['base_url']);

        $this->save(['base_url' => 'http://n8n.local:5678/'])->assertSessionHasNoErrors();
        $this->assertSame('http://n8n.local:5678', Integration::firstOrFail()->config['base_url']);
    }

    public function test_the_api_key_is_required_and_a_blank_one_keeps_the_stored_key(): void
    {
        $this->actAs();

        $this->save(['api_key' => ''])->assertSessionHasErrors('api_key');
        $this->save(['api_key' => str_repeat('k', 4097)])->assertSessionHasErrors('api_key');
        $this->assertSame(0, Integration::count());

        $this->save()->assertSessionHasNoErrors();
        $this->save(['api_key' => '', 'base_url' => 'https://otra.app.n8n.cloud'])->assertSessionHasNoErrors();

        $integration = Integration::firstOrFail();
        $this->assertSame(['https://otra.app.n8n.cloud', self::KEY], [$integration->config['base_url'], $integration->secrets['api_key']]);
        $this->assertSame(1, Integration::count(), 'one connection per Workspace');

        $this->save(['api_key' => 'NEW-KEY'])->assertSessionHasNoErrors();
        $this->assertSame('NEW-KEY', Integration::firstOrFail()->secrets['api_key']);
    }

    public function test_a_generic_connection_already_named_n8n_asks_to_be_renamed(): void
    {
        $this->actAs();
        Workspace::first()->integrations()->create(['name' => 'n8n', 'type' => 'http', 'config' => ['base_url' => 'https://x.test']]);

        $this->save()->assertSessionHasErrors('name');
        $this->assertSame(0, Workspace::first()->integrations()->where('type', 'n8n')->count());
    }

    // --- the API Key is a secret -----------------------------------------------------------------------------

    public function test_the_api_key_is_encrypted_at_rest_and_never_sent_to_the_browser(): void
    {
        $this->actAs();
        $this->save();

        $this->assertStringNotContainsString(self::KEY, (string) DB::table('integrations')->value('secrets'));
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('integrations')->value('config'));

        $body = $this->get('/integrations')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::KEY, $body);
        $this->assertDoesNotMatchRegularExpression('/api_key&quot;:&quot;[^&]/', $body);
        $this->assertTrue($this->connection()['apiKeySet']);
        $this->assertSame('', $this->connection()['form']['api_key']);
        $this->assertArrayNotHasKey('secrets', Integration::firstOrFail()->toArray());
    }

    public function test_the_api_key_is_not_in_the_audit_trail_nor_in_the_logs(): void
    {
        $this->actAs();
        Log::spy();
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->save();
        $this->test(Integration::firstOrFail());
        $this->save(['api_key' => 'ANOTHER-SECRET-456']);
        $this->delete('/integrations/n8n');

        $trail = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString(self::KEY, $trail);
        $this->assertStringNotContainsString('ANOTHER-SECRET-456', $trail);
        $this->assertSame(['created', 'tested', 'updated', 'deleted'], AuditLog::where('resource_type', 'integration')->orderBy('id')->pluck('action')->all());
        $this->assertContains('API Key de n8n', collect(AuditLog::where('action', 'updated')->firstOrFail()->changes)->pluck('field')->all());

        foreach (['info', 'warning', 'error', 'debug', 'notice'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    // --- the real connection test ----------------------------------------------------------------------------

    public function test_a_valid_key_is_verified_against_the_n8n_api(): void
    {
        $this->actAs();
        $this->save();
        Http::fake([self::URL.'/*' => Http::response(['data' => [['id' => '1']], 'nextCursor' => null])]);

        $this->test(Integration::firstOrFail())->assertSessionHas('success', 'n8n conectado correctamente.');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::URL.'/api/v1/workflows?limit=1'
            && $request->hasHeader('X-N8N-API-KEY', self::KEY));
        $found = $this->connection();
        $this->assertSame(['verified', true, 'miempresa.app.n8n.cloud'], [$found['state'], $found['lastTest']['ok'], $found['host']]);
        $this->assertStringNotContainsString(self::KEY, json_encode($found));
    }

    public function test_every_failure_has_a_useful_fixed_message_and_never_echoes_secrets(): void
    {
        $this->actAs();
        $this->save();
        $integration = Integration::firstOrFail();

        $cases = [
            [Http::response(['message' => 'unauthorized '.self::KEY], 401), 'n8n rechazó la API Key'],
            [Http::response('Forbidden', 403), 'n8n rechazó la API Key'],
            [Http::response(['message' => 'not found'], 404), 'No encontramos la API de n8n en esa dirección'],
            [Http::response('<html><body>n8n</body></html>', 200), 'no parece la API de n8n'],
            [Http::response(['unexpected' => true], 200), 'no parece la API de n8n'],
            [Http::response('', 302, ['Location' => 'https://elsewhere.example.com']), 'redirige a otra parte'],
            [Http::response('slow down', 429), 'está limitando las solicitudes'],
            [Http::response('boom '.self::KEY, 500), 'respondió con un error (HTTP 500)'],
        ];

        Http::fake([self::URL.'/*' => Http::sequence(array_column($cases, 0))
            ->pushFailedConnection('cURL error 28: Operation timed out after 10001 milliseconds')
            ->pushFailedConnection('cURL error 60: SSL certificate problem')
            ->pushFailedConnection('cURL error 6: Could not resolve host: miempresa.app.n8n.cloud')
            ->pushFailedConnection('cURL error 7: Failed to connect')]);

        $expected = array_merge(array_column($cases, 1), ['no respondió en 10 segundos', 'certificado de seguridad', 'No encontramos esa dirección', 'No se pudo establecer la conexión']);

        foreach ($expected as $fragment) {
            $this->test($integration)->assertSessionHas('error', fn ($message) => str_starts_with($message, 'No pudimos conectarnos con n8n.') && str_contains($message, $fragment));

            $integration->refresh();
            $this->assertFalse($integration->last_test_ok);
            $this->assertStringNotContainsString(self::KEY, (string) $integration->last_test_message);
            $this->assertStringNotContainsString('elsewhere', (string) $integration->last_test_message);
            $this->assertSame('error', $this->connection()['state']);
        }
    }

    public function test_an_address_that_resolves_to_an_internal_network_is_never_called(): void
    {
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['10.0.0.5']));
        $this->actAs();
        $this->save(['base_url' => 'https://internal.example.com']);
        Http::fake();

        $this->test(Integration::firstOrFail())->assertSessionHas('error', fn ($message) => str_starts_with($message, 'No pudimos conectarnos con n8n.'));

        Http::assertNothingSent();
    }

    public function test_changing_the_configuration_clears_what_was_verified(): void
    {
        $this->actAs();
        $this->save();
        Http::fake([self::URL.'/*' => Http::response(['data' => []])]);
        $this->test(Integration::firstOrFail());
        $this->assertSame('verified', $this->connection()['state']);

        $this->save(['api_key' => '', 'base_url' => 'https://otra.app.n8n.cloud']);

        $this->assertSame('configured', $this->connection()['state']);
        $this->assertNull($this->connection()['lastTest']);
    }

    public function test_the_test_needs_the_connection_to_exist_in_this_workspace_and_to_be_active(): void
    {
        $this->actAs();
        $this->save();
        $integration = Integration::firstOrFail();
        Http::fake();

        $this->post("/integrations/{$integration->id}/deactivate");
        $this->test($integration)->assertSessionHas('error', 'Activa la integración para probar la conexión.');
        Http::assertNothingSent();
    }

    // --- disconnecting ---------------------------------------------------------------------------------------

    public function test_disconnecting_removes_the_connection_and_the_api_key(): void
    {
        $this->actAs();
        $this->save();

        $this->delete('/integrations/n8n')->assertSessionHas('success');

        $this->assertSame(0, Integration::count());
        $this->assertSame('not_configured', $this->connection()['state']);
        $this->delete('/integrations/n8n')->assertSessionHas('success'); // nothing left to remove: harmless
    }

    // --- it is not a generic connection ----------------------------------------------------------------------

    public function test_n8n_does_not_appear_in_the_generic_connections(): void
    {
        $this->actAs();
        $this->save();
        $integration = Integration::firstOrFail();

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('integrations', 0)->where('catalog.types', [['value' => 'http', 'label' => 'API HTTP / REST']]));

        $this->post('/integrations', ['name' => 'Falsa', 'type' => 'n8n'])->assertSessionHasErrors('type');
        $this->put("/integrations/{$integration->id}", ['name' => 'Otro'])->assertNotFound();
    }

    // --- Workspace isolation and permissions -----------------------------------------------------------------

    public function test_each_workspace_has_its_own_connection(): void
    {
        $mine = $this->workspace('WS_A');
        $theirs = $this->workspace('WS_B');
        $foreign = $theirs->integrations()->create(['name' => 'n8n', 'type' => 'n8n', 'config' => ['base_url' => 'https://ajena.app.n8n.cloud'], 'secrets' => ['api_key' => 'FOREIGN-KEY']]);
        $this->actAs(in: $mine);

        $this->assertSame('not_configured', $this->connection()['state']);
        $this->assertStringNotContainsString('ajena', $this->get('/integrations')->getContent());

        $this->save();
        $this->assertSame(self::URL, $mine->integrations()->firstOrFail()->config['base_url']);
        $this->assertSame(['https://ajena.app.n8n.cloud', 'FOREIGN-KEY'], [$foreign->fresh()->config['base_url'], $foreign->fresh()->secrets['api_key']]);

        // The client cannot aim a change or a test at the other Workspace.
        $this->save(['workspace_id' => $theirs->id, 'integration_id' => $foreign->id])->assertSessionHasNoErrors();
        $this->post("/integrations/{$foreign->id}/test")->assertNotFound();
        $this->post("/integrations/{$foreign->id}/deactivate")->assertNotFound();
        $this->delete('/integrations/n8n');
        $this->assertNotNull($foreign->fresh());
    }

    public function test_a_normal_user_cannot_look_at_another_workspaces_n8n(): void
    {
        $other = $this->workspace('WS_B');
        $this->actAs();

        $this->get("/integrations?workspace={$other->id}")->assertForbidden();
    }

    public function test_the_platform_administrator_sees_the_connection_of_one_workspace_without_the_key_and_cannot_change_it(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $dev = $this->workspace('DESARROLLO_DEV');
        $client = $this->workspace('EMPRESA_ABC');
        $client->integrations()->create(['name' => 'n8n', 'type' => 'n8n', 'config' => ['base_url' => 'https://abc.app.n8n.cloud'], 'secrets' => ['api_key' => 'ABC-KEY-SECRET']]);
        $this->actAs('admin', superuser: true, in: $dev);

        $own = $this->get('/integrations')->viewData('page')['props']['automation']['connection'];
        $this->assertSame('not_configured', $own['state']);

        $page = $this->get("/integrations?workspace={$client->id}");
        $found = $page->viewData('page')['props']['automation']['connection'];
        $this->assertSame(['configured', 'https://abc.app.n8n.cloud'], [$found['state'], $found['baseUrl']]);
        $this->assertStringNotContainsString('ABC-KEY-SECRET', $page->getContent());
        $page->assertInertia(fn (AssertableInertia $p) => $p->where('scope.readOnly', true));

        $this->save()->assertSessionHasNoErrors();
        $this->assertSame('https://abc.app.n8n.cloud', $client->integrations()->firstOrFail()->config['base_url']);
        $this->assertSame(self::URL, $dev->integrations()->firstOrFail()->config['base_url']);
    }

    public function test_changing_n8n_needs_manage_settings(): void
    {
        $this->actAs('admin');
        $this->save();
        $integration = Integration::firstOrFail();

        $this->actAs('supervisor');
        $this->save(['base_url' => 'https://hack.app.n8n.cloud'])->assertForbidden();
        $this->delete('/integrations/n8n')->assertForbidden();
        $this->test($integration)->assertForbidden();
        $this->get('/integrations')->assertForbidden();
        $this->actAs('cliente');
        $this->save()->assertForbidden();

        $this->assertSame(self::URL, $integration->fresh()->config['base_url']);
    }

    // --- n8n -> Ava keeps working and stays separate ---------------------------------------------------------

    public function test_the_token_of_a_chatbot_is_shown_once_on_the_integrations_page_and_is_not_the_api_key(): void
    {
        $this->actAs();
        $this->save();
        $bot = Workspace::first()->chatbots()->create(['name' => 'Ventas', 'instructions' => 'Sé breve']);

        $this->post("/chatbots/{$bot->id}/agent-token")->assertSessionHas('agent_token');
        $token = session('agent_token');

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('newToken.token', $token)->where('newToken.chatbotId', $bot->id)
            ->where('automation.state', 'configured')->where('automation.connection.state', 'configured'));
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->where('newToken', null));
        $this->assertNotSame(self::KEY, $token);
        $this->assertStringNotContainsString($token, $this->get('/integrations')->getContent());

        // The chatbot token keeps working for n8n -> Ava, whatever the state of the n8n API connection.
        $this->withToken($token)->getJson('/api/agent/config')->assertOk()->assertJsonPath('chatbot.instructions', 'Sé breve');
        $this->delete('/integrations/n8n');
        $this->withToken($token)->getJson('/api/agent/config')->assertOk();
    }

    public function test_a_token_generated_in_another_workspace_is_never_shown_here(): void
    {
        $this->actAs();
        $bot = $this->workspace('WS_B')->chatbots()->create(['name' => 'Ajeno']);

        $this->post("/chatbots/{$bot->id}/agent-token")->assertNotFound();
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->where('newToken', null));
    }
}
