<?php

namespace App\Support;

use Closure;

/**
 * Remembers an answer for the length of ONE read-only web request, so a value several parts of a page ask for (the administrative Workspace for the support panel, the modules of the
 * Workspace) is worked out once instead of once per caller. It never remembers anything for writes (a POST may change the very thing it reads next), for queue jobs or for the console
 * (long-lived processes where "this request" has no meaning), so a change is always seen. Keys must contain whatever tenant they depend on.
 */
final class RequestMemo
{
    /** @template T @param  Closure(): T  $resolve @return T */
    public static function remember(string $key, Closure $resolve): mixed
    {
        $request = app()->runningInConsole() || ! app()->bound('request') ? null : request();

        if ($request === null || ! $request->isMethodSafe()) {
            return $resolve();
        }

        $key = 'memo.'.$key;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $resolve());
        }

        return $request->attributes->get($key);
    }
}
