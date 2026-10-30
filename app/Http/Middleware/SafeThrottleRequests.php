<?php

namespace App\Http\Middleware;

use App\Support\ApiProtection;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Throwable;

/**
 * Laravel's `throttle`, with one difference: when the store that keeps the counters is not reachable (Redis down, the database not answering) the request is NOT turned into a 500.
 * It goes through WITHOUT being counted (fail open) and the outage is logged (`api.limit_store_unavailable`, once a minute per process). The reason: the counters are a defence in
 * depth, not the authorization of the request, and refusing every visitor because the cache is down would turn a cache outage into a total one; the signature, token, permission
 * and plan checks of each route do not depend on this store. A real excess (429) is never swallowed. Registered as the `throttle.safe` alias in bootstrap/app.php and used by every route of routes/api.php (agent API, public widget and booking, provider and inbound webhooks): the surfaces open to the internet. The signed-in web routes keep Laravel's own `throttle` (their sessions live in the same store: if it is down the web application is down anyway).
 */
class SafeThrottleRequests extends ThrottleRequests
{
    protected function handleRequest($request, Closure $next, array $limits)
    {
        try {
            foreach ($limits as $limit) {
                if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                    throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
                }
            }

            foreach ($limits as $limit) {
                if (! $limit->afterCallback) {
                    $this->limiter->hit($limit->key, $limit->decaySeconds);
                }
            }
        } catch (ThrottleRequestsException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiProtection::storeUnavailable($e);

            return $next($request);
        }

        $response = $next($request);

        try {
            foreach ($limits as $limit) {
                if ($limit->afterCallback && ($limit->afterCallback)($response)) {
                    $this->limiter->hit($limit->key, $limit->decaySeconds);
                }

                $response = $this->addHeaders($response, $limit->maxAttempts, $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts));
            }
        } catch (Throwable $e) {
            ApiProtection::storeUnavailable($e);
        }

        return $response;
    }
}
