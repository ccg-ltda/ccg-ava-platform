<?php

namespace Tests\Feature;

use App\Chatbots\ExecutionOutcome;
use App\Chatbots\N8nConnection;
use App\Chatbots\N8nExecutor;
use App\Chatbots\ResolvedWorkflow;
use App\Integrations\SafeHttpTarget;
use App\Models\ApiCallLog;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChatbotExecutions;
use App\Services\ChatbotWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Each Workspace runs its conversations on ITS OWN n8n connection (Integraciones). The platform variables are a named, scoped transition and never
 * serve a Workspace that has its own connection. Every n8n here is a simulated HTTP client: nothing leaves the machine.
 */
class N8nWorkspaceExecutionTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const DEV_URL = 'https://n8n.dev.example.com';

    private const DEV_SECRET = 'dev-execution-SECRET-0001';

    private const A_URL = 'https://n8n.cliente-a.example.com';

    private const A_SECRET = 'cliente-a-execution-SECRET-02';

    private const ENV_URL = 'https://n8n.platform.example.com/webhook';

    private const ENV_SECRET = 'platform-env-SECRET-0003';

    private Workspace $dev;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        config(['n8n.execution_url' => self::ENV_URL, 'n8n.secret' => self::ENV_SECRET, 'n8n.legacy_environment' => 'administrative', 'n8n.backoff' => [0, 0],
            'n8n.workflows.soporte' => ['name' => 'Soporte', 'n8n_workflow_id' => 'wf_s', 'webhook_path' => 'soporte-v1', 'version' => '1'],
            'n8n.workflows.ventas' => ['name' => 'Ventas', 'n8n_workflow_id' => 'wf_v', 'webhook_path' => 'ventas-v1', 'version' => '1', 'workspaces' => ['CLIENTE_A']],
            'n8n.workflows.general' => ['name' => 'General', 'n8n_workflow_id' => 'wf_g', 'webhook_path' => 'general-v1', 'version' => '1', 'workspaces' => ['*']]]);
        $this->seedRoleCatalog();
        $organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations', 'assistants', 'integrations']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations', 'assistants', 'integrations']);
    }

    private function connect(Workspace $workspace, string $url, string $secret, bool $active = true): Integration
    {
        return $workspace->integrations()->create(['name' => 'n8n', 'type' => 'n8n', 'is_active' => $active, 'config' => ['base_url' => $url], 'secrets' => array_filter(['api_key' => 'api-key-'.$workspace->code, 'execution_secret' => $secret])]);
    }

    private function bot(Workspace $workspace, ?string $workflow = null): Chatbot
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente '.$workspace->code, 'instructions' => 'Atiende']);

        if ($workflow) {
            app(ChatbotWorkflows::class)->assign($bot->refresh(), $workflow);
        }

        return $bot->refresh();
    }

    private function runWorkflow(Chatbot $bot, string $workflowPath = 'general-v1'): ExecutionOutcome
    {
        return app(N8nExecutor::class)->run(new ResolvedWorkflow('general', 'General', 'wf_g', $workflowPath), 'corr-'.$bot->id, ['chatbot' => ['id' => $bot->id], 'event' => 'message', 'message' => ['text' => 'Hola'], 'history' => [], 'context' => []]);
    }

    // --- which connection serves which Workspace --------------------------------------------------------------

    public function test_a_workspace_runs_on_its_own_connection_and_each_one_gets_only_its_own_secret(): void
    {
        $this->connect($this->dev, self::DEV_URL, self::DEV_SECRET);
        $this->connect($this->a, self::A_URL, self::A_SECRET);
        $connections = app(N8nConnection::class);

        $this->assertSame(['workspace', self::DEV_URL.'/webhook', self::DEV_SECRET], [$connections->forWorkspace($this->dev)->source, $connections->forWorkspace($this->dev)->baseUrl, $connections->forWorkspace($this->dev)->secret]);
        $this->assertSame(['workspace', self::A_URL.'/webhook', self::A_SECRET], [$connections->forWorkspace($this->a)->source, $connections->forWorkspace($this->a)->baseUrl, $connections->forWorkspace($this->a)->secret]);
        $this->assertNull($connections->forWorkspace($this->b), 'No connection of its own and no legacy scope for clients: nothing.');
    }

    public function test_the_request_of_a_conversation_goes_to_its_workspace_n8n_signed_with_its_secret_only(): void
    {
        $this->connect($this->dev, self::DEV_URL, self::DEV_SECRET);
        $this->connect($this->a, self::A_URL, self::A_SECRET);
        $botA = $this->bot($this->a, 'general');
        $seen = [];
        Http::fake(['*' => function (Request $request) use (&$seen) {
            $seen[] = $request;

            return Http::response(['execution_id' => $request->header('X-Ava-Execution')[0], 'reply' => 'ok']);
        }]);

        $outcome = $this->runWorkflow($botA);

        $this->assertSame('answered', $outcome->kind);
        $this->assertCount(1, $seen);
        $request = $seen[0];
        $this->assertSame('n8n.cliente-a.example.com', parse_url($request->url(), PHP_URL_HOST));
        $this->assertSame(self::A_URL.'/webhook/general-v1', $request->url());
        $timestamp = $request->header('X-Ava-Timestamp')[0];
        $this->assertSame('sha256='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", self::A_SECRET), $request->header('X-Ava-Signature')[0]);
        // Nothing of any other Workspace's credentials travels, in the headers or in the body.
        $everything = json_encode($request->headers()).$request->body();
        foreach ([self::DEV_SECRET, self::ENV_SECRET, 'api-key-CLIENTE_A', 'api-key-DESARROLLO_DEV'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $everything);
        }
        $this->assertStringNotContainsString(self::A_SECRET, $everything);
        $this->assertSame($this->a->id, ApiCallLog::where('integration', 'n8n')->latest('id')->value('workspace_id'));
    }

    public function test_a_workspace_without_a_connection_fails_safe_and_never_borrows_the_platform_variables(): void
    {
        $botB = $this->bot($this->b, 'general');
        Http::fake();

        $outcome = $this->runWorkflow($botB);

        $this->assertSame('not_configured', $outcome->code);
        $this->assertSame('permanent', $outcome->kind);
        Http::assertNothingSent();
        $this->assertFalse(app(ChatbotExecutions::class)->available($botB));
    }

    public function test_the_legacy_variables_follow_their_declared_scope_and_never_override_an_own_connection(): void
    {
        $connections = app(N8nConnection::class);

        // `administrative` (default): only Desarrollo_DEV, and it says so.
        $this->assertSame('environment', $connections->forWorkspace($this->dev)->source);
        $this->assertNull($connections->forWorkspace($this->a));

        config(['n8n.legacy_environment' => 'all']);
        $this->assertSame('environment', $connections->forWorkspace($this->a)->source);

        config(['n8n.legacy_environment' => 'none']);
        $this->assertNull($connections->forWorkspace($this->dev));
        $this->assertNull($connections->forWorkspace($this->a));

        // An own connection always wins over the variables, whatever the scope.
        config(['n8n.legacy_environment' => 'all']);
        $this->connect($this->a, self::A_URL, self::A_SECRET);
        $this->assertSame(['workspace', self::A_SECRET], [$connections->forWorkspace($this->a)->source, $connections->forWorkspace($this->a)->secret]);
        $this->assertSame('environment', $connections->forWorkspace($this->b)->source, 'B is still on the legacy scope; A\'s connection is not B\'s.');
    }

    public function test_an_inactive_or_secretless_connection_is_not_a_connection(): void
    {
        $this->connect($this->a, self::A_URL, self::A_SECRET, active: false);
        $this->connect($this->b, self::A_URL, '');

        $this->assertNull(app(N8nConnection::class)->forWorkspace($this->a));
        $this->assertNull(app(N8nConnection::class)->forWorkspace($this->b), 'API Key alone verifies n8n; it does not run conversations.');
        $this->assertSame(['ready' => false, 'source' => null, 'host' => null], app(N8nConnection::class)->status($this->a));
    }

    public function test_timeouts_and_failures_are_recorded_for_the_workspace_without_any_secret(): void
    {
        $this->connect($this->a, self::A_URL, self::A_SECRET);
        $botA = $this->bot($this->a, 'general');
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $outcome = $this->runWorkflow($botA);

        $this->assertSame(['temporary', 'timeout'], [$outcome->kind, $outcome->code]);
        $log = ApiCallLog::where('integration', 'n8n')->latest('id')->firstOrFail();
        $this->assertSame([$this->a->id, 'timeout'], [$log->workspace_id, $log->outcome]);
        $dump = json_encode($log->getAttributes());
        foreach ([self::A_SECRET, self::ENV_SECRET, 'api-key-CLIENTE_A', 'Hola'] as $private) {
            $this->assertStringNotContainsString($private, $dump);
        }
    }

    // --- the catalog is authorized per Workspace --------------------------------------------------------------

    public function test_a_workflow_without_audience_is_reserved_to_the_administrative_workspace(): void
    {
        $workflows = app(ChatbotWorkflows::class);

        $this->assertSame('soporte', $this->bot($this->dev, 'soporte')->workflow_key);
        $this->assertTrue($workflows->assignable('soporte', $this->dev));
        $this->assertFalse($workflows->assignable('soporte', $this->a));

        $this->expectException(InvalidArgumentException::class);
        $this->bot($this->a, 'soporte');
    }

    public function test_a_published_workflow_is_assignable_only_to_the_workspaces_it_names_and_is_checked_again_when_it_runs(): void
    {
        $workflows = app(ChatbotWorkflows::class);

        $this->assertTrue($workflows->assignable('ventas', $this->a));
        $this->assertFalse($workflows->assignable('ventas', $this->b));
        $this->assertFalse($workflows->assignable('ventas', $this->dev));
        $this->assertTrue($workflows->assignable('general', $this->b));

        $botA = $this->bot($this->a, 'ventas');
        $this->assertNotNull($workflows->for($botA));

        // A key written behind the model's back (raw SQL, or a catalog that stopped publishing it) grants nothing on the next execution.
        \DB::table('chatbots')->where('id', $botA->id)->update(['workflow_key' => 'soporte']);
        $this->assertNull($workflows->for($botA->refresh()));
        \DB::table('chatbots')->where('id', $botA->id)->update(['workflow_key' => 'ventas']);
        config(['n8n.workflows.ventas.workspaces' => ['CLIENTE_B']]);
        $this->assertNull($workflows->for($botA->refresh()));
        $this->assertSame('ventas', $botA->workflow_key, 'The assignment is kept; it just does not run.');

        $this->assertSame(['*'], $workflows->audience('general'));
        $this->assertSame([], $workflows->audience('soporte'));
    }

    // --- the connection screen --------------------------------------------------------------------------------

    private function actAsAdmin(Workspace $workspace, string $role = 'admin'): User
    {
        $user = $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    public function test_the_execution_secret_is_saved_encrypted_for_the_active_workspace_only_and_never_comes_back(): void
    {
        $this->actAsAdmin($this->a);

        $this->put('/integrations/n8n', ['base_url' => self::A_URL, 'api_key' => 'api-key-A-123456', 'execution_secret' => self::A_SECRET])->assertSessionHasNoErrors();

        $integration = $this->a->integrations()->where('type', 'n8n')->firstOrFail();
        $this->assertSame(self::A_SECRET, $integration->secrets['execution_secret']);
        $this->assertStringNotContainsString(self::A_SECRET, (string) \DB::table('integrations')->where('id', $integration->id)->value('secrets'), 'Encrypted at rest.');
        $this->assertSame(0, $this->b->integrations()->count());

        $page = $this->get('/integrations');
        $this->assertStringNotContainsString(self::A_SECRET, $page->getContent());
        $connection = $page->viewData('page')['props']['automation']['connection'];
        $this->assertTrue($connection['form']['execution_secret_set']);
        $this->assertSame('', $connection['form']['execution_secret']);
        $this->assertSame(['ready' => true, 'source' => 'workspace', 'host' => 'n8n.cliente-a.example.com'], $connection['execution']);

        // Leaving it blank keeps it; a short one is refused.
        $this->put('/integrations/n8n', ['base_url' => self::A_URL, 'api_key' => '', 'execution_secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::A_SECRET, $integration->refresh()->secrets['execution_secret']);
        $this->put('/integrations/n8n', ['base_url' => self::A_URL, 'execution_secret' => 'corto'])->assertSessionHasErrors('execution_secret');
        $this->assertFalse(AuditLog::where('workspace_id', $this->a->id)->get()->contains(fn ($row) => str_contains(json_encode($row->getAttributes()), self::A_SECRET)));
    }

    public function test_without_manage_integrations_nobody_changes_the_connection_and_workspaces_stay_apart(): void
    {
        $this->connect($this->a, self::A_URL, self::A_SECRET);
        $this->actAsAdmin($this->b, 'cliente'); // no manage-integrations

        $this->put('/integrations/n8n', ['base_url' => 'https://evil.example.com', 'api_key' => 'x-api-key-123456', 'execution_secret' => 'evil-secret-0000000000'])->assertForbidden();
        $this->assertSame(self::A_SECRET, $this->a->integrations()->where('type', 'n8n')->firstOrFail()->secrets['execution_secret']);
        $this->assertSame(0, $this->b->integrations()->count());

        // An administrator of B edits B's own; A's is untouched.
        $this->actAsAdmin($this->b);
        $this->put('/integrations/n8n', ['base_url' => 'https://n8n.cliente-b.example.com', 'api_key' => 'api-key-B-123456', 'execution_secret' => 'cliente-b-secret-000000'])->assertSessionHasNoErrors();
        $this->assertSame(self::A_URL, $this->a->integrations()->where('type', 'n8n')->firstOrFail()->config['base_url']);
        $this->assertSame('https://n8n.cliente-b.example.com', $this->b->integrations()->where('type', 'n8n')->firstOrFail()->config['base_url']);
    }
}
