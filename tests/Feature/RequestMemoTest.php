<?php

namespace Tests\Feature;

use App\Support\RequestMemo;
use Illuminate\Http\Request;
use ReflectionProperty;
use Tests\TestCase;

/** What several parts of a page ask for is worked out once per READ-ONLY web request, and never for writes, jobs or the console (where a change must be seen at once). */
class RequestMemoTest extends TestCase
{
    private function asWeb(string $method): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $this->app->instance('request', Request::create('/x', $method));
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, true);

        parent::tearDown();
    }

    public function test_a_read_only_request_resolves_once_and_each_request_starts_clean(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return 'value-'.$calls;
        };

        $this->asWeb('GET');
        $this->assertSame('value-1', RequestMemo::remember('k', $resolve));
        $this->assertSame('value-1', RequestMemo::remember('k', $resolve));
        $this->assertSame(1, $calls);
        $this->assertSame('other', RequestMemo::remember('another-key', fn () => 'other'), 'keys do not mix (they carry the tenant)');

        $this->asWeb('GET'); // the next request
        $this->assertSame('value-2', RequestMemo::remember('k', $resolve));
    }

    public function test_writes_and_the_console_always_resolve_again(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls) {
            return ++$calls;
        };

        $this->asWeb('POST');
        RequestMemo::remember('k', $resolve);
        RequestMemo::remember('k', $resolve);
        $this->assertSame(2, $calls, 'a POST may change what it reads next');

        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, true);
        $this->app->instance('request', Request::create('/x', 'GET'));
        RequestMemo::remember('k', $resolve);
        RequestMemo::remember('k', $resolve);
        $this->assertSame(4, $calls, 'a job or a command is long-lived: nothing is remembered');
    }
}
