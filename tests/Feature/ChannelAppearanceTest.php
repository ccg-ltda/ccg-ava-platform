<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Channels and appearance: the client chooses how the button / widget of each channel looks, per Workspace + chatbot +
 * channel; the public script receives only that presentation, and only while the channel really works.
 */
class ChannelAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://n8n.example.com/webhook/ava-web';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function workspace(string $code = 'WS_A'): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function actAs(Workspace $workspace, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, $role);
        $workspace->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);

        $this->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function bot(Workspace $workspace, string $name = 'Asistente'): Chatbot
    {
        return $workspace->chatbots()->create(['name' => $name]);
    }

    private function webIntegration(Workspace $workspace): void
    {
        $workspace->integrations()->create([
            'name' => 'Web', 'type' => 'web', 'is_active' => true,
            'config' => ['webhook_url' => self::HOOK, 'allowed_origins' => []],
            'secrets' => ['webhook_secret' => 'shared-SECRET-123'],
        ]);
    }

    private function whatsApp(Workspace $workspace, bool $verified = true, bool $active = true): void
    {
        $workspace->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => $active,
            'config' => ['phone_number_id' => '100200300'] + ($verified ? ['display_number' => '573001234567'] : []),
            'secrets' => ['access_token' => 'EAAB-private-token-XYZ'],
            'last_tested_at' => $verified ? now() : null,
            'last_test_ok' => $verified ? true : null,
        ]);
    }

    private function appearance(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true, 'button_text' => '¿Necesitas ayuda?', 'header_title' => 'Soporte', 'welcome_message' => 'Hola, ¿en qué te ayudo?',
            'icon' => 'channel', 'primary_color' => '#0f766e', 'text_color' => null, 'size' => 72, 'shape' => 30,
            'position' => 'bottom-left', 'shadow' => 3, 'widget_size' => 420, 'radius' => 24, 'open_behavior' => 'auto',
        ];
    }

    private function whatsappAppearance(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true, 'button_text' => 'Escríbenos', 'message' => 'Hola, quiero información', 'icon' => 'channel',
            'primary_color' => '#128c7e', 'text_color' => null, 'size' => 60, 'shape' => 50, 'position' => 'bottom-right', 'shadow' => 2,
        ];
    }

    private function save(Chatbot $bot, string $channel, array $data)
    {
        return $this->put("/chatbots/{$bot->id}/channels/{$channel}/appearance", $data);
    }

    private function activate(Chatbot $bot, string $channel): string
    {
        $this->put("/chatbots/{$bot->id}/channels/{$channel}", ['is_active' => true])->assertSessionHas('success');

        return ChatbotChannel::where('chatbot_id', $bot->id)->where('channel', $channel)->firstOrFail()->public_key;
    }

    // --- saving ----------------------------------------------------------------------------------------------

    public function test_the_web_appearance_is_saved_and_reloaded_for_that_chatbot_and_channel(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors()->assertSessionHas('success');

        $web = collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['appearance']['channels'])->firstWhere('key', 'web');
        $this->assertSame('widget', $web['kind']);
        $this->assertEquals($this->appearance(), $web['values']);
        $this->assertSame(1, $bot->appearances()->count());
        $this->assertSame($workspace->id, $bot->appearances()->first()->workspace_id);
    }

    public function test_saving_again_replaces_the_values_without_creating_another_row(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors();
        $this->save($bot, 'web', $this->appearance(['size' => 48, 'button_text' => '']))->assertSessionHasNoErrors();

        $this->assertSame(1, $bot->appearances()->count());
        $saved = $bot->appearances()->first()->settings;
        $this->assertSame(48, $saved['size']);
        $this->assertNull($saved['button_text']);
    }

    public function test_the_whatsapp_appearance_is_independent_from_the_web_one(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors();
        $this->save($bot, 'whatsapp', $this->whatsappAppearance())->assertSessionHasNoErrors();

        $channels = collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['appearance']['channels'])->keyBy('key');
        $this->assertSame('button', $channels['whatsapp']['kind']);
        $this->assertSame('Escríbenos', $channels['whatsapp']['values']['button_text']);
        $this->assertSame('¿Necesitas ayuda?', $channels['web']['values']['button_text']);
        $this->assertArrayNotHasKey('widget_size', $channels['whatsapp']['values']);
        $this->assertArrayNotHasKey('message', $channels['web']['values']);
        $this->assertSame(2, $bot->appearances()->count());
    }

    public function test_the_page_knows_whether_a_look_was_saved_and_confirms_only_after_saving(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);
        $saved = fn (string $key) => collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['appearance']['channels'])->firstWhere('key', $key)['saved'];

        $this->assertFalse($saved('web'));
        $this->save($bot, 'web', $this->appearance(['size' => 200]))->assertSessionHasErrors('size')->assertSessionMissing('success');
        $this->assertFalse($saved('web'));

        $this->save($bot, 'web', $this->appearance())->assertSessionHas('success', 'Cambios guardados correctamente');
        $this->assertTrue($saved('web'));
        $this->assertFalse($saved('whatsapp'));
    }

    public function test_an_unsaved_channel_shows_defaults(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $web = collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['appearance']['channels'])->firstWhere('key', 'web');

        $this->assertSame(60, $web['values']['size']);
        $this->assertSame(50, $web['values']['shape']);
        $this->assertNull($web['values']['primary_color']);
        $this->assertSame($workspace->settingsOrDefault()->primary_color, $web['defaultPrimary']);
    }

    public function test_a_look_saved_with_the_old_named_choices_keeps_its_appearance_as_numbers(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);
        $bot->appearances()->create([
            'workspace_id' => $workspace->id, 'channel' => 'web',
            'settings' => ['size' => 'lg', 'shape' => 'rounded', 'shadow' => 'strong', 'widget_size' => 'sm', 'radius' => 20],
        ]);

        $values = collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['appearance']['channels'])->firstWhere('key', 'web')['values'];

        $this->assertSame([72, 30, 3, 320, 20], [$values['size'], $values['shape'], $values['shadow'], $values['widget_size'], $values['radius']]);
    }

    // --- validation ------------------------------------------------------------------------------------------

    public function test_values_that_would_break_the_design_are_refused(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $invalid = [
            'primary_color' => ['red', '#12345', '#GGGGGG', 'url(javascript:alert(1))', '#0f766e;position:fixed'],
            'text_color' => ['white', '#ffff'],
            'size' => ['huge', '', 47, 50, 76, 44, 60.5],
            'shape' => ['triangle', -5, 55, 12],
            'position' => ['top-left'],
            'shadow' => ['glow', -1, 5, 1.5],
            'widget_size' => ['full', 310, 430, 375],
            'open_behavior' => ['always'],
            'icon' => ['custom'],
            'radius' => [-1, 29, 'abc', 10.5],
            'enabled' => ['maybe'],
            'button_text' => [str_repeat('a', 41), ['x']],
            'header_title' => [str_repeat('a', 41)],
            'welcome_message' => [str_repeat('a', 281)],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $this->save($bot, 'web', $this->appearance([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, $bot->appearances()->count());
    }

    public function test_the_whatsapp_message_is_limited(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'whatsapp', $this->whatsappAppearance(['message' => str_repeat('a', 201)]))->assertSessionHasErrors('message');
        $this->save($bot, 'whatsapp', $this->whatsappAppearance(['message' => str_repeat('a', 200)]))->assertSessionHasNoErrors();
    }

    public function test_an_unreadable_text_color_is_refused_but_a_contrasting_one_is_accepted(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'web', $this->appearance(['primary_color' => '#ffffff', 'text_color' => '#fefefe']))->assertSessionHasErrors('text_color');
        $this->save($bot, 'web', $this->appearance(['primary_color' => '#ffffff', 'text_color' => '#111111']))->assertSessionHasNoErrors();
        $this->assertSame('#111111', $bot->appearances()->first()->settings['text_color']);
    }

    public function test_extra_keys_are_ignored_and_never_stored(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'whatsapp', $this->whatsappAppearance(['header_title' => 'nope', 'workspace_id' => 999, 'access_token' => 'x', 'custom_css' => 'body{display:none}']))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(array_keys($this->whatsappAppearance()), array_keys($bot->appearances()->first()->settings));
    }

    // --- unknown things, permissions, isolation ------------------------------------------------------------------

    public function test_an_unknown_chatbot_or_channel_is_a_404(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->put('/chatbots/9999/channels/web/appearance', $this->appearance())->assertNotFound();
        $this->save($bot, 'sms', $this->appearance())->assertNotFound();
        $this->save($bot, 'instagram', $this->appearance())->assertNotFound();
        $this->save($bot, 'messenger', $this->appearance())->assertNotFound();
        $this->assertSame(0, $bot->appearances()->count());
    }

    public function test_saving_needs_manage_chatbots(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace, 'supervisor');
        $bot = $this->bot($workspace);

        $this->get("/chatbots/{$bot->id}")->assertOk();
        $this->save($bot, 'web', $this->appearance())->assertForbidden();
        $this->assertSame(0, $bot->appearances()->count());
    }

    public function test_direct_requests_without_the_permission_or_a_session_cannot_save(): void
    {
        $workspace = $this->workspace();
        $bot = $this->bot($workspace);

        $this->put("/chatbots/{$bot->id}/channels/web/appearance", $this->appearance())->assertRedirect();

        $this->actAs($workspace, 'cliente');
        $this->save($bot, 'web', $this->appearance())->assertForbidden();
        $this->save($bot, 'whatsapp', $this->whatsappAppearance())->assertForbidden();
        $this->assertSame(0, $bot->appearances()->count());
    }

    public function test_a_chatbot_of_another_workspace_can_neither_be_read_nor_changed(): void
    {
        $mine = $this->workspace('WS_A');
        $other = $this->workspace('WS_B');
        $this->actAs($mine);
        $foreign = $this->bot($other, 'Ajeno');
        $foreign->appearances()->create(['workspace_id' => $other->id, 'channel' => 'web', 'settings' => ['primary_color' => '#ff0000']]);

        $this->get("/chatbots/{$foreign->id}")->assertNotFound();
        $this->save($foreign, 'web', $this->appearance())->assertNotFound();
        $this->assertSame('#ff0000', $foreign->appearances()->first()->settings['primary_color']);
    }

    public function test_two_chatbots_of_a_workspace_never_share_their_appearance(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $one = $this->bot($workspace, 'Uno');
        $two = $this->bot($workspace, 'Dos');

        $this->save($one, 'web', $this->appearance(['primary_color' => '#0f766e']))->assertSessionHasNoErrors();

        $values = collect($this->get("/chatbots/{$two->id}")->viewData('page')['props']['appearance']['channels'])->firstWhere('key', 'web')['values'];
        $this->assertNull($values['primary_color']);
    }

    public function test_the_database_refuses_an_appearance_that_crosses_workspaces(): void
    {
        $mine = $this->workspace('WS_A');
        $other = $this->workspace('WS_B');
        $bot = $this->bot($mine);

        try {
            $bot->appearances()->create(['workspace_id' => $other->id, 'channel' => 'web', 'settings' => []]);
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());

            return;
        }

        $this->fail('The database accepted an appearance for a chatbot of another Workspace.');
    }

    public function test_changes_are_audited_with_each_setting_by_name(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors();
        $this->save($bot, 'web', $this->appearance(['size' => 48]))->assertSessionHasNoErrors();

        $log = AuditLog::where('resource_type', 'chatbot_channel_appearance')->where('action', 'updated')->firstOrFail();
        $this->assertSame('Tamaño del botón', $log->changes[0]['field']);
        $this->assertSame('72', $log->changes[0]['before']);
        $this->assertSame('48', $log->changes[0]['after']);
    }

    // --- channels offered --------------------------------------------------------------------------------------------

    public function test_instagram_and_messenger_are_listed_as_unavailable_with_nothing_to_configure(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);

        $channels = collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['appearance']['channels'])->keyBy('key');

        $this->assertSame(['key' => 'instagram', 'label' => 'Instagram', 'available' => false], $channels['instagram']);
        $this->assertSame(['key' => 'messenger', 'label' => 'Messenger', 'available' => false], $channels['messenger']);
    }

    public function test_whatsapp_without_a_verified_number_has_no_destination_and_no_install_code(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $bot = $this->bot($workspace);
        $page = fn () => $this->get("/chatbots/{$bot->id}")->viewData('page')['props'];
        $destination = fn () => collect($page()['appearance']['channels'])->firstWhere('key', 'whatsapp')['destination'];

        $this->assertFalse($destination()['ready']);
        $this->assertSame('WhatsApp todavía no está configurado. Configura primero el canal WhatsApp.', $destination()['message']);

        $this->whatsApp($workspace, verified: false);
        $this->assertFalse($destination()['ready']);
        $this->assertStringContainsString('Probar conexión', $destination()['message']);

        $workspace->integrations()->where('type', 'whatsapp')->first()->update(['is_active' => false]);
        $this->assertStringContainsString('inactiva', $destination()['message']);

        $workspace->integrations()->where('type', 'whatsapp')->first()->update(['is_active' => true, 'config' => ['phone_number_id' => '100200300', 'display_number' => '573001234567'], 'last_tested_at' => now(), 'last_test_ok' => true]);
        $this->assertTrue($destination()['ready']);
        $this->assertSame('573001234567', $destination()['number']);
    }

    public function test_a_successful_whatsapp_test_keeps_the_number_meta_reported(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $workspace->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => '100200300'], 'secrets' => ['access_token' => 'EAAB-private-token-XYZ'],
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '100200300', 'display_phone_number' => '+57 300 123 4567', 'verified_name' => 'Acme'])]);

        $integration = $workspace->integrations()->first();
        $this->post("/integrations/{$integration->id}/test")->assertSessionHasNoErrors();

        $this->assertSame('573001234567', $integration->fresh()->config['display_number']);
        $this->assertTrue($integration->fresh()->last_test_ok);
    }

    // --- the public configuration ----------------------------------------------------------------------------------------

    public function test_the_public_config_carries_the_saved_look_and_nothing_private(): void
    {
        $workspace = $this->workspace('WS_SECRET_CODE');
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $bot = $this->bot($workspace);
        $bot->update(['instructions' => 'INSTRUCCIONES-PRIVADAS']);
        $key = $this->activate($bot, 'web');
        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors();

        $response = $this->getJson("/api/widget/{$key}/config")->assertOk();

        $response->assertJsonPath('type', 'widget')
            ->assertJsonPath('primaryColor', '#0f766e')
            ->assertJsonPath('style.buttonText', '¿Necesitas ayuda?')
            ->assertJsonPath('style.headerTitle', 'Soporte')
            ->assertJsonPath('style.welcomeMessage', 'Hola, ¿en qué te ayudo?')
            ->assertJsonPath('style.size', 72)
            ->assertJsonPath('style.position', 'bottom-left')
            ->assertJsonPath('style.radius', 24)
            ->assertJsonPath('style.openBehavior', 'auto');

        foreach (['WS_SECRET_CODE', 'shared-SECRET-123', 'n8n.example.com', 'webhook', 'INSTRUCCIONES-PRIVADAS', 'workspace', 'agent_token', 'integration', 'EAAB'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $response->getContent());
        }
    }

    public function test_the_public_config_changes_when_the_look_is_saved_again_with_the_same_key(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'web');

        $this->getJson("/api/widget/{$key}/config")->assertJsonPath('style.size', 60);
        $this->save($bot, 'web', $this->appearance(['size' => 48]))->assertSessionHasNoErrors();

        $this->getJson("/api/widget/{$key}/config")->assertJsonPath('style.size', 48);
    }

    public function test_the_look_of_one_chatbot_never_reaches_the_widget_of_another(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $one = $this->bot($workspace, 'Uno');
        $key = $this->activate($one, 'web');
        $two = $this->bot($workspace, 'Dos');
        $this->save($two, 'web', $this->appearance(['button_text' => 'SOLO DOS']))->assertSessionHasNoErrors();

        $this->getJson("/api/widget/{$key}/config")->assertOk()->assertJsonPath('name', 'Uno')->assertJsonPath('style.buttonText', null);
    }

    public function test_a_hidden_button_or_a_disabled_chatbot_does_not_exist_for_the_public(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'web');
        $this->getJson("/api/widget/{$key}/config")->assertOk();

        $this->save($bot, 'web', $this->appearance(['enabled' => false]))->assertSessionHasNoErrors();
        $this->getJson("/api/widget/{$key}/config")->assertNotFound();
        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'abcdefgh1234', 'message' => 'hola'])->assertNotFound();

        $this->save($bot, 'web', $this->appearance(['enabled' => true]));
        $this->getJson("/api/widget/{$key}/config")->assertOk();

        $bot->update(['is_active' => false]);
        $this->getJson("/api/widget/{$key}/config")->assertNotFound();
    }

    public function test_saving_a_look_does_not_make_an_inactive_channel_public(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'web');
        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => false]);

        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors();

        $this->getJson("/api/widget/{$key}/config")->assertNotFound();
    }

    public function test_the_install_code_is_the_same_script_and_holds_only_the_public_key(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'web');

        $web = fn () => collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['channels'])->firstWhere('key', 'web')['installCode'];
        $before = $web();
        $this->save($bot, 'web', $this->appearance())->assertSessionHasNoErrors();

        $this->assertSame('<script src="'.url('/widget/ava-widget.js').'" data-chatbot="'.$key.'" defer></script>', $before);
        $this->assertSame($before, $web());
        $this->assertStringNotContainsString('shared-SECRET-123', $before);
        $this->assertStringNotContainsString((string) $workspace->id, str_replace($key, '', $before));
    }

    public function test_the_whatsapp_button_links_to_the_verified_number_with_the_message(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->whatsApp($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'whatsapp');
        $this->save($bot, 'whatsapp', $this->whatsappAppearance())->assertSessionHasNoErrors();

        $response = $this->getJson("/api/widget/{$key}/config")->assertOk();

        $response->assertJsonPath('type', 'button')
            ->assertJsonPath('href', 'https://wa.me/573001234567?text='.rawurlencode('Hola, quiero información'))
            ->assertJsonPath('style.buttonText', 'Escríbenos');
        $this->assertStringNotContainsString('EAAB-private-token-XYZ', $response->getContent());
        $this->assertStringNotContainsString('100200300', $response->getContent());
    }

    public function test_the_whatsapp_button_disappears_when_there_is_no_valid_destination(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->whatsApp($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'whatsapp');
        $this->getJson("/api/widget/{$key}/config")->assertOk();

        $workspace->integrations()->where('type', 'whatsapp')->first()->update(['last_test_ok' => false]);

        $this->getJson("/api/widget/{$key}/config")->assertNotFound();
    }

    public function test_the_install_code_of_whatsapp_needs_a_valid_destination(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->whatsApp($workspace);
        $bot = $this->bot($workspace);
        $this->activate($bot, 'whatsapp');
        $code = fn () => collect($this->get("/chatbots/{$bot->id}")->viewData('page')['props']['channels'])->firstWhere('key', 'whatsapp')['installCode'];

        $this->assertNotNull($code());

        $workspace->integrations()->where('type', 'whatsapp')->first()->update(['last_test_ok' => false]);
        $this->assertNull($code());
    }

    public function test_a_whatsapp_key_cannot_be_used_to_send_chat_messages(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->whatsApp($workspace);
        $bot = $this->bot($workspace);
        $key = $this->activate($bot, 'whatsapp');
        Http::fake();

        $this->postJson("/api/widget/{$key}/messages", ['session_id' => 'abcdefgh1234', 'message' => 'hola'])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_page_props_never_carry_credentials(): void
    {
        $workspace = $this->workspace();
        $this->actAs($workspace);
        $this->webIntegration($workspace);
        $this->whatsApp($workspace);
        $bot = $this->bot($workspace);
        $this->activate($bot, 'web');

        $content = $this->get("/chatbots/{$bot->id}")->assertInertia(fn (AssertableInertia $page) => $page->component('Chatbots/Show')->has('appearance.channels'))->getContent();

        foreach (['shared-SECRET-123', 'EAAB-private-token-XYZ'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_the_script_draws_the_preview_with_the_same_code_and_never_builds_html_from_the_network(): void
    {
        $script = file_get_contents(public_path('widget/ava-widget.js'));

        $this->assertStringContainsString('AvaWidget', $script);
        $this->assertStringContainsString('preview', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
        $this->assertStringNotContainsString('eval(', $script);
        $this->assertStringContainsString("indexOf('https://wa.me/') === 0", $script);
    }
}
