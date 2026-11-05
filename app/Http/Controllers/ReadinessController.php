<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness, for a load balancer or an external monitor: can this instance really serve a request? `/up` (Laravel's own) only says the PHP process is alive, and it must stay
 * that way for the container healthcheck (restarting `app` would not fix a database or Redis outage). This one checks what every page needs: PostgreSQL and, when the cache,
 * the sessions or the queue use it, Redis. It answers 200 {"status":"ok"} or 503 {"status":"unavailable"} and nothing else: which dependency failed goes to the log (class only),
 * never to a caller who is not authenticated. No session, no cookie, no secret.
 */
class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $ready = $this->check('database', fn () => DB::select('select 1'));

        if ($this->usesRedis()) {
            $ready = $this->check('redis', fn () => Redis::connection()->ping()) && $ready;
        }

        return response()->json(['status' => $ready ? 'ok' : 'unavailable'], $ready ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [config('cache.default'), config('session.driver'), config('queue.default')], true);
    }

    private function check(string $name, callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            Log::error('readiness_check_failed', ['dependency' => $name, 'exception' => $e::class]);

            return false;
        }
    }
}
