<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Chatbots: owned by one Workspace, channels linked to that Workspace's own integrations, never across Workspaces. */
class ChatbotsTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function otherWorkspace(string $code = 'OTHER_WS'): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function whatsApp(Workspace $workspace, bool $active = true, string $token = 'EAAB-secret-token'): Integration
    {
        return $workspace->integrations()->create([
            'name' => 'WhatsApp',
            'type' => 'whatsapp',
            'is_active' => $active,
            'config' => ['phone_number_id' => '100200300'],
            'secrets' => ['access_token' => $token],
        ]);
    }

    private function bot(Workspace $workspace, string $name = 'Asistente'): Chatbot
    {
        return $workspace->chatbots()->create(['name' => $name]);
    }

    private function assertForeignKeyRefuses(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());

            return;
        }

        $this->fail('The database accepted a link across Workspaces.');
    }

    // --- permissions -----------------------------------------------------------------------------------------

    public function test_viewing_needs_view_chatbots_and_changing_needs_manage_chatbots(): void
    {
        $workspace = $this->otherWorkspace('FIRST_WS'); // becomes the active one
        $bot = $this->bot($workspace);

        $this->actAs('cliente'); // view-dashboard only
        $this->get('/chatbots')->assertForbidden();
        $this->get("/chatbots/{$bot->id}")->assertForbidden();

        $this->actAs('supervisor'); // view-chatbots, no manage
        $this->get('/chatbots')->assertOk();
        $this->get("/chatbots/{$bot->id}")->assertOk();
        $this->post('/chatbots', ['name' => 'Nuevo'])->assertForbidden();
        $this->post("/chatbots/{$bot->id}", ['name' => 'X'])->assertForbidden();
        $this->post("/chatbots/{$bot->id}/deactivate")->assertForbidden();
        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true])->assertForbidden();
        $this->assertSame('Asistente', $bot->fresh()->name);

        $this->actAs('admin');
        $this->post('/chatbots', ['name' => 'Nuevo'])->assertRedirect();
    }

    public function test_the_menu_item_follows_the_view_permission(): void
    {
        $this->actAs('supervisor');
        $this->assertContains('view-chatbots', Role::findByName('supervisor', 'web')->permissions->pluck('name')->all());
        $this->assertNotContains('manage-chatbots', Role::findByName('supervisor', 'web')->permissions->pluck('name')->all());
        $this->get('/chatbots')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.user.permissions', fn ($permissions) => in_array('view-chatbots', $permissions->all(), true)));
    }

    // --- create / edit / activate ----------------------------------------------------------------------------

    public function test_a_chatbot_is_created_inside_the_active_workspace_and_the_client_cannot_choose_another(): void
    {
        $this->actAs();
        $active = Workspace::first();
        $other = $this->otherWorkspace();

        $this->post('/chatbots', ['name' => '  Ventas  ', 'description' => 'Atiende ventas', 'workspace_id' => $other->id])->assertSessionHasNoErrors();

        $bot = $active->chatbots()->firstOrFail();
        $this->assertSame(['Ventas', 'Atiende ventas', true], [$bot->name, $bot->description, $bot->is_active]);
        $this->assertSame(0, $other->chatbots()->count());
    }

    public function test_names_are_required_and_unique_per_workspace_only(): void
    {
        $this->actAs();
        $this->post('/chatbots', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/chatbots', ['name' => 'Ventas'])->assertSessionHasNoErrors();
        $this->post('/chatbots', ['name' => 'Ventas'])->assertSessionHasErrors('name');

        $this->otherWorkspace()->chatbots()->create(['name' => 'Soporte']);
        $this->post('/chatbots', ['name' => 'Soporte'])->assertSessionHasNoErrors();
    }

    public function test_the_general_tab_is_edited_and_validated(): void
    {
        $this->actAs();
        $bot = $this->bot(Workspace::first());

        $this->post("/chatbots/{$bot->id}", ['name' => 'Soporte', 'description' => 'Ayuda', 'instructions' => 'Responde con amabilidad.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $bot->refresh();
        $this->assertSame(['Soporte', 'Ayuda', 'Responde con amabilidad.'], [$bot->name, $bot->description, $bot->instructions]);

        $this->post("/chatbots/{$bot->id}", ['name' => 'x', 'instructions' => str_repeat('a', config('chatbots.instructions_max') + 1)])->assertSessionHasErrors('instructions');
    }

    public function test_the_avatar_is_stored_privately_served_to_members_and_replaceable(): void
    {
        Storage::fake();
        $this->actAs();
        $bot = $this->bot(Workspace::first());

        $this->post("/chatbots/{$bot->id}", ['name' => 'Asistente', 'avatar' => UploadedFile::fake()->image('a.png', 64, 64)])->assertSessionHasNoErrors();
        $first = $bot->fresh()->avatar_path;
        $this->assertStringStartsWith('workspaces/'.Workspace::first()->id."/chatbots/{$bot->id}/avatar/", $first);
        Storage::assertExists($first);
        $this->get("/chatbots/{$bot->id}/avatar")->assertOk()->assertHeader('Cache-Control', 'no-cache, private');

        $this->post("/chatbots/{$bot->id}", ['name' => 'Asistente', 'avatar' => UploadedFile::fake()->image('b.jpg', 64, 64)])->assertSessionHasNoErrors();
        Storage::assertMissing($first);
        Storage::assertExists($bot->fresh()->avatar_path);

        $this->post("/chatbots/{$bot->id}", ['name' => 'Asistente', 'remove_avatar' => true])->assertSessionHasNoErrors();
        $this->assertNull($bot->fresh()->avatar_path);
        $this->get("/chatbots/{$bot->id}/avatar")->assertNotFound();
    }

    public function test_the_avatar_refuses_svg_and_big_files(): void
    {
        Storage::fake();
        $this->actAs();
        $bot = $this->bot(Workspace::first());

        $this->post("/chatbots/{$bot->id}", ['name' => 'A', 'avatar' => UploadedFile::fake()->create('a.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('avatar');
        $this->post("/chatbots/{$bot->id}", ['name' => 'A', 'avatar' => UploadedFile::fake()->image('big.png')->size(2048)])->assertSessionHasErrors('avatar');
        $this->assertNull($bot->fresh()->avatar_path);
    }

    public function test_a_chatbot_is_deactivated_and_reactivated_never_deleted(): void
    {
        $this->actAs();
        $bot = $this->bot(Workspace::first());

        $this->post("/chatbots/{$bot->id}/deactivate")->assertSessionHas('success', 'Chatbot desactivado correctamente');
        $this->assertFalse($bot->fresh()->is_active);
        $this->post("/chatbots/{$bot->id}/activate")->assertSessionHas('success', 'Chatbot activado correctamente');
        $this->assertTrue($bot->fresh()->is_active);
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($route) => in_array('DELETE', $route->methods(), true) && $route->uri() === 'chatbots/{chatbot}'));
    }

    // --- General (presentation) and IA y comportamiento (edition) --------------------------------------------

    public function test_general_asks_for_the_identity_until_it_is_saved_once(): void
    {
        $this->actAs();
        $this->post('/chatbots', ['name' => 'Ventas', 'description' => 'Atiende ventas'])->assertRedirect();
        $bot = Workspace::first()->chatbots()->firstOrFail();
        $profile = fn () => $this->get("/chatbots/{$bot->id}")->viewData('page')['props']['chatbot'];

        // Creating it (name and description) is not saving its identity yet.
        $this->assertFalse($profile()['profileSaved']);
        $this->assertNull($bot->fresh()->profile_saved_at);

        $this->post("/chatbots/{$bot->id}", ['name' => 'Ventas', 'description' => 'Atiende ventas'])->assertSessionHasNoErrors();
        $this->assertTrue($profile()['profileSaved']);
        $savedAt = $bot->fresh()->profile_saved_at;
        $this->assertNotNull($savedAt);

        // Later edits keep it saved and do not move the moment of the first save.
        $this->travel(2)->hours();
        $this->post("/chatbots/{$bot->id}", ['name' => 'Ventas 2', 'instructions' => 'Sé breve'])->assertSessionHasNoErrors();
        $this->assertTrue($profile()['profileSaved']);
        $this->assertTrue($savedAt->equalTo($bot->fresh()->profile_saved_at));
    }

    public function test_instructions_are_persisted_edited_and_returned_to_the_page(): void
    {
        $this->actAs();
        $bot = $this->bot(Workspace::first());
        $props = fn () => $this->get("/chatbots/{$bot->id}")->viewData('page')['props']['chatbot'];

        $this->assertSame('', $props()['instructions']);

        $this->post("/chatbots/{$bot->id}", ['name' => 'Asistente', 'instructions' => "Eres un asistente comercial.\nNunca inventes precios."])->assertSessionHasNoErrors();
        $this->assertSame("Eres un asistente comercial.\nNunca inventes precios.", $props()['instructions']);
        $this->assertSame("Eres un asistente comercial.\nNunca inventes precios.", $bot->fresh()->instructions);

        $this->post("/chatbots/{$bot->id}", ['name' => 'Asistente', 'instructions' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', $props()['instructions']);
        $this->assertNull($bot->fresh()->instructions);
    }

    public function test_a_viewer_who_cannot_manage_never_gets_the_editing_permission_flag(): void
    {
        $workspace = $this->otherWorkspace('FIRST_WS');
        $bot = $this->bot($workspace);
        $this->actAs('supervisor');

        $this->get("/chatbots/{$bot->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('scope.canManage', false)->where('scope.readOnly', false));
        $this->post("/chatbots/{$bot->id}", ['name' => 'Cambiado', 'instructions' => 'x'])->assertForbidden();
        $this->assertSame('Asistente', $bot->fresh()->name);
    }

    // --- isolation between Workspaces ------------------------------------------------------------------------

    public function test_the_list_only_has_the_active_workspaces_chatbots(): void
    {
        $this->actAs();
        $this->bot(Workspace::first(), 'Mío');
        $this->bot($this->otherWorkspace(), 'Ajeno');

        $this->get('/chatbots')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Chatbots/Index')->has('chatbots', 1)->where('chatbots.0.name', 'Mío')
            ->where('scope.readOnly', false)->where('scope.canChoose', false)->where('scope.canManage', true));
    }

    public function test_another_workspaces_chatbot_does_not_exist_from_here(): void
    {
        $this->actAs();
        $foreign = $this->bot($this->otherWorkspace(), 'Ajeno');

        $this->get("/chatbots/{$foreign->id}")->assertNotFound();
        $this->get("/chatbots/{$foreign->id}/avatar")->assertNotFound();
        $this->post("/chatbots/{$foreign->id}", ['name' => 'Hackeado'])->assertNotFound();
        $this->post("/chatbots/{$foreign->id}/deactivate")->assertNotFound();
        $this->put("/chatbots/{$foreign->id}/channels/whatsapp", ['is_active' => true])->assertNotFound();

        $foreign->refresh();
        $this->assertSame(['Ajeno', true], [$foreign->name, $foreign->is_active]);
    }

    public function test_a_normal_user_cannot_look_at_another_workspace_with_the_parameter(): void
    {
        $this->actAs('admin');
        $other = $this->otherWorkspace();
        $foreign = $this->bot($other, 'Ajeno');

        $this->get("/chatbots?workspace={$other->id}")->assertForbidden();
        $this->get("/chatbots/{$foreign->id}?workspace={$other->id}")->assertForbidden();
        $this->get("/chatbots/{$foreign->id}/avatar?workspace={$other->id}")->assertForbidden();
        // Even a superuser inside a client Workspace is limited to it.
        $this->actAs('admin', superuser: true);
        $this->get("/chatbots?workspace={$other->id}")->assertForbidden();
    }

    // --- administrator inside Desarrollo_DEV -----------------------------------------------------------------

    private function actAsDevAdmin(): Workspace
    {
        config(['workspace.admin_code' => 'desarrollo_dev']);
        $dev = $this->otherWorkspace('DESARROLLO_DEV');
        $this->actAs('admin', superuser: true);

        return $dev;
    }

    public function test_the_administrator_in_the_administrative_workspace_may_look_at_one_other_workspace_read_only(): void
    {
        $dev = $this->actAsDevAdmin();
        $client = $this->otherWorkspace('EMPRESA_ABC');
        $this->bot($dev, 'Interno');
        $foreign = $this->bot($client, 'De ABC');
        $this->whatsApp($client);

        $this->get('/chatbots')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('chatbots', 1)->where('chatbots.0.name', 'Interno')->where('scope.canChoose', true)->where('scope.readOnly', false));

        $this->get("/chatbots?workspace={$client->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('chatbots', 1)->where('chatbots.0.name', 'De ABC')->where('scope.workspace.code', 'EMPRESA_ABC')
            ->where('scope.readOnly', true)->where('scope.canManage', false));

        $this->get("/chatbots/{$foreign->id}?workspace={$client->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Chatbots/Show')->where('chatbot.name', 'De ABC')->where('scope.readOnly', true)->where('scope.canManage', false));

        // Looking is not changing: every change applies to the active Workspace, where that id does not exist.
        $this->post("/chatbots/{$foreign->id}", ['name' => 'Cambiado'])->assertNotFound();
        $this->put("/chatbots/{$foreign->id}/channels/whatsapp", ['is_active' => true])->assertNotFound();
        $this->assertSame('De ABC', $foreign->fresh()->name);
    }

    public function test_there_is_no_view_of_every_workspace_and_unknown_workspaces_are_404(): void
    {
        $this->actAsDevAdmin();

        $this->get('/chatbots?workspace=all')->assertNotFound();
        $this->get('/chatbots?workspace=999999')->assertNotFound();
        $this->get('/chatbots?workspace=abc')->assertNotFound();
        $this->get('/integrations?workspace=all')->assertNotFound();
    }

    // --- channels --------------------------------------------------------------------------------------------

    private function channel(array $channels, string $key): array
    {
        return collect($channels)->firstWhere('key', $key);
    }

    public function test_the_channel_states_say_what_can_really_be_done(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $this->bot($workspace);

        $states = fn () => $this->get("/chatbots/{$bot->id}")->viewData('page')['props']['channels'];

        $this->assertSame(['web', 'whatsapp', 'instagram', 'messenger'], array_column($states(), 'key'));
        $this->assertSame('not_configured', $this->channel($states(), 'web')['state']);
        $this->assertSame('unavailable', $this->channel($states(), 'instagram')['state']);
        $this->assertSame('not_configured', $this->channel($states(), 'whatsapp')['state']);

        $integration = $this->whatsApp($workspace, active: false);
        $this->assertSame('integration_inactive', $this->channel($states(), 'whatsapp')['state']);

        $integration->update(['is_active' => true]);
        $this->assertSame('inactive', $this->channel($states(), 'whatsapp')['state']);

        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true])->assertSessionHas('success');
        $this->assertSame('active', $this->channel($states(), 'whatsapp')['state']);

        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => false])->assertSessionHas('success');
        $this->assertSame('inactive', $this->channel($states(), 'whatsapp')['state']);
    }

    public function test_a_channel_links_the_chatbot_to_the_integration_of_its_own_workspace(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $mine = $this->whatsApp($workspace);
        $theirs = $this->whatsApp($this->otherWorkspace(), token: 'other-token');
        $bot = $this->bot($workspace);

        // The client sends no integration id; even if it does, it is ignored.
        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true, 'integration_id' => $theirs->id, 'workspace_id' => $theirs->workspace_id])->assertSessionHas('success');

        $link = $bot->channels()->firstOrFail();
        $this->assertSame([$mine->id, $workspace->id, true, 'whatsapp'], [$link->integration_id, $link->workspace_id, $link->is_active, $link->channel]);
    }

    public function test_a_channel_cannot_be_activated_without_a_ready_integration_or_when_unavailable(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $this->bot($workspace);
        $this->whatsApp($this->otherWorkspace()); // another Workspace's integration does not count

        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true])->assertSessionHas('error');
        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('error');
        $this->put("/chatbots/{$bot->id}/channels/instagram", ['is_active' => true])->assertSessionHas('error');
        $this->put("/chatbots/{$bot->id}/channels/nope", ['is_active' => true])->assertNotFound();
        $this->put("/chatbots/{$bot->id}/channels/whatsapp", [])->assertSessionHasErrors('is_active');
        $this->assertSame(0, $bot->channels()->count());
    }

    public function test_one_account_answers_through_one_chatbot_only(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->whatsApp($workspace);
        $one = $this->bot($workspace, 'Uno');
        $two = $this->bot($workspace, 'Dos');

        $this->put("/chatbots/{$one->id}/channels/whatsapp", ['is_active' => true])->assertSessionHas('success');
        $this->put("/chatbots/{$two->id}/channels/whatsapp", ['is_active' => true])->assertSessionHas('error');
        $this->assertSame('in_use', $this->channel($this->get("/chatbots/{$two->id}")->viewData('page')['props']['channels'], 'whatsapp')['state']);

        $this->put("/chatbots/{$one->id}/channels/whatsapp", ['is_active' => false]);
        $this->put("/chatbots/{$two->id}/channels/whatsapp", ['is_active' => true])->assertSessionHas('success');
    }

    public function test_the_database_refuses_a_chatbot_linked_to_another_workspaces_integration(): void
    {
        $workspace = $this->otherWorkspace('WS_ONE');
        $other = $this->otherWorkspace('WS_TWO');
        $foreignIntegration = $this->whatsApp($other);
        $bot = $this->bot($workspace);

        $this->assertForeignKeyRefuses(fn () => $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $foreignIntegration->id, 'is_active' => true]));

        // The same link inside the chatbot's own Workspace is accepted: the refusal above is the Workspace rule, not a typo.
        $own = $this->whatsApp($workspace);
        $this->assertNotNull($bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'integration_id' => $own->id, 'is_active' => true])->id);
    }

    public function test_the_database_refuses_a_channel_of_a_chatbot_of_another_workspace(): void
    {
        $workspace = $this->otherWorkspace('WS_ONE');
        $other = $this->otherWorkspace('WS_TWO');
        $bot = $this->bot($other);

        $this->assertForeignKeyRefuses(fn () => $bot->channels()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'is_active' => false]));
    }

    public function test_the_show_page_never_carries_a_credential(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->whatsApp($workspace, token: 'EAAB-TOP-SECRET');
        $bot = $this->bot($workspace);
        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true]);

        $body = $this->get("/chatbots/{$bot->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('EAAB-TOP-SECRET', $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('100200300', $body);
    }

    // --- audit -----------------------------------------------------------------------------------------------

    public function test_every_change_is_audited_in_the_workspace_of_the_chatbot(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $this->whatsApp($workspace);
        $this->post('/chatbots', ['name' => 'Ventas']);
        $bot = $workspace->chatbots()->firstOrFail();
        $this->post("/chatbots/{$bot->id}", ['name' => 'Ventas', 'instructions' => 'Sé breve']);
        $this->post("/chatbots/{$bot->id}/deactivate");
        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true]);

        $this->assertSame(3, AuditLog::where('resource_type', 'chatbot')->where('workspace_name', $workspace->name)->count());
        $channel = AuditLog::where('resource_type', 'chatbot_channel')->firstOrFail();
        $this->assertSame('WhatsApp · Ventas', $channel->resource_label);
        $this->assertStringNotContainsString('EAAB', json_encode(AuditLog::all()->toArray()));
    }
}
