<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Integrations by Workspace: one global catalog of channels, and each Workspace's own, isolated configuration. */
class ChannelIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAB-SUPER-SECRET-TOKEN-987';

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['phone_number_id' => '100200300', 'access_token' => self::TOKEN], $overrides);
    }

    private function channel(string $key = 'whatsapp'): array
    {
        return collect($this->get('/integrations')->viewData('page')['props']['channels'])->firstWhere('key', $key);
    }

    // --- the global catalog ----------------------------------------------------------------------------------

    public function test_the_catalog_lists_each_channel_once_and_says_what_this_workspace_has(): void
    {
        $this->actAs();

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Integrations/Index')->has('channels', 4)
            ->where('channels', fn ($channels) => collect($channels)->pluck('key')->all() === ['web', 'whatsapp', 'instagram', 'messenger'])
            ->where('channels.1.available', true)->where('channels.1.integration', null)->where('channels.1.testable', true)
            ->where('channels.0.available', true)->where('channels.0.testable', false)
            ->where('channels.2.available', false)->where('channels.3.available', false)
            ->has('integrations', 0)->where('scope.readOnly', false)->where('scope.canChoose', false));
    }

    // --- configuring -----------------------------------------------------------------------------------------

    public function test_a_workspace_configures_its_own_whatsapp_account(): void
    {
        $this->actAs();
        $workspace = Workspace::first();

        $this->put('/integrations/channels/whatsapp', $this->payload())->assertSessionHasNoErrors()->assertSessionHas('success', 'Canal configurado correctamente');

        $integration = $workspace->integrations()->firstOrFail();
        $this->assertSame(['WhatsApp', 'whatsapp', true], [$integration->name, $integration->type, $integration->is_active]);
        $this->assertSame(['phone_number_id' => '100200300'], $integration->config);
        $this->assertSame(self::TOKEN, $integration->secrets['access_token']);

        $channel = $this->channel();
        $this->assertTrue($channel['integration']['isActive']);
        $this->assertTrue($channel['integration']['form']['access_token_set']);
    }

    public function test_the_token_is_encrypted_at_rest_and_never_sent_to_the_browser(): void
    {
        $this->actAs();
        $this->put('/integrations/channels/whatsapp', $this->payload());

        $raw = DB::table('integrations')->value('secrets');
        $this->assertStringNotContainsString(self::TOKEN, $raw);
        $this->assertStringNotContainsString(self::TOKEN, (string) DB::table('integrations')->value('config'));

        $body = $this->get('/integrations')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::TOKEN, $body);
        $this->assertStringNotContainsString('"secrets"', $body);

        $this->assertArrayNotHasKey('secrets', Integration::firstOrFail()->toArray());
    }

    public function test_editing_keeps_the_token_when_left_blank_and_replaces_it_when_given(): void
    {
        $this->actAs();
        $this->put('/integrations/channels/whatsapp', $this->payload());

        $this->put('/integrations/channels/whatsapp', $this->payload(['access_token' => '', 'phone_number_id' => '999888777']))->assertSessionHasNoErrors();
        $integration = Integration::firstOrFail();
        $this->assertSame(['999888777', self::TOKEN], [$integration->config['phone_number_id'], $integration->secrets['access_token']]);
        $this->assertSame(1, Integration::count(), 'one integration per channel and Workspace');

        $this->put('/integrations/channels/whatsapp', $this->payload(['access_token' => 'NEW-TOKEN']));
        $this->assertSame('NEW-TOKEN', Integration::firstOrFail()->secrets['access_token']);
    }

    public function test_the_fields_are_validated(): void
    {
        $this->actAs();

        $this->put('/integrations/channels/whatsapp', $this->payload(['access_token' => '']))->assertSessionHasErrors('access_token');
        $this->put('/integrations/channels/whatsapp', $this->payload(['phone_number_id' => 'abc']))->assertSessionHasErrors('phone_number_id');
        $this->assertSame(0, Integration::count());
    }

    public function test_only_available_channels_with_an_integration_can_be_configured(): void
    {
        $this->actAs();

        $this->put('/integrations/channels/instagram', $this->payload())->assertNotFound();
        $this->put('/integrations/channels/unknown', $this->payload())->assertNotFound();
        $this->assertSame(0, Integration::count());
    }

    public function test_configuring_a_channel_needs_manage_settings(): void
    {
        $this->actAs('supervisor');
        $this->put('/integrations/channels/whatsapp', $this->payload())->assertForbidden();
        $this->actAs('cliente');
        $this->put('/integrations/channels/whatsapp', $this->payload())->assertForbidden();
        $this->get('/integrations')->assertForbidden();
        $this->assertSame(0, Integration::count());
    }

    // --- isolation between Workspaces ------------------------------------------------------------------------

    public function test_each_workspace_has_its_own_configuration_that_never_mixes(): void
    {
        $this->actAs();
        $mine = Workspace::first();
        $theirs = $this->workspace('OTHER_WS');
        $foreign = $theirs->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => '777000111'],
            'secrets' => ['access_token' => 'OTHER-WORKSPACE-TOKEN'],
        ]);

        // Mine is still unconfigured: the other Workspace's account does not leak into this catalog.
        $this->assertNull($this->channel()['integration']);
        $this->assertStringNotContainsString('777000111', $this->get('/integrations')->getContent());

        $this->put('/integrations/channels/whatsapp', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, $mine->integrations()->count());
        $this->assertSame('100200300', $mine->integrations()->first()->config['phone_number_id']);
        $foreign->refresh();
        $this->assertSame(['777000111', 'OTHER-WORKSPACE-TOKEN'], [$foreign->config['phone_number_id'], $foreign->secrets['access_token']]);
    }

    public function test_the_client_cannot_aim_a_channel_configuration_at_another_workspace(): void
    {
        $this->actAs();
        $other = $this->workspace('OTHER_WS');

        $this->put('/integrations/channels/whatsapp', $this->payload(['workspace_id' => $other->id]))->assertSessionHasNoErrors();
        $this->put("/integrations/channels/whatsapp?workspace={$other->id}", $this->payload(['phone_number_id' => '555666777']))->assertSessionHasNoErrors();

        $this->assertSame(0, $other->integrations()->count());
        $this->assertSame('555666777', Workspace::first()->integrations()->firstOrFail()->config['phone_number_id']);
    }

    public function test_another_workspaces_integration_cannot_be_activated_deactivated_or_tested(): void
    {
        $this->actAs();
        $foreign = $this->workspace('OTHER_WS')->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => '777000111'], 'secrets' => ['access_token' => 'x'],
        ]);

        $this->post("/integrations/{$foreign->id}/deactivate")->assertNotFound();
        $this->post("/integrations/{$foreign->id}/activate")->assertNotFound();
        $this->post("/integrations/{$foreign->id}/test")->assertNotFound();
        $this->put("/integrations/{$foreign->id}", ['name' => 'Hackeada'])->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_a_normal_user_cannot_look_at_another_workspaces_integrations(): void
    {
        $this->actAs();
        $other = $this->workspace('OTHER_WS');

        $this->get("/integrations?workspace={$other->id}")->assertForbidden();
        $this->actAs('admin', superuser: true); // a superuser inside a client Workspace is limited to it
        $this->get("/integrations?workspace={$other->id}")->assertForbidden();
    }

    public function test_the_administrator_picks_one_workspace_and_sees_only_its_configuration_read_only(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $dev = $this->workspace('DESARROLLO_DEV');
        $abc = $this->workspace('EMPRESA_ABC');
        $xyz = $this->workspace('EMPRESA_XYZ');
        $abc->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '111222333'], 'secrets' => ['access_token' => 'ABC-TOKEN']]);
        $xyz->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => false, 'config' => ['phone_number_id' => '999000999'], 'secrets' => ['access_token' => 'XYZ-TOKEN']]);
        $this->actAs('admin', superuser: true);

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.canChoose', true)->where('scope.readOnly', false)->where('scope.workspace.code', 'DESARROLLO_DEV')
            ->where('channels.1.integration', null)->has('channels', 4));

        $abcPage = $this->get("/integrations?workspace={$abc->id}");
        $abcPage->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.readOnly', true)->where('scope.workspace.code', 'EMPRESA_ABC')
            ->where('channels.1.integration.isActive', true)->where('channels.1.integration.summary.rows.0.value', '111222333')
            ->has('channels', 4));
        $this->assertStringNotContainsString('ABC-TOKEN', $abcPage->getContent());
        $this->assertStringNotContainsString('XYZ-TOKEN', $abcPage->getContent());
        $this->assertStringNotContainsString('999000999', $abcPage->getContent());

        $this->get("/integrations?workspace={$xyz->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channels.1.integration.isActive', false)->where('channels.1.integration.summary.rows.0.value', '999000999'));

        // The catalog stays four channels, never one per Workspace.
        $this->get("/integrations?workspace={$abc->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('channels', 4));

        // Looking is not changing: writes always go to the active Workspace.
        $this->put("/integrations/channels/whatsapp?workspace={$abc->id}", $this->payload(['phone_number_id' => '123123123']))->assertSessionHasNoErrors();
        $this->assertSame('111222333', $abc->integrations()->first()->config['phone_number_id']);
        $this->assertSame('123123123', $dev->integrations()->firstOrFail()->config['phone_number_id']);
    }

    public function test_there_is_no_view_that_mixes_every_workspaces_integrations(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $this->workspace('DESARROLLO_DEV');
        $this->actAs('admin', superuser: true);

        $this->get('/integrations?workspace=all')->assertNotFound();
        $this->get('/integrations?workspace=999999')->assertNotFound();
    }

    // --- generic connections stay as they were ---------------------------------------------------------------

    public function test_a_channel_integration_is_not_a_generic_connection(): void
    {
        $this->actAs();
        $this->put('/integrations/channels/whatsapp', $this->payload());
        $channelIntegration = Integration::firstOrFail();

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->has('integrations', 0)->where('catalog.types', [['value' => 'http', 'label' => 'API HTTP / REST']]));

        $this->put("/integrations/{$channelIntegration->id}", ['name' => 'Otro'])->assertNotFound();
        $this->post('/integrations', ['name' => 'Falsa', 'type' => 'whatsapp'])->assertSessionHasErrors('type');
        $this->assertSame('WhatsApp', $channelIntegration->fresh()->name);
    }

    public function test_a_generic_connection_with_the_channels_name_asks_to_be_renamed(): void
    {
        $this->actAs();
        Workspace::first()->integrations()->create(['name' => 'WhatsApp', 'type' => 'http', 'config' => ['base_url' => 'https://x.test']]);

        $this->put('/integrations/channels/whatsapp', $this->payload())->assertSessionHasErrors('name');
        $this->assertSame(0, Workspace::first()->integrations()->where('type', 'whatsapp')->count());
    }

    // --- activation, tests, audit ----------------------------------------------------------------------------

    public function test_the_channel_integration_is_deactivated_and_reactivated_never_deleted(): void
    {
        $this->actAs();
        $this->put('/integrations/channels/whatsapp', $this->payload());
        $integration = Integration::firstOrFail();

        $this->post("/integrations/{$integration->id}/deactivate")->assertSessionHas('success');
        $this->assertFalse($this->channel()['integration']['isActive']);
        $this->post("/integrations/{$integration->id}/activate")->assertSessionHas('success');
        $this->assertTrue($this->channel()['integration']['isActive']);
    }

    // --- connection test (WhatsApp talks to Meta; the web widget cannot be tested) ---------------------------

    private function graphUrl(string $id = '100200300'): string
    {
        return rtrim(config('integrations.whatsapp.graph_url'), '/').'/'.config('integrations.whatsapp.graph_version').'/'.$id.'*';
    }

    private function configuredWhatsApp(): Integration
    {
        $this->put('/integrations/channels/whatsapp', $this->payload());

        return Integration::firstOrFail();
    }

    public function test_a_valid_number_is_verified_with_meta_using_the_stored_token(): void
    {
        $this->actAs();
        $integration = $this->configuredWhatsApp();
        Http::fake([$this->graphUrl() => Http::response(['id' => '100200300', 'display_phone_number' => '+57 300 000 0000', 'verified_name' => 'Tienda ABC'])]);

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('success', 'Número verificado con Meta: Tienda ABC · +57 300 000 0000.');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && str_contains($request->url(), '/100200300'));
        $integration->refresh();
        $this->assertTrue($integration->last_test_ok);
        $this->assertNotNull($integration->last_tested_at);

        $channel = $this->channel();
        $this->assertTrue($channel['integration']['lastTest']['ok']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($channel));
    }

    public function test_meta_failures_are_reported_with_fixed_messages_and_never_echo_the_token_or_the_response(): void
    {
        $this->actAs();
        $integration = $this->configuredWhatsApp();

        $cases = [
            [Http::response(['error' => ['message' => 'Invalid OAuth access token '.self::TOKEN, 'code' => 190]], 401), 'Meta rechazó el token: es inválido o expiró.'],
            [Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400), 'Meta no encontró ese ID de número o el token no tiene permiso sobre él.'],
            [Http::response(['id' => '999'], 200), 'Meta respondió con un número distinto al configurado.'],
            [Http::response('boom', 500), 'Meta respondió HTTP 500.'],
        ];

        Http::fake([$this->graphUrl() => Http::sequence(array_column($cases, 0))->pushFailedConnection('cURL error 6: could not resolve host')]);

        foreach ($cases as [, $message]) {
            $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error', $message);
            $integration->refresh();
            $this->assertFalse($integration->last_test_ok);
            $this->assertSame($message, $integration->last_test_message);
            $this->assertStringNotContainsString(self::TOKEN, (string) $integration->last_test_message);
        }

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error', 'No se pudo conectar con Meta.');
    }

    public function test_the_state_is_verified_only_after_a_real_test_and_editing_resets_it(): void
    {
        $this->actAs();
        $integration = $this->configuredWhatsApp();

        $this->assertNull($this->channel()['integration']['lastTest'], 'saved credentials are not a verified connection');

        Http::fake([$this->graphUrl() => Http::response(['id' => '100200300'])]);
        $this->post("/integrations/{$integration->id}/test");
        $this->assertTrue($this->channel()['integration']['lastTest']['ok']);

        $this->put('/integrations/channels/whatsapp', $this->payload(['access_token' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->channel()['integration']['lastTest']);
    }

    public function test_an_inactive_integration_is_not_tested_and_nothing_is_sent(): void
    {
        $this->actAs();
        Http::preventStrayRequests();
        $integration = $this->configuredWhatsApp();
        $this->post("/integrations/{$integration->id}/deactivate");

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error', 'Activa la integración para probar la conexión.');

        Http::assertNothingSent();
    }

    public function test_the_web_widget_integration_has_no_connection_test_and_sends_nothing(): void
    {
        $this->actAs();
        Http::preventStrayRequests();
        $this->put('/integrations/channels/web', ['webhook_url' => 'https://n8n.example.com/webhook/ava'])->assertSessionHasNoErrors();
        $integration = Integration::firstOrFail();

        $this->post("/integrations/{$integration->id}/test")->assertSessionHas('error', 'Esta integración todavía no admite prueba de conexión.');

        Http::assertNothingSent();
        $this->assertNull($integration->fresh()->last_tested_at);
    }

    public function test_the_web_widget_integration_is_validated_and_keeps_its_secret_write_only(): void
    {
        $this->actAs();

        $this->put('/integrations/channels/web', [])->assertSessionHasErrors('webhook_url');
        $this->put('/integrations/channels/web', ['webhook_url' => 'ftp://x.test/hook'])->assertSessionHasErrors('webhook_url');
        $this->put('/integrations/channels/web', ['webhook_url' => 'https://user:pass@x.test/hook'])->assertSessionHasErrors('webhook_url');
        $this->put('/integrations/channels/web', ['webhook_url' => 'https://x.test/hook', 'allowed_origins' => "https://ok.test\nnot a site"])->assertSessionHasErrors('allowed_origins');
        $this->assertSame(0, Integration::count());

        $this->put('/integrations/channels/web', ['webhook_url' => 'https://x.test/hook', 'webhook_secret' => 'shared-SECRET-1', 'allowed_origins' => "https://Www.Ok.test/\nhttps://ok.test"])->assertSessionHasNoErrors();
        $integration = Integration::firstOrFail();
        $this->assertSame(['https://www.ok.test', 'https://ok.test'], $integration->config['allowed_origins']);
        $this->assertSame('shared-SECRET-1', $integration->secrets['webhook_secret']);

        $body = $this->get('/integrations')->getContent();
        $this->assertStringNotContainsString('shared-SECRET-1', $body);
        $this->assertTrue($this->channel('web')['integration']['form']['webhook_secret_set']);

        $this->put('/integrations/channels/web', ['webhook_url' => 'https://x.test/other'])->assertSessionHasNoErrors();
        $this->assertSame('shared-SECRET-1', Integration::firstOrFail()->secrets['webhook_secret'], 'a blank secret keeps the stored one');
    }

    public function test_configuring_a_channel_is_audited_without_the_token(): void
    {
        $this->actAs();
        $this->put('/integrations/channels/whatsapp', $this->payload());
        $this->put('/integrations/channels/whatsapp', $this->payload(['access_token' => 'ANOTHER-TOKEN-123', 'phone_number_id' => '555666777']));

        $logs = AuditLog::where('resource_type', 'integration')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $logs->pluck('action')->all());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($logs->toArray()));
        $this->assertStringNotContainsString('ANOTHER-TOKEN-123', json_encode($logs->toArray()));

        $fields = collect($logs[1]->changes)->pluck('field')->all();
        $this->assertContains('ID del número', $fields);
        $this->assertContains('Token de acceso', $fields);
    }
}
