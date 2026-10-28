<?php

namespace Tests\Feature;

use App\Integrations\SafeHttpTarget;
use App\Jobs\SendSupportNotification;
use App\Models\ApiCallLog;
use App\Services\IntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Coverage of the outbound HTTP calls added to the history: the Workspace's generic API, its own n8n and CCG's support alert. Always metadata only — no bodies, no request
 * headers and no query string (those are where a Workspace's credentials live) — and a failure with no HTTP answer is recorded too.
 */
class ApiCallCoverageTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        $this->seedRoleCatalog();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_the_generic_api_test_is_recorded_without_its_query_headers_or_bodies(): void
    {
        Http::fake(['api.example.test/*' => Http::response(['ok' => true, 'token' => 'RESP-TOKEN'])]);
        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'integrations']);
        $integration = $ws->integrations()->create(['name' => 'API', 'type' => 'http', 'is_active' => true, 'secrets' => ['auth' => 'BEARER-SECRET-1'],
            'config' => ['base_url' => 'https://api.example.test/v1', 'endpoint' => '/status', 'method' => 'GET', 'timeout' => 5, 'auth' => ['type' => 'bearer'], 'headers' => [['name' => 'X-Custom', 'value' => 'HDR-SECRET-2', 'secret' => true]], 'query' => [['name' => 'k', 'value' => 'QUERY-SECRET-3', 'secret' => true]], 'body' => null]]);

        app(IntegrationService::class)->test($integration);

        $row = ApiCallLog::where('integration', 'http_api')->firstOrFail();
        $this->assertSame($ws->id, $row->workspace_id);
        $this->assertSame('/v1/status', $row->path);
        $this->assertSame(200, $row->status_code);
        $dump = json_encode($row->toArray());
        foreach (['BEARER-SECRET-1', 'HDR-SECRET-2', 'QUERY-SECRET-3', 'RESP-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_a_timeout_of_the_generic_api_is_recorded_even_with_no_http_answer(): void
    {
        Http::fake(['api.example.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received')]);
        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'integrations']);
        $integration = $ws->integrations()->create(['name' => 'API', 'type' => 'http', 'is_active' => true,
            'config' => ['base_url' => 'https://api.example.test', 'endpoint' => '/status', 'method' => 'GET', 'timeout' => 5, 'auth' => ['type' => 'none'], 'headers' => [], 'query' => [], 'body' => null]]);

        $result = app(IntegrationService::class)->test($integration);

        $this->assertFalse($result->ok); // the behavior of the test is unchanged
        $row = ApiCallLog::where('integration', 'http_api')->firstOrFail();
        $this->assertSame('timeout', $row->outcome);
        $this->assertNull($row->status_code);
    }

    public function test_the_n8n_connection_test_of_a_workspace_is_recorded_without_its_api_key(): void
    {
        Http::fake(['n8n.cliente.test/*' => Http::response(['data' => []])]);
        $ws = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'integrations']);
        $integration = $ws->integrations()->create(['name' => 'n8n', 'type' => 'n8n', 'is_active' => true, 'secrets' => ['api_key' => 'N8N-KEY-SECRET'], 'config' => ['base_url' => 'https://n8n.cliente.test', 'timeout' => 5]]);

        app(IntegrationService::class)->test($integration);

        $row = ApiCallLog::where('integration', 'n8n_api')->firstOrFail();
        $this->assertSame($ws->id, $row->workspace_id);
        $this->assertStringNotContainsString('N8N-KEY-SECRET', json_encode($row->toArray()));
        $this->assertNull($row->request_headers === '[]' ? null : $row->request_headers);
    }

    public function test_the_support_alert_is_a_platform_call_recorded_without_the_secret_the_body_or_the_url_query(): void
    {
        config(['support.notify_webhook_url' => 'https://hooks.ccg.test/alert?token=URL-TOKEN-9', 'support.notify_secret' => 'ALERT-SECRET-7']);
        Http::fake(['hooks.ccg.test/*' => Http::response(['ok' => true])]);

        (new SendSupportNotification('new_conversation', 41, 3))->handle(app(SafeHttpTarget::class));

        $row = ApiCallLog::where('integration', 'support_notify')->firstOrFail();
        $this->assertNull($row->workspace_id);
        $this->assertSame('conversation', $row->resource_type);
        $this->assertSame(41, $row->resource_id);
        $dump = json_encode($row->toArray());
        foreach (['URL-TOKEN-9', 'ALERT-SECRET-7', 'new_conversation'] as $private) {
            $this->assertStringNotContainsString($private, $dump);
        }
        $this->assertSame('/alert', $row->path);
    }
}
