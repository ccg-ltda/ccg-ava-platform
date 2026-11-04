<?php

namespace App\Integrations;

use App\Services\PlatformMeta;
use Illuminate\Http\Client\ConnectionException;

/** Small facts about Meta's Graph API shared by the Instagram and Messenger integrations. */
final class MetaGraph
{
    /** cURL errors that happen before the request is sent (name, connection, TLS): nothing could have gone out, so trying again later is safe. */
    public static function neverReached(ConnectionException $e): bool
    {
        return preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1 && in_array((int) $m[1], [5, 6, 7, 35, 51, 58, 59, 60, 77, 83, 90, 91], true);
    }

    public static function version(): string
    {
        return (string) (PlatformMeta::get('graph_version') ?? config('integrations.meta.graph_version'));
    }
}
