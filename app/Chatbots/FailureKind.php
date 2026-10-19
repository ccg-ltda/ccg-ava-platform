<?php

namespace App\Chatbots;

/**
 * What kind of failure a fixed execution code stands for, so an alert and a person reading it can tell a hiccup from a broken setup.
 * Transient and timeout failures are retried (`config('n8n.tries')`); the rest are final on the first occurrence. Unknown codes are `definitive`.
 */
final class FailureKind
{
    public const TRANSIENT = 'transient';

    public const TIMEOUT = 'timeout';

    public const INVALID_RESPONSE = 'invalid_response';

    public const AUTH = 'auth';

    public const CONFIGURATION = 'configuration';

    public const QUEUE = 'queue';

    public const DEFINITIVE = 'definitive';

    private const BY_CODE = [
        'unreachable' => self::TRANSIENT,
        'request_failed' => self::TRANSIENT,
        'n8n_unavailable' => self::TRANSIENT,
        'timeout' => self::TIMEOUT,
        'invalid_response' => self::INVALID_RESPONSE,
        'n8n_auth' => self::AUTH,
        'not_configured' => self::CONFIGURATION,
        'insecure_target' => self::CONFIGURATION,
        'unsafe_target' => self::CONFIGURATION,
        'stale' => self::QUEUE,
        'crashed' => self::QUEUE,
        'internal_error' => self::QUEUE,
    ];

    public static function of(string $code): string
    {
        return self::BY_CODE[$code] ?? self::DEFINITIVE;
    }
}
