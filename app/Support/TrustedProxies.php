<?php

namespace App\Support;

/**
 * Which proxies Laravel trusts to tell it the client's IP (X-Forwarded-For) and the scheme (X-Forwarded-Proto). The rate limits by IP depend on it: trusting everyone ("*") lets any
 * visitor fake the IP and escape them. So in production a wildcard is NEVER honoured: it falls back to the private ranges (Docker network and VPC), the same default as
 * docker-compose.prod.yml. Outside production "*" still works, because local setups (a tunnel, a dev proxy) have no fixed address.
 * Read by bootstrap/app.php, before the configuration exists, which is why it takes plain values and not config().
 */
final class TrustedProxies
{
    public const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** @return list<string>|string the value for `trustProxies(at:)`: a list of IPs / CIDR ranges, or '*' (never in production). An empty list trusts none. */
    public static function resolve(?string $configured, string $environment): array|string
    {
        $value = trim((string) $configured);

        if ($value === '*') {
            return $environment === 'production' ? self::PRIVATE_RANGES : '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item) => $item !== ''));
    }
}
