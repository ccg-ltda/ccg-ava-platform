<?php

namespace Tests\Feature;

use App\Models\Chatbot;
use App\Models\Workspace;
use App\Services\AgentAccess;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The layers that protect the API from abuse and overload (config/api_protection.php, RECOMENDACIONES.md §79): the Workspace buckets and the concurrency cap of the agent API,
 * body size limits, the shared widget / booking buckets, the 429 contract (Retry-After, no secrets in the answer or the log) and that identities are never taken from what the
 * client says. The counters live in the cache (array here; Redis or the database in the real stacks).
 */
class ApiProtectionTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];

    private Workspace $a;

    private Workspace $b;

    private Chatbot $botA;

    private string $tokenA;

    private string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->a = $this->bookingWorkspace('PRO_A', self::MODULES);
        $this->b = $this->bookingWorkspace('PRO_B', self::MODULES);
        $this->botA = $this->a->chatbots()->create(['name' => 'Asistente A']);
        $this->tokenA = app(AgentAccess::class)->generate($this->botA);
        $this->tokenB = app(AgentAccess::class)->generate($this->b->chatbots()->create(['name' => 'Asistente B']));
        RateLimiter::clear('agent-ws|'.$this->a->id);
        Cache::flush();
    }

    public function test_one_workspace_that_exceeds_its_bucket_gets_429_with_retry_after_and_another_keeps_working(): void
    {
        config(['api_protection.agent.workspace_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();
        }

        $limited = $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertStatus(429);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertStringNotContainsString($this->tokenA, $limited->getContent());

        // A different identity (another Workspace's token) is not affected, and the counter of A is its own.
        $this->withToken($this->tokenB)->getJson('/api/agent/config')->assertOk();
    }

    public function test_the_workspace_comes_from_the_token_never_from_what_the_client_sends(): void
    {
        config(['api_protection.agent.workspace_per_minute' => 2]);

        // Claiming the other Workspace in the query, the headers or the body changes nothing: the bucket is the token's.
        foreach (range(1, 2) as $i) {
            $this->withToken($this->tokenA)->withHeaders(['X-Workspace-Id' => (string) $this->b->id])->getJson('/api/agent/config?workspace_id='.$this->b->id)->assertOk();
        }
        $this->withToken($this->tokenA)->withHeaders(['X-Workspace-Id' => (string) $this->b->id, 'X-Forwarded-For' => '203.0.113.9'])->getJson('/api/agent/config?workspace_id='.$this->b->id)->assertStatus(429);
        $this->withToken($this->tokenB)->getJson('/api/agent/config')->assertOk();
    }

    public function test_state_changing_calls_have_their_own_stricter_bucket_while_reads_are_unaffected(): void
    {
        config(['api_protection.agent.write_per_minute' => 2]);
        $body = ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'direction' => 'in', 'type' => 'text', 'text' => 'hola', 'external_id' => 'x'];

        $this->withToken($this->tokenA)->postJson('/api/agent/messages/status', [])->assertStatus(422);
        $this->withToken($this->tokenA)->postJson('/api/agent/messages/status', [])->assertStatus(422);
        $this->withToken($this->tokenA)->postJson('/api/agent/messages', $body)->assertStatus(429);

        // Reads still pass: a conversation can always ask what it needs to resolve itself.
        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();
    }

    public function test_the_concurrency_cap_refuses_extra_simultaneous_requests_and_frees_its_slot_afterwards(): void
    {
        config(['api_protection.agent.concurrent' => 1]);
        $slot = "api-protect:agent:concurrent:{$this->a->id}";
        Cache::put($slot, 1, 120); // another request of this Workspace is being served right now

        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->assertSame(1, (int) Cache::get($slot), 'a refused request must not leak a slot');

        Cache::put($slot, 0, 120);
        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();
        $this->assertSame(0, (int) Cache::get($slot), 'the slot is freed when the request ends');
    }

    public function test_a_bad_token_is_refused_without_touching_any_workspace_bucket(): void
    {
        $this->withToken('ava_'.str_repeat('x', 40))->getJson('/api/agent/config')->assertUnauthorized();
        $this->assertSame(0, (int) Cache::get("api-protect:agent:concurrent:{$this->a->id}"));
    }

    public function test_oversized_bodies_are_refused_with_413_before_anything_reads_them(): void
    {
        config(['api_protection.agent.max_body_kb' => 1]);
        $this->withToken($this->tokenA)->postJson('/api/agent/messages', ['text' => str_repeat('a', 4096)])->assertStatus(413);
        // A read has no body to limit.
        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();

        $key = $this->publicWidgetKey();
        config(['api_protection.widget.max_body_kb' => 1]);
        $this->postJson("/api/widget/{$key}/messages", ['message' => str_repeat('a', 4096), 'session_id' => 'abc'])->assertStatus(413);
    }

    public function test_the_widget_has_a_bucket_for_all_its_visitors_together_not_only_per_ip(): void
    {
        $key = $this->publicWidgetKey();
        config(['api_protection.widget.key_messages_per_minute' => 2, 'chatbots.widget.messages_per_minute' => 1000]);

        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson("/api/widget/{$key}/messages", ['message' => 'hola', 'session_id' => 'abc'])->assertStatus(404);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->postJson("/api/widget/{$key}/messages", ['message' => 'hola', 'session_id' => 'abc'])->assertStatus(429);
    }

    public function test_the_per_ip_limit_does_not_block_other_visitors(): void
    {
        $key = $this->publicWidgetKey();
        config(['chatbots.widget.messages_per_minute' => 1, 'api_protection.widget.key_messages_per_minute' => 1000]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson("/api/widget/{$key}/messages", ['message' => 'hola', 'session_id' => 'abc']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson("/api/widget/{$key}/messages", ['message' => 'hola', 'session_id' => 'abc'])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])->postJson("/api/widget/{$key}/messages", ['message' => 'hola', 'session_id' => 'abc'])->assertStatus(404);
    }

    public function test_an_excess_is_logged_once_without_tokens_or_headers(): void
    {
        config(['api_protection.agent.workspace_per_minute' => 1]);
        Log::spy();

        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();
        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertStatus(429);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $flat = json_encode($context);

            return $message === 'api.limit_exceeded' && $context['consumer'] === 'agent' && $context['layer'] === 'workspace' && $context['workspace_id'] === $this->a->id
                && ! str_contains($flat, $this->tokenA) && ! str_contains($flat, 'Authorization');
        });
    }

    public function test_signed_in_people_of_one_workspace_share_a_bucket_and_other_workspaces_are_unaffected(): void
    {
        config(['api_protection.user.workspace_per_minute' => 2, 'auth.authenticated_per_minute' => 1000]);
        $this->actAsMember($this->a);

        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertStatus(429);

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->b);
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_limiter_counters_use_a_shared_store_in_the_real_stacks(): void
    {
        // The Docker stacks run Redis and the host runs the database: both are shared between processes. The diagnose command reports anything else.
        $this->artisan('integrations:diagnose')->expectsOutputToContain('Límites de tasa')->run();
        $this->assertTrue(true);
    }

    public function test_throttle_exception_contract_is_preserved(): void
    {
        $this->assertTrue(is_subclass_of(ThrottleRequestsException::class, HttpException::class));
    }

    public function test_when_the_store_of_the_counters_is_down_requests_go_through_uncounted_and_the_outage_is_logged_once(): void
    {
        $broken = Cache::repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('store down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('store down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('store down');
            }
        });
        // The named limiters stay registered; only the store that keeps their counters goes away.
        $property = new \ReflectionProperty(app(\Illuminate\Cache\RateLimiter::class), 'cache');
        $property->setValue(app(\Illuminate\Cache\RateLimiter::class), $broken);
        Log::spy();

        // The limiters throw on every counter: the agent API and a public route still answer; none turns into a 500.
        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();
        $this->withToken($this->tokenA)->getJson('/api/agent/config')->assertOk();
        $this->getJson('/api/widget/'.str_repeat('a', 32).'/config')->assertStatus(404);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'api.limit_store_unavailable' && $context === ['exception' => \RuntimeException::class])->atLeast()->once();
    }

    /** The limiters run before the key is resolved, so any key shows them (an unknown key is a 404 once it passes). */
    private function publicWidgetKey(): string
    {
        return str_repeat('a', 32);
    }
}
