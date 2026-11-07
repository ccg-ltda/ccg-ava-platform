<?php

namespace App\Payments\Gateways;

use App\Models\Integration;

/**
 * The opaque token in the events URL of ONE Wompi connection: `{integration id}.{HMAC}`. The HMAC is keyed with the application key over the id and the connection's own random
 * salt, so it can neither be guessed nor computed from the id, and rotating the salt revokes the old address. It only NAMES the connection: no event is believed because of it
 * (the checksum with the events secret and a read of the transaction from Wompi's API decide that). Nothing is stored beyond the salt, which is useless without the key.
 */
final class WompiEventToken
{
    public static function for(Integration $integration): ?string
    {
        $salt = $integration->config['events_salt'] ?? null;

        return filled($salt) ? $integration->id.'.'.self::mac($integration->id, (string) $salt) : null;
    }

    public static function resolve(string $token): ?Integration
    {
        if (preg_match('/^(\d{1,12})\.([a-f0-9]{40})$/', $token, $match) !== 1) {
            return null;
        }

        $integration = Integration::where('type', 'wompi')->find((int) $match[1]);
        $salt = $integration?->config['events_salt'] ?? null;

        return $integration && filled($salt) && hash_equals(self::mac($integration->id, (string) $salt), $match[2]) ? $integration : null;
    }

    private static function mac(int $id, string $salt): string
    {
        return substr(hash_hmac('sha256', "wompi-workspace-events|{$id}|{$salt}", (string) config('app.key')), 0, 40);
    }
}
