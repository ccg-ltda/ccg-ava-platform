<?php

namespace App\Http\Middleware;

use App\Support\ApiProtection;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Workspace layers of the agent API (`/api/agent/*`), applied AFTER the token has been resolved (the Workspace comes from the token, never from the request):
 *  - a bucket for ALL the automations of the Workspace together;
 *  - a stricter bucket for state-changing calls (anything but GET), so a looping automation can not create rows without end;
 *  - a cap on requests being served at the same moment, so one tenant can not occupy every worker.
 * A refusal is the same 429 + `Retry-After` the per-token limiter gives. The counters live in the cache store of the rate limiter (shared between instances when it is Redis
 * or the database, see `integrations:diagnose`). Usage: `api.protect:agent`.
 */
class EnforceApiProtection
{
    private const SLOT_TTL = 120;

    public function handle(Request $request, Closure $next, string $surface = 'agent'): Response
    {
        $workspace = $request->attributes->get('workspace');

        if ($workspace === null) {
            return $next($request);
        }

        $config = (array) config("api_protection.{$surface}");
        $slot = "api-protect:{$surface}:concurrent:{$workspace->id}";

        // If the store of the counters is down the request goes through uncounted (fail open, logged): see SafeThrottleRequests for why. A real excess is never swallowed.
        try {
            $this->hit($request, "{$surface}-ws|{$workspace->id}", (int) $config['workspace_per_minute'], 'workspace');

            if (! $request->isMethodSafe()) {
                $this->hit($request, "{$surface}-write|{$workspace->id}", (int) $config['write_per_minute'], 'write');
            }

            Cache::add($slot, 0, self::SLOT_TTL);

            if ((int) Cache::increment($slot) > (int) $config['concurrent']) {
                Cache::decrement($slot);
                ApiProtection::logExceeded($request, 'concurrency', 1);

                throw new ThrottleRequestsException('Demasiadas solicitudes simultáneas.', null, ['Retry-After' => 1]);
            }
        } catch (ThrottleRequestsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            ApiProtection::storeUnavailable($e);

            return $next($request);
        }

        try {
            return $next($request);
        } finally {
            try {
                if ((int) Cache::get($slot, 0) > 0) {
                    Cache::decrement($slot);
                }
            } catch (\Throwable $e) {
                ApiProtection::storeUnavailable($e);
            }
        }
    }

    private function hit(Request $request, string $key, int $perMinute, string $layer): void
    {
        $limit = Limit::perMinute($perMinute)->by($key);

        if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
            $wait = RateLimiter::availableIn($limit->key);
            ApiProtection::logExceeded($request, $layer, $wait);

            throw new ThrottleRequestsException('Demasiadas solicitudes.', null, ['Retry-After' => $wait, 'X-RateLimit-Limit' => $limit->maxAttempts, 'X-RateLimit-Remaining' => 0]);
        }

        RateLimiter::hit($limit->key, $limit->decaySeconds);
    }
}
