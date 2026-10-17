<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\RunChatbotExecution;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\ChatbotExecution;
use App\Models\Message;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\ChannelDelivery;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A plan decides which modules a Workspace may use; roles still decide what each user may do inside them. The server
 * enforces both on every route, API endpoint and background path: hiding a menu entry is never the control.
 */
class ModuleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Workspace $dev;

    private Workspace $client;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->organization = Organization::create(['name' => 'CCG']);
        $this->dev = $this->workspace('DESARROLLO_DEV');
        $this->client = $this->workspace('CLIENTE_UNO');
        $this->other = $this->workspace('CLIENTE_DOS');

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function workspace(string $code): Workspace
    {
        return Workspace::create(['organization_id' => $this->organization->id, 'code' => $code, 'name' => $code]);
    }

    private function actIn(Workspace $workspace, string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    /** Gives the Workspace a plan with exactly these modules (by key). */
    private function contract(Workspace $workspace, array $keys, bool $active = true): Plan
    {
        $plan = Plan::create(['name' => 'Plan '.$workspace->code.count(Plan::all()), 'is_active' => $active]);
        $plan->modules()->sync(Module::whereIn('key', $keys)->pluck('id'));
        $workspace->update(['plan_id' => $plan->id]);

        return $plan;
    }

    private function everyModuleExcept(string ...$excluded): array
    {
        return array_values(array_diff(array_keys(config('modules.implemented')), $excluded));
    }

    // --- the plan decides what a Workspace can open ------------------------------------------------------------------

    /** @return array<string, array{string, string}> module key, URL */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard', '/dashboard'],
            'reports' => ['reports', '/reports'],
            'audit' => ['audit', '/audit'],
            'settings' => ['settings', '/settings'],
            'integrations' => ['integrations', '/integrations'],
            'users_roles' => ['users_roles', '/users'],
            'assistants' => ['assistants', '/chatbots'],
            'conversations' => ['conversations', '/conversations'],
        ];
    }

    #[DataProvider('pages')]
    public function test_a_module_missing_from_the_plan_cannot_be_opened_by_url_even_by_an_admin(string $module, string $url): void
    {
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, $this->everyModuleExcept($module));

        $this->get($url)->assertForbidden();
    }

    #[DataProvider('pages')]
    public function test_the_same_module_opens_when_the_plan_includes_it(string $module, string $url): void
    {
        $this->actIn($this->client, 'admin');
        // Asistentes is only operative together with Conversaciones (config/modules.php `dependencies`).
        $this->contract($this->client, $module === 'assistants' ? ['assistants', 'conversations'] : [$module]);

        $this->get($url)->assertOk();
    }

    #[DataProvider('pages')]
    public function test_the_default_plan_keeps_every_existing_workspace_working(string $module, string $url): void
    {
        $this->actIn($this->client, 'admin');
        Workspace::whereKey($this->client->id)->update(['plan_id' => null]);

        $this->assertNull($this->client->fresh()->plan_id);
        $this->get($url)->assertOk();
    }

    public function test_a_workspace_with_a_restricted_plan_does_not_affect_another(): void
    {
        $this->contract($this->client, ['dashboard']);
        $this->actIn($this->other, 'admin');

        $this->get('/audit')->assertOk();
        $this->get('/settings')->assertOk();
    }

    public function test_changing_the_plan_takes_effect_on_the_next_request(): void
    {
        $this->actIn($this->client, 'admin');
        $plan = $this->contract($this->client, ['dashboard']);

        $this->get('/audit')->assertForbidden();

        $plan->modules()->attach(Module::where('key', 'audit')->value('id'));
        $this->get('/audit')->assertOk();

        $plan->modules()->detach(Module::where('key', 'audit')->value('id'));
        $this->get('/audit')->assertForbidden();
    }

    public function test_writes_are_blocked_too_and_create_nothing(): void
    {
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['dashboard']);

        $this->post('/settings', ['name' => 'X'])->assertForbidden();
        $this->post('/integrations', ['name' => 'X', 'type' => 'http'])->assertForbidden();
        $this->post('/users', ['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'role' => 'cliente', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertForbidden();
        $this->post('/chatbots', ['name' => 'Bot'])->assertForbidden();
        $this->post('/saved-filters/audit', ['name' => 'F', 'criteria' => ['search' => 'x']])->assertForbidden();

        $this->assertSame(0, $this->client->chatbots()->count());
        $this->assertSame(0, $this->client->integrations()->count());
        $this->assertSame(0, User::where('email', 'nuevo@example.com')->count());
    }

    public function test_the_plan_never_grants_permissions_the_role_lacks(): void
    {
        $this->actIn($this->client, 'cliente');
        $this->contract($this->client, array_keys(config('modules.implemented')));

        $this->get('/dashboard')->assertOk();
        $this->get('/settings')->assertForbidden();
        $this->get('/audit')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/integrations')->assertForbidden();
        $this->get('/plans')->assertForbidden();
    }

    public function test_a_module_the_plan_includes_still_needs_the_users_permission(): void
    {
        Role::findOrCreate('solo_ver', 'web')->syncPermissions(['view-dashboard']);
        $this->actIn($this->client, 'solo_ver');
        $this->contract($this->client, ['dashboard', 'reports', 'audit']);

        $this->get('/reports')->assertOk();
        $this->get('/audit')->assertForbidden();
    }

    public function test_a_superuser_inside_a_client_workspace_is_limited_to_its_plan(): void
    {
        $this->actIn($this->client, 'admin', superuser: true);
        $this->contract($this->client, ['dashboard']);

        $this->get('/audit')->assertForbidden();
        $this->get('/plans')->assertForbidden();
    }

    public function test_the_administrative_workspace_always_has_every_module(): void
    {
        $this->actIn($this->dev, 'admin', superuser: true);
        $this->contract($this->dev, ['dashboard']);

        foreach (self::pages() as [, $url]) {
            $this->get($url)->assertOk();
        }
        $this->actIn($this->dev, 'plataforma');
        $this->get('/plans')->assertOk();
    }

    // --- modules that are not (or no longer) usable ---------------------------------------------------------------------

    public function test_a_registered_but_unimplemented_module_in_a_plan_grants_nothing(): void
    {
        $future = app(ModuleCatalog::class)->createModule(['name' => 'Citas', 'module_category_id' => ModuleCategory::where('key', 'health')->value('id')]);
        $plan = $this->contract($this->client, ['dashboard']);
        $plan->modules()->attach($future->id);

        $this->assertSame(['dashboard'], app(ModuleAccess::class)->keys($this->client->fresh()));
        $this->actIn($this->client, 'admin');
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('modules', ['dashboard']));
    }

    public function test_a_deactivated_module_or_plan_removes_the_access_without_deleting_data(): void
    {
        $this->actIn($this->client, 'admin');
        $plan = $this->contract($this->client, ['dashboard', 'audit']);
        $this->get('/audit')->assertOk();

        Module::where('key', 'audit')->update(['is_active' => false]);
        $this->get('/audit')->assertForbidden();
        $this->assertSame(2, $plan->modules()->count());

        Module::where('key', 'audit')->update(['is_active' => true]);
        $plan->update(['is_active' => false]);
        $this->get('/dashboard')->assertForbidden();
        $this->assertSame(2, $plan->modules()->count());

        $plan->update(['is_active' => true]);
        $this->get('/dashboard')->assertOk();
    }

    public function test_without_any_default_plan_a_workspace_fails_closed(): void
    {
        Workspace::query()->update(['plan_id' => null]);
        Plan::where('key', 'full_platform')->delete();

        $this->assertSame([], app(ModuleAccess::class)->keys($this->client->fresh()));
        $this->actIn($this->client, 'admin');
        $this->get('/dashboard')->assertForbidden();
        $this->get('/profile')->assertOk();
    }

    // --- the interface mirrors the plan ------------------------------------------------------------------------------------

    public function test_the_shared_modules_follow_the_plan(): void
    {
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['dashboard', 'reports']);

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('modules', fn ($modules) => collect($modules)->sort()->values()->all() === ['dashboard', 'reports'])->where('platform', false));
    }

    public function test_the_login_destination_skips_pages_the_plan_does_not_include(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $this->client->users()->attach($user->id, ['role' => 'admin']);
        $this->contract($this->client, ['audit', 'settings']);

        $this->post('/pre-login', ['workspace_code' => 'CLIENTE_UNO']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('audit.index', absolute: false));
    }

    public function test_technical_routes_do_not_depend_on_the_plan(): void
    {
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, []);

        $this->get('/profile')->assertOk();
        $this->get('/workspaces/search?purpose=assign')->assertOk();
        $this->get('/workspace/logo')->assertStatus(404);
    }

    // --- a client never changes its own commercial setup -------------------------------------------------------------------

    public function test_a_client_admin_cannot_change_its_plan_or_the_catalog(): void
    {
        $this->actIn($this->client, 'admin', superuser: true);
        $premium = Plan::create(['name' => 'Premium']);
        $premium->modules()->sync(Module::pluck('id'));
        $this->contract($this->client, ['dashboard']);
        $before = $this->client->fresh()->plan_id;

        $this->put("/workspaces/{$this->client->id}/plan", ['plan_id' => $premium->id])->assertForbidden();
        $this->put("/plans/{$premium->id}/modules", ['module_ids' => []])->assertForbidden();
        $this->post('/modules', ['name' => 'X', 'module_category_id' => ModuleCategory::first()->id])->assertForbidden();
        $this->put('/workspaces/'.$this->client->id, ['code' => 'CLIENTE_UNO', 'name' => 'Cliente', 'plan_id' => $premium->id])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->client->fresh()->plan_id);
        $this->assertSame(Module::count(), $premium->modules()->count());
    }

    // --- automatic processes and integrations do not skip the plan ---------------------------------------------------------

    private function agentToken(Workspace $workspace, string $name = 'Asistente'): array
    {
        $bot = $workspace->chatbots()->create(['name' => $name, 'instructions' => 'Sé breve']);

        return [$bot, app(AgentAccess::class)->generate($bot)];
    }

    private function agent(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_the_agent_api_needs_assistants_and_conversations_in_the_plan(): void
    {
        [, $token] = $this->agentToken($this->client);
        $payload = ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'text' => 'Hola', 'message_id' => 'wamid.1'];

        $this->getJson('/api/agent/config', $this->agent($token))->assertOk();

        // Asistentes cannot operate without Conversaciones, so taking Conversaciones away takes the whole agent API with it.
        $this->contract($this->client, $this->everyModuleExcept('conversations'));
        $this->getJson('/api/agent/config', $this->agent($token))->assertForbidden();
        $this->postJson('/api/agent/messages', $payload, $this->agent($token))->assertForbidden();
        $this->postJson('/api/agent/messages/status', ['message_id' => 'x', 'status' => 'read'], $this->agent($token))->assertForbidden();
        $this->postJson('/api/agent/conversations/authorize', [], $this->agent($token))->assertForbidden();
        $this->postJson('/api/agent/conversations/handoff', [], $this->agent($token))->assertForbidden();

        $this->contract($this->client, $this->everyModuleExcept('assistants'));
        $this->getJson('/api/agent/config', $this->agent($token))->assertForbidden();
        $this->postJson('/api/agent/messages', $payload, $this->agent($token))->assertForbidden();
        $this->assertSame(0, $this->client->conversations()->count());
    }

    public function test_the_agent_token_of_one_workspace_never_borrows_the_plan_of_another(): void
    {
        [, $tokenClient] = $this->agentToken($this->client);
        [, $tokenOther] = $this->agentToken($this->other);
        $this->contract($this->client, ['dashboard']);

        $this->getJson('/api/agent/config', $this->agent($tokenClient))->assertForbidden();
        $this->getJson('/api/agent/config', $this->agent($tokenOther))->assertOk();
    }

    public function test_the_public_widget_disappears_when_the_plan_loses_the_assistant_or_the_inbox(): void
    {
        $bot = $this->client->chatbots()->create(['name' => 'Web bot']);
        $this->client->integrations()->create([
            'name' => 'Web', 'type' => 'web', 'is_active' => true,
            'config' => ['webhook_url' => 'https://n8n.example.com/webhook/ava-web', 'allowed_origins' => []], 'secrets' => [],
        ]);
        $this->actIn($this->client, 'admin');
        $this->put("/chatbots/{$bot->id}/channels/web", ['is_active' => true])->assertSessionHas('success');
        $key = ChatbotChannel::where('chatbot_id', $bot->id)->where('channel', 'web')->firstOrFail()->public_key;
        $message = ['session_id' => 'visitor-session-1', 'message' => 'hola'];

        $this->getJson("/api/widget/{$key}/config")->assertOk();

        foreach (['conversations', 'assistants'] as $missing) {
            $this->contract($this->client, $this->everyModuleExcept($missing));
            $this->getJson("/api/widget/{$key}/config")->assertNotFound();
            $this->get("/api/widget/{$key}/avatar")->assertNotFound();
            $this->getJson("/api/widget/{$key}/messages?session_id=visitor-session-1")->assertNotFound();
            $this->postJson("/api/widget/{$key}/messages", $message)->assertNotFound();
        }

        $this->contract($this->client, array_keys(config('modules.implemented')));
        $this->getJson("/api/widget/{$key}/config")->assertOk();
        $this->assertSame(0, $this->client->conversations()->count());
    }

    public function test_an_agent_cannot_send_through_a_workspace_whose_plan_lost_the_inbox(): void
    {
        $bot = $this->client->chatbots()->create(['name' => 'Bot']);
        $conversation = $bot->conversations()->create(['workspace_id' => $this->client->id, 'channel' => 'web', 'contact_id' => 'visitor-1', 'session_id' => 'visitor-1']);

        $this->assertStringNotContainsString('plan', (string) app(ChannelDelivery::class)->availability($conversation->load('chatbot.workspace'))['message']);

        $this->contract($this->client, $this->everyModuleExcept('conversations'));
        $issue = app(ChannelDelivery::class)->availability($conversation->fresh()->load('chatbot.workspace'));

        $this->assertFalse($issue['ok']);
        $this->assertStringContainsString('plan', $issue['message']);
    }

    public function test_meta_events_for_a_workspace_without_the_modules_are_ignored(): void
    {
        config(['services.meta.app_secret' => 'meta-secret', 'services.meta.webhook_verify_token' => 'verify-me']);
        Queue::fake();
        $bot = $this->client->chatbots()->create(['name' => 'WA']);
        $integration = $this->client->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => '1000001'], 'secrets' => ['access_token' => 'EAAB-token'],
        ]);
        $bot->channels()->create(['workspace_id' => $this->client->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);

        $send = function (string $id) {
            $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1000001'],
                'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => '573001112233']],
                'messages' => [['from' => '573001112233', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hola']]],
            ]]]]]]);

            return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-secret'),
            ], $raw);
        };

        $this->contract($this->client, $this->everyModuleExcept('conversations'));
        $send('wamid.A')->assertOk();
        $this->assertSame(0, $this->client->conversations()->count());

        $this->contract($this->client, array_keys(config('modules.implemented')));
        $send('wamid.B')->assertOk();
        $this->assertSame(1, $this->client->conversations()->count());
    }

    public function test_a_queued_execution_does_not_run_after_the_plan_loses_the_modules(): void
    {
        config([
            'n8n.execution_url' => 'https://n8n.platform.example.com/webhook', 'n8n.secret' => 'n8n-shared-SECRET-123', 'n8n.backoff' => [0, 0],
            'n8n.workflows.support' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_support', 'webhook_path' => 'support-v1', 'version' => '1'],
            'services.meta.app_secret' => 'meta-secret', 'services.meta.webhook_verify_token' => 'verify-me',
        ]);
        Queue::fake();
        $bot = $this->client->chatbots()->create(['name' => 'WA', 'instructions' => 'Sé breve']);
        $integration = $this->client->integrations()->create([
            'name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true,
            'config' => ['phone_number_id' => '1000001'], 'secrets' => ['access_token' => 'EAAB-token'],
        ]);
        $bot->channels()->create(['workspace_id' => $this->client->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        app(ChatbotWorkflows::class)->assign($bot->refresh(), 'support');

        $raw = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'W', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1000001'],
            'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => '573001112233']],
            'messages' => [['from' => '573001112233', 'id' => 'wamid.X', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]]]]]]);
        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-secret'),
        ], $raw)->assertOk();
        $job = Queue::pushed(RunChatbotExecution::class)->last();
        $this->assertNotNull($job);

        // The contract ends between the message and the run: nothing is sent to the workflow or to the contact.
        $this->contract($this->client, $this->everyModuleExcept('assistants'));
        Http::fake();
        app(ChatbotExecutions::class)->run($job->executionId);

        Http::assertNothingSent();
        $this->assertNotSame('succeeded', ChatbotExecution::firstOrFail()->status);
        $this->assertSame(0, Message::where('direction', 'out')->count());
    }

    public function test_saved_filters_follow_the_module_that_owns_them(): void
    {
        $this->actIn($this->client, 'admin');
        $this->contract($this->client, ['dashboard', 'conversations']);

        $this->post('/saved-filters/audit', ['name' => 'F', 'criteria' => ['search' => 'x']])->assertForbidden();
        $this->post('/saved-filters/conversations', ['name' => 'F', 'criteria' => ['status' => 'pending', 'q' => 'ana']])->assertSessionHasNoErrors();
    }

    public function test_the_chatbot_model_still_belongs_only_to_its_workspace(): void
    {
        $this->contract($this->client, ['assistants']);
        $bot = $this->client->chatbots()->create(['name' => 'Propio']);

        $this->assertSame($this->client->id, Chatbot::findOrFail($bot->id)->workspace_id);
        $this->assertSame(0, $this->other->chatbots()->count());
    }
}
