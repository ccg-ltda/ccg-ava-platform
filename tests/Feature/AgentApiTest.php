<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The automation (n8n) reads a chatbot's configuration from Ava with that chatbot's token, and nothing else. */
class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => (Organization::first() ?? Organization::create(['name' => 'Test Org']))->id, 'code' => $code, 'name' => "Workspace {$code}"]);
    }

    private function bot(Workspace $workspace, string $name, string $instructions): Chatbot
    {
        return $workspace->chatbots()->create(['name' => $name, 'instructions' => $instructions]);
    }

    private function whatsApp(Workspace $workspace, string $phoneId, string $token)
    {
        return $workspace->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => $phoneId], 'secrets' => ['access_token' => $token],
        ]);
    }

    private function tokenFor(Chatbot $chatbot): string
    {
        return app(AgentAccess::class)->generate($chatbot);
    }

    // --- generating the token --------------------------------------------------------------------------------

    public function test_only_who_manages_chatbots_generates_or_revokes_the_token(): void
    {
        $this->actAs('admin');
        $bot = $this->bot(Workspace::first(), 'Ventas', 'Sé breve');

        $this->actAs('supervisor');
        $this->post("/chatbots/{$bot->id}/agent-token")->assertForbidden();
        $this->delete("/chatbots/{$bot->id}/agent-token")->assertForbidden();
        $this->assertNull($bot->fresh()->agent_token_hash);

        $this->actAs('cliente');
        $this->post("/chatbots/{$bot->id}/agent-token")->assertForbidden();
    }

    public function test_the_plain_token_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $this->actAs();
        $bot = $this->bot(Workspace::first(), 'Ventas', 'Sé breve');

        $this->post("/chatbots/{$bot->id}/agent-token")->assertSessionHas('agent_token')->assertSessionHas('success');
        $plain = session('agent_token');
        $this->assertStringStartsWith('ava_', $plain);

        // The next page carries the plain token once...
        $this->get("/chatbots/{$bot->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('agentToken.token', $plain)->where('agentToken.configured', true)->where('agentToken.hint', substr($plain, -4))
            ->where('agentToken.endpoint', url('/api/agent/config')));

        // ...and never again.
        $this->get("/chatbots/{$bot->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('agentToken.token', null)->where('agentToken.configured', true));
        $this->assertStringNotContainsString($plain, $this->get("/chatbots/{$bot->id}")->getContent());

        $row = DB::table('chatbots')->where('id', $bot->id)->first();
        $this->assertSame(hash('sha256', $plain), $row->agent_token_hash);
        $this->assertStringNotContainsString($plain, json_encode($row));
        $this->assertArrayNotHasKey('agent_token_hash', $bot->fresh()->toArray());
    }

    public function test_a_new_token_replaces_the_old_one_and_revoking_removes_access(): void
    {
        $this->actAs();
        $bot = $this->bot(Workspace::first(), 'Ventas', 'Sé breve');
        $old = $this->tokenFor($bot);

        $this->post("/chatbots/{$bot->id}/agent-token");
        $new = session('agent_token');

        $this->withToken($old)->getJson('/api/agent/config')->assertUnauthorized();
        $this->withToken($new)->getJson('/api/agent/config')->assertOk();

        $this->delete("/chatbots/{$bot->id}/agent-token")->assertSessionHas('success');
        $this->withToken($new)->getJson('/api/agent/config')->assertUnauthorized();
        $this->get("/chatbots/{$bot->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('agentToken.configured', false)->where('agentToken.hint', null));
    }

    public function test_the_token_of_another_workspaces_chatbot_cannot_be_generated_or_revoked(): void
    {
        $this->actAs();
        $foreign = $this->bot($this->workspace('OTHER_WS'), 'Ajeno', 'Secreto de B');
        $token = $this->tokenFor($foreign);

        $this->post("/chatbots/{$foreign->id}/agent-token")->assertNotFound();
        $this->delete("/chatbots/{$foreign->id}/agent-token")->assertNotFound();
        $this->withToken($token)->getJson('/api/agent/config')->assertOk();
    }

    public function test_generating_and_revoking_are_audited_without_the_token(): void
    {
        $this->actAs();
        $bot = $this->bot(Workspace::first(), 'Ventas', 'Sé breve');

        $this->post("/chatbots/{$bot->id}/agent-token");
        $plain = session('agent_token');
        $this->delete("/chatbots/{$bot->id}/agent-token");

        $logs = AuditLog::where('resource_type', 'chatbot')->where('action', 'updated')->get();
        $fields = $logs->flatMap(fn ($log) => collect($log->changes)->pluck('field'))->all();
        $this->assertSame(2, count(array_keys($fields, 'Token de acceso del agente', true)));
        $this->assertStringNotContainsString($plain, json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString(hash('sha256', $plain), json_encode(AuditLog::all()->toArray()));
    }

    // --- reading the configuration ---------------------------------------------------------------------------

    public function test_the_configuration_needs_a_valid_token(): void
    {
        $bot = $this->bot($this->workspace('WS_A'), 'Ventas', 'Sé breve');
        $this->tokenFor($bot);

        $this->getJson('/api/agent/config')->assertUnauthorized();
        $this->withToken('ava_'.str_repeat('x', 40))->getJson('/api/agent/config')->assertUnauthorized();
        $this->withToken('not-a-token')->getJson('/api/agent/config')->assertUnauthorized();
        $this->withToken(hash('sha256', 'anything'))->getJson('/api/agent/config')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Basic abc'])->getJson('/api/agent/config')->assertUnauthorized();
    }

    public function test_the_configuration_is_the_instructions_saved_in_ava(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $this->bot($workspace, 'Agente de ventas', '');
        $bot->update(['description' => 'Atiende ventas']);

        $this->post("/chatbots/{$bot->id}", ['name' => 'Agente de ventas', 'description' => 'Atiende ventas', 'instructions' => "Eres un asistente comercial.\nNunca inventes precios."])->assertSessionHasNoErrors();
        $token = $this->tokenFor($bot->fresh());

        $this->withToken($token)->getJson('/api/agent/config')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'chatbot' => [
                    'id' => $bot->id,
                    'name' => 'Agente de ventas',
                    'description' => 'Atiende ventas',
                    'instructions' => "Eres un asistente comercial.\nNunca inventes precios.",
                    'updated_at' => $bot->fresh()->updated_at->toIso8601String(),
                ],
                'workspace' => ['code' => $workspace->code, 'name' => $workspace->name],
                'channels' => [],
            ]);

        // Editing in Ava changes what the automation reads: there is no second copy.
        $this->post("/chatbots/{$bot->id}", ['name' => 'Agente de ventas', 'instructions' => 'Responde en inglés.'])->assertSessionHasNoErrors();
        $this->withToken($token)->getJson('/api/agent/config')->assertJsonPath('chatbot.instructions', 'Responde en inglés.')->assertJsonPath('chatbot.description', null);
    }

    public function test_a_chatbot_without_instructions_reads_as_an_empty_text(): void
    {
        $bot = $this->workspace('WS_A')->chatbots()->create(['name' => 'Vacío']);

        $this->withToken($this->tokenFor($bot))->getJson('/api/agent/config')->assertOk()->assertJsonPath('chatbot.instructions', '');
    }

    public function test_only_active_channels_appear_with_non_secret_identifiers(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $this->bot($workspace, 'Ventas', 'Sé breve');
        $this->whatsApp($workspace, '100200300', 'EAAB-TOP-SECRET');
        $token = $this->tokenFor($bot);

        $this->withToken($token)->getJson('/api/agent/config')->assertJsonPath('channels', []);

        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => true]);
        $response = $this->withToken($token)->getJson('/api/agent/config')->assertOk()->assertJsonPath('channels.whatsapp.phone_number_id', '100200300');
        $this->assertStringNotContainsString('EAAB-TOP-SECRET', $response->getContent());
        $this->assertStringNotContainsString('access_token', $response->getContent());

        $this->put("/chatbots/{$bot->id}/channels/whatsapp", ['is_active' => false]);
        $this->withToken($token)->getJson('/api/agent/config')->assertJsonPath('channels', []);
    }

    public function test_a_disabled_chatbot_workspace_or_organization_is_refused(): void
    {
        $workspace = $this->workspace('WS_A');
        $bot = $this->bot($workspace, 'Ventas', 'Sé breve');
        $token = $this->tokenFor($bot);

        $bot->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/agent/config')->assertForbidden();
        $bot->update(['is_active' => true]);

        $workspace->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/agent/config')->assertForbidden();
        $workspace->update(['is_active' => true]);

        $workspace->organization->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/agent/config')->assertForbidden();
        $workspace->organization->update(['is_active' => true]);

        $this->withToken($token)->getJson('/api/agent/config')->assertOk();
    }

    // --- isolation: n8n A never reaches B --------------------------------------------------------------------

    public function test_each_token_reads_only_its_own_chatbot_whatever_the_request_asks_for(): void
    {
        $a = $this->workspace('WS_A');
        $b = $this->workspace('WS_B');
        $botA = $this->bot($a, 'Bot A', 'Instrucciones de A');
        $botB = $this->bot($b, 'Bot B', 'Instrucciones SECRETAS de B');
        $this->whatsApp($a, '111111111', 'TOKEN-A');
        $this->whatsApp($b, '222222222', 'TOKEN-B');
        $tokenA = $this->tokenFor($botA);
        $tokenB = $this->tokenFor($botB);
        $botA->channels()->create(['workspace_id' => $a->id, 'channel' => 'whatsapp', 'integration_id' => $a->integrations()->first()->id, 'is_active' => true]);
        $botB->channels()->create(['workspace_id' => $b->id, 'channel' => 'whatsapp', 'integration_id' => $b->integrations()->first()->id, 'is_active' => true]);

        // The caller cannot steer the lookup: query, headers and body naming B are ignored.
        $attempts = [
            "/api/agent/config?chatbot={$botB->id}&chatbot_id={$botB->id}&workspace=WS_B&workspace_id={$b->id}",
            '/api/agent/config',
        ];

        foreach ($attempts as $url) {
            $response = $this->withToken($tokenA)->withHeaders(['X-Workspace' => 'WS_B', 'X-Chatbot-Id' => (string) $botB->id])->getJson($url)->assertOk();
            $response->assertJsonPath('chatbot.id', $botA->id)->assertJsonPath('workspace.code', 'WS_A')
                ->assertJsonPath('chatbot.instructions', 'Instrucciones de A')->assertJsonPath('channels.whatsapp.phone_number_id', '111111111');

            foreach (['SECRETAS', 'WS_B', '222222222', 'TOKEN-B', 'TOKEN-A', 'Bot B'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent());
            }
        }

        $this->withToken($tokenB)->getJson('/api/agent/config')->assertJsonPath('chatbot.id', $botB->id)->assertJsonPath('channels.whatsapp.phone_number_id', '222222222');
    }

    public function test_the_other_endpoints_of_ava_do_not_accept_an_agent_token(): void
    {
        $bot = $this->bot($this->workspace('WS_A'), 'Ventas', 'Sé breve');
        $token = $this->tokenFor($bot);

        $this->withToken($token)->getJson("/chatbots/{$bot->id}")->assertUnauthorized();
        $this->withToken($token)->getJson('/integrations')->assertUnauthorized();
    }

    // --- n8n as an integration of the Workspace ---------------------------------------------------------------

    public function test_the_integrations_page_reports_n8n_only_as_far_as_it_was_really_seen(): void
    {
        $this->actAs();
        $workspace = Workspace::first();
        $bot = $this->bot($workspace, 'Ventas', 'Sé breve');
        $this->bot($workspace, 'Soporte', 'Ayuda');
        $automation = fn () => $this->get('/integrations')->viewData('page')['props']['automation'];

        $this->assertSame('not_configured', $automation()['state']);
        $this->assertNull($automation()['lastSeen']);
        $this->assertSame(url('/api/agent/config'), $automation()['endpoint']);
        $this->assertSame(url('/api/agent/messages'), $automation()['messagesEndpoint']);

        $token = $this->tokenFor($bot);
        $this->assertSame('configured', $automation()['state'], 'a token exists but n8n never read anything');
        $this->assertNull($automation()['lastSeen']);

        $this->withToken($token)->getJson('/api/agent/config')->assertOk();
        $found = $automation();
        $this->assertSame('connected', $found['state']);
        $this->assertNotNull($found['lastSeen']);
        $this->assertSame(['Soporte', 'Ventas'], array_column(collect($found['chatbots'])->sortBy('name')->values()->all(), 'name'));
        $ventas = collect($found['chatbots'])->firstWhere('name', 'Ventas');
        $this->assertSame([true, substr($token, -4)], [$ventas['hasToken'], $ventas['hint']]);
        $this->assertNotNull($ventas['lastSeen']);
        $this->assertFalse(collect($found['chatbots'])->firstWhere('name', 'Soporte')['hasToken']);
        $this->assertStringNotContainsString($token, $this->get('/integrations')->getContent());
    }

    public function test_the_n8n_card_lists_only_the_chatbots_of_the_workspace_being_shown(): void
    {
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $dev = $this->workspace('DESARROLLO_DEV');
        $client = $this->workspace('EMPRESA_ABC');
        $this->bot($dev, 'Interno', 'x');
        $abc = $this->bot($client, 'De ABC', 'x');
        $token = $this->tokenFor($abc);
        $this->actAs('admin');
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        $dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        $names = fn (string $query = '') => array_column($this->get('/integrations'.$query)->viewData('page')['props']['automation']['chatbots'], 'name');

        $this->assertSame(['Interno'], $names());
        $this->assertSame(['De ABC'], $names("?workspace={$client->id}"));
        $this->assertStringNotContainsString($token, $this->get("/integrations?workspace={$client->id}")->getContent());
    }

    public function test_a_normal_user_does_not_get_another_workspaces_n8n_state(): void
    {
        $this->actAs();
        $other = $this->workspace('OTHER_WS');
        $this->tokenFor($this->bot($other, 'Ajeno', 'x'));

        $this->get("/integrations?workspace={$other->id}")->assertForbidden();
        $this->assertSame([], $this->get('/integrations')->viewData('page')['props']['automation']['chatbots']);
    }
}
