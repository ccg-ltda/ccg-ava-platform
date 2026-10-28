<?php

namespace Tests\Feature;

use App\Models\ApiCallLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Observability\ApiCallRecorder;
use App\Observability\ApiRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** The history of outbound API calls: real recordings (success, HTTP error, timeout, connection failure), redaction before storing, size limits and isolation. */
class ApiCallLogTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'integrations']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'integrations']);
    }

    private function record(callable $send, array $context = [], bool $bodies = true, string $url = 'https://api.example.test/v1/pay?token=abc123&page=2', array $headers = [], ?string $body = null)
    {
        return app(ApiCallRecorder::class)->call('demo', 'op', 'POST', $url, $send, $headers, $body, $context, $bodies);
    }

    public function test_success_http_error_timeout_and_connection_failure_are_all_recorded(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.example.test/ok' => Http::response(['ok' => true]), 'api.example.test/bad' => Http::response(['error' => 'x'], 422)]);

        $this->record(fn () => Http::get('https://api.example.test/ok'), ['workspace_id' => $this->a->id], true, 'https://api.example.test/ok');
        $this->record(fn () => Http::get('https://api.example.test/bad'), ['workspace_id' => $this->a->id], true, 'https://api.example.test/bad');

        foreach (['cURL error 28: Operation timed out' => 'timeout', 'cURL error 6: Could not resolve host' => 'connection_error'] as $message => $outcome) {
            try {
                $this->record(fn () => throw new ConnectionException($message), ['workspace_id' => $this->a->id]);
                $this->fail('the failure must be rethrown');
            } catch (ConnectionException) {
                // the caller still sees the original failure
            }
            $row = ApiCallLog::latest('id')->first();
            $this->assertSame($outcome, $row->outcome);
            $this->assertNull($row->status_code);
            $this->assertSame(ConnectionException::class, $row->error_class);
        }

        $this->assertSame(['success', 'http_error', 'timeout', 'connection_error'], ApiCallLog::orderBy('id')->pluck('outcome')->all());
        $this->assertSame([200, 422], ApiCallLog::whereNotNull('status_code')->orderBy('id')->pluck('status_code')->all());
        $this->assertTrue(ApiCallLog::first()->duration_ms >= 0);
        $this->assertNotSame('', ApiCallLog::first()->correlation_id);
    }

    public function test_nested_secrets_in_headers_bodies_and_urls_never_reach_the_database(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => ['access_token' => 'RESP-TOKEN-999', 'nested' => ['client_secret' => 'RESP-SECRET-888'], 'note' => 'ok'], 'card' => '4242 4242 4242 4242'], 200, ['Set-Cookie' => 'sid=COOKIE-777'])]);

        $this->record(
            fn () => Http::withToken('BEARER-111')->post('https://api.example.test/v1/pay?token=abc123&page=2', []),
            ['workspace_id' => $this->a->id],
            true,
            'https://api.example.test/v1/pay?token=abc123&page=2',
            ['Authorization' => 'Bearer BEARER-111', 'X-Api-Key' => 'KEY-222', 'Accept' => 'application/json'],
            json_encode(['amount' => 100, 'user' => ['password' => 'PASS-333', 'profile' => ['private_key' => 'prv_test_ABCDEF123', 'name' => 'Ana']], 'list' => [['secret' => 'LIST-444']], 'ref' => 'pub_test_ABCDEF']),
        );

        $dump = json_encode(ApiCallLog::first()->toArray());
        foreach (['BEARER-111', 'KEY-222', 'PASS-333', 'prv_test_ABCDEF123', 'LIST-444', 'RESP-TOKEN-999', 'RESP-SECRET-888', 'COOKIE-777', '4242 4242', 'abc123', 'pub_test_ABCDEF'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, "leaked: {$secret}");
        }
        // The useful, non-sensitive parts survive for diagnosis.
        $this->assertStringContainsString('amount', $dump);
        $this->assertStringContainsString('100', ApiCallLog::first()->request_body);
        $this->assertStringContainsString('Ana', $dump);
        $this->assertStringContainsString('page=2', $dump);
        $this->assertStringContainsString('Accept', $dump);
    }

    public function test_large_bodies_are_truncated_and_marked_but_status_and_error_survive(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(str_repeat('x', 50000), 500)]);

        $this->record(fn () => Http::get('https://api.example.test/big'), ['workspace_id' => $this->a->id]);

        $row = ApiCallLog::first();
        $this->assertTrue($row->truncated);
        $this->assertLessThan(ApiRedactor::MAX_BYTES + 100, strlen($row->response_body));
        $this->assertSame(500, $row->status_code);
        $this->assertSame('http_error', $row->outcome);
    }

    public function test_bodies_are_not_kept_when_the_integration_forbids_it(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['text' => 'conversación privada'])]);

        $this->record(fn () => Http::post('https://api.example.test/chat', ['x' => 1]), ['workspace_id' => $this->a->id], false, 'https://api.example.test/chat', [], '{"text":"conversación privada"}');

        $row = ApiCallLog::first();
        $this->assertNull($row->request_body);
        $this->assertNull($row->response_body);
        $this->assertSame(200, $row->status_code);
    }

    public function test_a_failing_log_never_breaks_the_call(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);
        \Schema::drop('api_call_logs');

        $response = $this->record(fn () => Http::get('https://api.example.test/x'));

        $this->assertTrue($response->successful());
    }

    public function test_the_history_is_isolated_by_workspace_and_protected(): void
    {
        $mine = ApiCallLog::create(['workspace_id' => $this->a->id, 'integration' => 'wompi', 'operation' => 'get_transaction', 'method' => 'GET', 'host' => 'sandbox.wompi.co', 'path' => '/v1/x', 'status_code' => 200, 'outcome' => 'success', 'duration_ms' => 12, 'correlation_id' => 'c-a']);
        $theirs = ApiCallLog::create(['workspace_id' => $this->b->id, 'integration' => 'n8n', 'operation' => 'run_workflow', 'method' => 'POST', 'host' => 'n8n.b.test', 'path' => '/hook', 'status_code' => 500, 'outcome' => 'http_error', 'duration_ms' => 5, 'correlation_id' => 'c-b', 'request_body' => 'SECRETO-DE-B']);
        $platform = ApiCallLog::create(['workspace_id' => null, 'integration' => 'wompi', 'operation' => 'validate_merchant', 'method' => 'GET', 'host' => 'sandbox.wompi.co', 'path' => '/v1/m', 'status_code' => 200, 'outcome' => 'success', 'duration_ms' => 3, 'correlation_id' => 'c-p']);

        $this->actingAs($this->member($this->a, 'admin'))->withSession(['workspace_id' => $this->a->id]);
        $list = $this->getJson('/integrations/calls')->assertOk();
        $this->assertSame([$mine->id], collect($list->json('data'))->pluck('id')->all());
        $this->getJson("/integrations/calls/{$mine->id}")->assertOk()->assertJsonPath('correlationId', 'c-a');
        $this->getJson("/integrations/calls/{$theirs->id}")->assertNotFound();
        $this->getJson("/integrations/calls/{$platform->id}")->assertNotFound();
        $this->getJson('/integrations/calls?integration=n8n')->assertJsonPath('total', 0);

        // The Workspace id sent by the browser is not trusted.
        $this->getJson('/integrations/calls?workspace_id='.$this->b->id)->assertJsonPath('total', 1);

        // A role without view-integrations gets nothing.
        $this->actingAs($this->member($this->a, 'cliente'))->withSession(['workspace_id' => $this->a->id]);
        $this->getJson('/integrations/calls')->assertForbidden();

        auth()->logout();
        $this->getJson('/integrations/calls')->assertUnauthorized();
    }

    public function test_the_administrative_workspace_also_sees_the_platform_calls_and_filters_work(): void
    {
        $dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        $dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        $base = ['integration' => 'wompi', 'operation' => 'validate_merchant', 'method' => 'GET', 'host' => 'h', 'path' => '/p', 'duration_ms' => 1, 'correlation_id' => 'c'];
        ApiCallLog::create(array_merge($base, ['workspace_id' => null, 'status_code' => 200, 'outcome' => 'success']));
        ApiCallLog::create(array_merge($base, ['workspace_id' => $dev->id, 'integration' => 'n8n', 'status_code' => 500, 'outcome' => 'http_error']));
        ApiCallLog::create(array_merge($base, ['workspace_id' => $this->a->id, 'status_code' => 200, 'outcome' => 'success']));

        $this->getJson('/integrations/calls')->assertJsonPath('total', 2);
        $this->getJson('/integrations/calls?outcome=http_error')->assertJsonPath('total', 1);
        $this->getJson('/integrations/calls?status=500')->assertJsonPath('total', 1);
        $this->getJson('/integrations/calls?integration=wompi')->assertJsonPath('total', 1);
        $this->getJson('/integrations/calls?from='.now()->toDateString().'&to='.now()->toDateString())->assertJsonPath('total', 2);
    }

    public function test_prune_removes_only_records_older_than_the_retention(): void
    {
        $base = ['integration' => 'x', 'operation' => 'o', 'method' => 'GET', 'host' => 'h', 'path' => '/p', 'outcome' => 'success', 'duration_ms' => 1, 'correlation_id' => 'c'];
        ApiCallLog::create(array_merge($base, ['created_at' => now()->subDays(ApiCallRecorder::RETENTION_DAYS + 1)]));
        ApiCallLog::create(array_merge($base, ['created_at' => now()->subDay()]));

        $this->assertSame(1, app(ApiCallRecorder::class)->prune());
        $this->assertSame(1, ApiCallLog::count());
    }
}
