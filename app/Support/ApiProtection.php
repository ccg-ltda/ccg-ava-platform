<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The few facts every protection layer needs, in one place: who the consumer of a request is, how an excess is logged, and how long to wait. It never reads a header, a token
 * or a body into a log line: a refused request is described by the route PATTERN (not the URL, which may carry a public key), the kind of consumer and the Workspace id.
 */
class ApiProtection
{
    /** @return 'agent'|'widget'|'booking'|'provider'|'user'|'anonymous' */
    public static function consumer(Request $request): string
    {
        return match (true) {
            $request->is('api/agent/*') => 'agent',
            $request->is('api/widget/*') => 'widget',
            $request->is('api/booking/*', 'book/*') => 'booking',
            $request->is('api/webhooks/*') => 'provider',
            $request->user() !== null => 'user',
            default => 'anonymous',
        };
    }

    /** The store of the counters did not answer: logged at most once a minute per process, class only (an exception message may carry an address or a password). */
    public static function storeUnavailable(\Throwable $e): void
    {
        static $last = 0;

        if (time() - $last < 60) {
            return;
        }

        $last = time();
        Log::error('api.limit_store_unavailable', ['exception' => $e::class]);
    }

    public static function logExceeded(Request $request, string $layer, ?int $retryAfter = null): void
    {
        $request->attributes->set('api_limit_logged', true);

        if (! config('api_protection.log_exceeded')) {
            return;
        }

        Log::warning('api.limit_exceeded', [
            'layer' => $layer,
            'consumer' => self::consumer($request),
            'route' => $request->route()?->getName() ?? $request->route()?->uri() ?? 'unknown',
            'method' => $request->method(),
            'workspace_id' => $request->attributes->get('workspace')?->id,
            'ip' => $request->ip(),
            'retry_after' => $retryAfter,
        ]);
    }
}
