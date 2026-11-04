<?php

namespace App\Integrations;

/**
 * Verification of a WooCommerce webhook delivery. WooCommerce signs the RAW body with the webhook's secret: `X-WC-Webhook-Signature` is the base64 of HMAC-SHA256(body, secret)
 * (secondary sources; confirm against a real store, see docs/integracion-woocommerce.md). The compare is constant-time and works on the exact bytes received, never on re-encoded JSON.
 * `X-WC-Webhook-Delivery-ID` identifies a delivery and is the natural idempotency key of whoever processes it.
 */
final class WooCommerceWebhook
{
    public static function signature(string $rawBody, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }

    public static function verify(string $rawBody, ?string $header, string $secret): bool
    {
        return $secret !== '' && is_string($header) && $header !== '' && hash_equals(self::signature($rawBody, $secret), trim($header));
    }
}
