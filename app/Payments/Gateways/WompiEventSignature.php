<?php

namespace App\Payments\Gateways;

/**
 * The authenticity check of a Wompi event, per Wompi's documentation (docs.wompi.co, «Eventos»): SHA-256 of the values of the fields named in `signature.properties` (read
 * from the event itself, they can change) taken from `data`, then the `timestamp`, then the events secret of the commerce. The result must equal `signature.checksum` and,
 * when present, the `X-Event-Checksum` header. Written here on purpose and not shared with the platform's own Wompi handler: the two integrations stay independent.
 */
final class WompiEventSignature
{
    /** @param  array<string, mixed>  $payload */
    public static function valid(array $payload, string $eventsSecret, ?string $headerChecksum = null): bool
    {
        $checksum = $payload['signature']['checksum'] ?? null;
        $properties = $payload['signature']['properties'] ?? null;
        $timestamp = $payload['timestamp'] ?? null;

        if ($eventsSecret === '' || ! is_string($checksum) || ! is_array($properties) || $properties === [] || ! is_scalar($timestamp)) {
            return false;
        }

        $concatenated = '';

        foreach ($properties as $path) {
            $value = is_string($path) ? data_get($payload['data'] ?? [], $path) : null;

            if (! is_scalar($value)) {
                return false;
            }

            $concatenated .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $expected = hash('sha256', $concatenated.$timestamp.$eventsSecret);

        return hash_equals($expected, strtolower($checksum)) && ($headerChecksum === null || hash_equals($expected, strtolower($headerChecksum)));
    }
}
