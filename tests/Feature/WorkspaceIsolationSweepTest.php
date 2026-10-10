<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Organization;
use App\Models\SavedFilter;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cross-module sweep: a member of one Workspace (the "attacker", B) tries every route that takes an identifier or a
 * Workspace parameter against the data of another Workspace (the "victim", A). Each module has its own isolation tests;
 * this one walks the whole route table so a new route cannot quietly skip the rule.
 */
class WorkspaceIsolationSweepTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'EAAB-VICTIM-SECRET';

    private Workspace $a;

    private Workspace $b;

    private User $victim;

    private User $attacker;

    private Chatbot $bot;

    private Integration $integration;

    private Conversation $conversation;

    private Message $message;

    private SavedFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoleCatalog();

        $org = Organization::create(['name' => 'Org']);
        $this->a = Workspace::create(['organization_id' => $org->id, 'code' => 'VICTIM_A', 'name' => 'Victim A']);
        $this->b = Workspace::create(['organization_id' => $org->id, 'code' => 'ATTACKER_B', 'name' => 'Attacker B']);

        $this->victim = User::factory()->create(['name' => 'Victim Person', 'email' => 'victim@a.test']);
        $this->a->users()->attach($this->victim->id, ['role' => 'admin']);
        $this->attacker = User::factory()->create(['name' => 'Attacker Person']);
        $this->b->users()->attach($this->attacker->id, ['role' => 'admin']);

        $this->bot = $this->a->chatbots()->create(['name' => 'Bot A']);
        $this->integration = $this->a->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => '999'], 'secrets' => ['access_token' => self::SECRET],
        ]);
        $this->bot->channels()->create(['workspace_id' => $this->a->id, 'channel' => 'whatsapp', 'integration_id' => $this->integration->id, 'is_active' => true]);
        $this->conversation = $this->bot->conversations()->create(['workspace_id' => $this->a->id, 'channel' => 'whatsapp', 'contact_id' => '573000000001', 'contact_name' => 'Contacto Privado', 'last_message_at' => now()]);
        $this->message = $this->conversation->messages()->create(['workspace_id' => $this->a->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => 'Mensaje privado de A', 'sent_at' => now()]);
        $this->filter = SavedFilter::create(['workspace_id' => $this->a->id, 'user_id' => $this->victim->id, 'scope' => 'audit', 'name' => 'Filtro A', 'criteria' => ['search' => 'x']]);
        AuditLog::create([
            'workspace_id' => $this->a->id, 'workspace_code' => 'VICTIM_A', 'workspace_name' => 'Victim A', 'user_id' => $this->victim->id, 'user_name' => 'Victim Person',
            'user_email' => 'victim@a.test', 'actor' => 'user', 'outcome' => 'success', 'action' => 'created', 'resource_type' => 'chatbot', 'resource_id' => $this->bot->id,
            'resource_label' => 'Evento secreto de A', 'description' => 'Evento secreto de A', 'changes' => [],
        ]);
    }

    private function asAttacker(bool $superuser = false)
    {
        if ($superuser) {
            $this->attacker->forceFill(['is_superuser' => true])->save();
        }

        return $this->actingAs($this->attacker)->withSession(['workspace_id' => $this->b->id]);
    }

    /** Every page that accepts `?workspace=` for a user who may not choose a scope. */
    public static function scopedPages(): array
    {
        return [['/dashboard'], ['/reports'], ['/audit'], ['/audit/export'], ['/chatbots'], ['/integrations'], ['/conversations']];
    }

    /** @dataProvider scopedPages */
    #[DataProvider('scopedPages')]
    public function test_a_client_admin_cannot_aim_a_page_at_another_workspace(string $path): void
    {
        $this->asAttacker()->get($path.'?workspace='.$this->a->id)->assertForbidden();
        $this->asAttacker()->get($path.'?workspace=all')->assertForbidden();
    }

    /** @dataProvider scopedPages */
    #[DataProvider('scopedPages')]
    public function test_a_superuser_inside_a_client_workspace_stays_limited_to_it(string $path): void
    {
        $this->asAttacker(superuser: true)->get($path.'?workspace='.$this->a->id)->assertForbidden();
        $this->asAttacker(superuser: true)->get($path.'?workspace=all')->assertForbidden();
    }

    public function test_the_selector_search_is_closed_to_clients(): void
    {
        $this->asAttacker()->getJson('/workspaces/search?purpose=view&q=Victim')->assertForbidden();

        // "assign" only offers Workspaces the user administers: B, never A.
        $codes = collect($this->asAttacker()->getJson('/workspaces/search?purpose=assign&q=')->assertOk()->json('data'))->pluck('code');
        $this->assertSame(['ATTACKER_B'], $codes->all());

        // A superuser inside a client Workspace cannot browse the others to look at them either.
        $this->asAttacker(superuser: true)->getJson('/workspaces/search?purpose=view&q=Victim')->assertForbidden();
    }

    public function test_every_chatbot_route_treats_a_foreign_id_as_missing(): void
    {
        $id = $this->bot->id;

        $this->asAttacker()->get("/chatbots/{$id}")->assertNotFound();
        $this->asAttacker()->get("/chatbots/{$id}/avatar")->assertNotFound();
        $this->asAttacker()->get("/chatbots/{$id}/channels/whatsapp/conversations")->assertNotFound();
        $this->asAttacker()->post("/chatbots/{$id}", ['name' => 'Hacked'])->assertNotFound();
        $this->asAttacker()->post("/chatbots/{$id}/activate")->assertNotFound();
        $this->asAttacker()->post("/chatbots/{$id}/deactivate")->assertNotFound();
        $this->asAttacker()->post("/chatbots/{$id}/agent-token")->assertNotFound();
        $this->asAttacker()->delete("/chatbots/{$id}/agent-token")->assertNotFound();
        $this->asAttacker()->put("/chatbots/{$id}/channels/whatsapp", ['is_active' => false])->assertNotFound();
        $this->asAttacker()->put("/chatbots/{$id}/channels/whatsapp/appearance", ['settings' => []])->assertNotFound();

        $fresh = $this->bot->fresh();
        $this->assertSame('Bot A', $fresh->name);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->agent_token_hash);
        $this->assertTrue($fresh->channels()->where('channel', 'whatsapp')->firstOrFail()->is_active);
    }

    public function test_every_integration_route_treats_a_foreign_id_as_missing(): void
    {
        $id = $this->integration->id;

        $this->asAttacker()->put("/integrations/{$id}", ['name' => 'Hacked', 'type' => 'http'])->assertNotFound();
        $this->asAttacker()->post("/integrations/{$id}/activate")->assertNotFound();
        $this->asAttacker()->post("/integrations/{$id}/deactivate")->assertNotFound();
        $this->asAttacker()->post("/integrations/{$id}/test")->assertNotFound();

        $fresh = $this->integration->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('WhatsApp', $fresh->name);
        $this->assertSame(self::SECRET, $fresh->secrets['access_token']);
    }

    public function test_every_conversation_route_treats_a_foreign_id_as_missing(): void
    {
        $id = $this->conversation->id;

        $this->asAttacker()->get('/conversations?c='.$id)->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/take")->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/release")->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/resolve")->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/messages", ['body' => 'hola'])->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/assign", ['user_id' => $this->attacker->id])->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/simulate/incoming", ['body' => 'x'])->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/simulate/ai-reply")->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/simulate/handoff")->assertNotFound();
        $this->asAttacker()->post("/conversations/{$id}/messages/{$this->message->id}/simulate-status", ['status' => 'read'])->assertNotFound();

        $fresh = $this->conversation->fresh();
        $this->assertSame('ai', $fresh->handling);
        $this->assertNull($fresh->assigned_user_id);
        $this->assertSame(1, $fresh->messages()->count());
    }

    public function test_a_foreign_conversation_filter_in_the_inbox_is_ignored_not_honored(): void
    {
        $this->asAttacker()->get('/conversations?chatbot='.$this->bot->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.chatbot', null)->has('conversations', 0));
    }

    public function test_a_conversation_cannot_be_assigned_to_a_member_of_another_workspace(): void
    {
        $own = $this->b->chatbots()->create(['name' => 'Bot B']);
        $conversation = $own->conversations()->create(['workspace_id' => $this->b->id, 'channel' => 'whatsapp', 'contact_id' => '573000000002', 'last_message_at' => now()]);

        $this->asAttacker()->post("/conversations/{$conversation->id}/assign", ['user_id' => $this->victim->id])->assertRedirect();

        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_saved_filters_of_another_workspace_or_user_do_not_exist(): void
    {
        $id = $this->filter->id;

        $this->asAttacker()->put("/saved-filters/audit/{$id}", ['name' => 'Hacked'])->assertNotFound();
        $this->asAttacker()->delete("/saved-filters/audit/{$id}")->assertNotFound();
        $this->assertSame('Filtro A', $this->filter->fresh()->name);

        // An account that belongs to both Workspaces still has separate filters in each.
        $this->b->users()->attach($this->victim->id, ['role' => 'admin']);
        $this->actingAs($this->victim)->withSession(['workspace_id' => $this->b->id])->delete("/saved-filters/audit/{$id}")->assertNotFound();
        $this->assertNotNull($this->filter->fresh());
    }

    public function test_users_of_another_workspace_cannot_be_read_or_changed(): void
    {
        $id = $this->victim->id;

        $this->asAttacker()->put("/users/{$id}", ['name' => 'Hacked', 'email' => 'hacked@x.test', 'role' => 'cliente'])->assertNotFound();
        $this->asAttacker()->post("/users/{$id}/deactivate")->assertNotFound();
        $this->asAttacker()->post("/users/{$id}/activate")->assertNotFound();

        $this->asAttacker()->get('/users')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->pluck('email')->doesntContain('victim@a.test')));

        $fresh = $this->victim->fresh();
        $this->assertSame('Victim Person', $fresh->name);
        $this->assertTrue($fresh->is_active);
    }

    public function test_a_client_admin_cannot_administer_workspaces_organizations_roles_or_foreign_memberships(): void
    {
        $workspace = $this->a->id;

        $this->asAttacker()->put("/workspaces/{$workspace}", ['code' => 'X', 'name' => 'Hacked'])->assertForbidden();
        $this->asAttacker()->post("/workspaces/{$workspace}/deactivate")->assertForbidden();
        $this->asAttacker()->post("/workspaces/{$workspace}/activate")->assertForbidden();
        $this->asAttacker()->post("/workspaces/{$workspace}/members", ['email' => $this->attacker->email, 'role' => 'admin'])->assertForbidden();
        $this->asAttacker()->put("/workspaces/{$workspace}/members/{$this->victim->id}", ['role' => 'cliente'])->assertNotFound();
        $this->asAttacker()->delete("/workspaces/{$workspace}/members/{$this->victim->id}")->assertNotFound();
        $this->asAttacker()->post('/organizations', ['name' => 'Nueva'])->assertForbidden();
        $this->asAttacker()->put('/organizations/'.$this->a->organization_id, ['name' => 'Hacked'])->assertForbidden();
        $this->asAttacker()->post('/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();

        $this->assertTrue($this->a->fresh()->is_active);
        $this->assertSame('Victim A', $this->a->fresh()->name);
        $this->assertSame('admin', $this->a->users()->whereKey($this->victim->id)->firstOrFail()->pivot->role);
        $this->assertFalse($this->a->users()->whereKey($this->attacker->id)->exists());
    }

    public function test_a_new_user_cannot_be_placed_in_a_workspace_the_actor_does_not_administer(): void
    {
        $this->asAttacker()->post('/users', [
            'name' => 'Intruso', 'email' => 'intruso@x.test', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
            'role' => 'admin', 'workspace_id' => $this->a->id,
        ])->assertSessionHasErrors('workspace_id');

        $this->assertNull(User::where('email', 'intruso@x.test')->first());
    }

    public function test_pages_of_one_workspace_never_carry_another_workspaces_data(): void
    {
        $this->a->settingsOrDefault();
        $this->b->chatbots()->create(['name' => 'Bot B']);

        foreach (['/dashboard', '/reports', '/audit', '/chatbots', '/integrations', '/conversations', '/users', '/settings'] as $path) {
            $body = $this->asAttacker()->get($path)->assertOk()->getContent();

            foreach (['Evento secreto de A', 'Mensaje privado de A', 'Contacto Privado', self::SECRET, 'victim@a.test', 'Bot A', '573000000001'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "{$path} leaked '{$leak}'");
            }
        }
    }

    public function test_the_workspace_logo_route_serves_only_the_active_workspaces_logo(): void
    {
        $this->asAttacker()->get('/workspace/logo?workspace='.$this->a->id)->assertNotFound();
    }

    public function test_the_administrative_workspace_may_look_at_another_but_every_write_stays_in_its_own(): void
    {
        $org = Organization::first();
        $admin = Workspace::create(['organization_id' => $org->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $super = User::factory()->create();
        $super->forceFill(['is_superuser' => true])->save();
        $admin->users()->attach($super->id, ['role' => 'admin']);
        $session = fn () => $this->actingAs($super)->withSession(['workspace_id' => $admin->id]);

        $session()->get('/chatbots?workspace='.$this->a->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('scope.readOnly', true)->where('scope.canManage', false));
        $session()->get('/integrations?workspace='.$this->a->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('scope.readOnly', true));
        $session()->get('/chatbots?workspace=all')->assertNotFound();
        $session()->get('/chatbots?workspace=999999')->assertNotFound();

        // Writes are applied to the session's Workspace, so the foreign ids do not exist for them.
        $session()->post("/chatbots/{$this->bot->id}/deactivate")->assertNotFound();
        $session()->post("/integrations/{$this->integration->id}/deactivate")->assertNotFound();
        $session()->post("/conversations/{$this->conversation->id}/take")->assertNotFound();
        $this->assertTrue($this->bot->fresh()->is_active);
        $this->assertTrue($this->integration->fresh()->is_active);

        // The read-only view of the integrations never carries the secret.
        $this->assertStringNotContainsString(self::SECRET, $session()->get('/integrations?workspace='.$this->a->id)->getContent());
    }

    public function test_a_session_pointing_at_a_workspace_the_user_left_is_closed(): void
    {
        $this->actingAs($this->attacker)->withSession(['workspace_id' => $this->a->id])->get('/dashboard')->assertRedirect(route('pre-login'));
        $this->assertGuest();
    }

    public function test_the_agent_api_ignores_any_workspace_or_chatbot_sent_by_the_caller(): void
    {
        $token = app(AgentAccess::class)->generate($this->bot);
        $botB = $this->b->chatbots()->create(['name' => 'Bot B']);

        $this->withToken($token)->getJson('/api/agent/config?workspace_id='.$this->b->id.'&chatbot_id='.$botB->id)->assertOk()
            ->assertJsonPath('chatbot.id', $this->bot->id)->assertJsonPath('workspace.code', 'VICTIM_A')
            ->assertJsonMissingPath('channels.whatsapp.access_token');
        $this->assertStringNotContainsString(self::SECRET, $this->withToken($token)->getJson('/api/agent/config')->getContent());

        $this->withHeaders(['X-Workspace-Id' => (string) $this->b->id])->withToken($token)->postJson('/api/agent/messages', [
            'channel' => 'whatsapp', 'contact_id' => '573009998877', 'direction' => 'in', 'type' => 'text', 'body' => 'hola',
            'workspace_id' => $this->b->id, 'chatbot_id' => $botB->id,
        ])->assertCreated();

        $this->assertSame(0, Conversation::where('workspace_id', $this->b->id)->count());
        $this->assertSame(2, Conversation::where('workspace_id', $this->a->id)->count());
    }
}
