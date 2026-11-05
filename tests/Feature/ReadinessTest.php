<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_ready_when_the_database_answers_and_says_nothing_else(): void
    {
        $this->getJson('/api/ready')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_it_needs_no_session_and_sets_no_cookie(): void
    {
        $response = $this->getJson('/api/ready')->assertOk();

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_it_is_unavailable_when_redis_is_used_and_does_not_answer_without_saying_which_dependency(): void
    {
        config(['queue.default' => 'redis']);
        $redis = \Mockery::mock();
        $redis->shouldReceive('ping')->andThrow(new \RuntimeException('connection refused to redis.internal:6379'));
        Redis::shouldReceive('connection')->andReturn($redis);

        $response = $this->getJson('/api/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);

        $this->assertStringNotContainsString('redis', strtolower($response->getContent()));
        $this->assertStringNotContainsString('6379', $response->getContent());
    }

    public function test_redis_is_not_checked_when_nothing_uses_it(): void
    {
        config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
        Redis::shouldReceive('connection')->never();

        $this->getJson('/api/ready')->assertOk();
    }

    public function test_it_is_unavailable_when_the_database_does_not_answer(): void
    {
        DB::shouldReceive('select')->andThrow(new \RuntimeException('could not connect to server'));

        $this->getJson('/api/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }
}
