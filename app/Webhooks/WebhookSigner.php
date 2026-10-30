<?php

namespace App\Webhooks;

use App\Models\WebhookEndpoint;

/**
 * The signature of a delivery: HMAC-SHA256 over "{timestamp}.{body}" with the endpoint secret, hex encoded and prefixed `sha256=`. The timestamp (unix seconds) is sent
 * in `X-Ava-Timestamp` and changes on every attempt, so a receiver can reject an old capture (replay) while the body, and so the event id, stay the same.
 * During the grace period after a rotation a second header carries the signature made with the previous secret.
 */
class WebhookSigner
{
    public const SCHEME = 'sha256=';

    public function sign(string $secret, int $timestamp, string $body): string
    {
        return self::SCHEME.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array<string, string> only the signature headers */
    public function headers(WebhookEndpoint $endpoint, int $timestamp, string $body): array
    {
        $headers = [
            'X-Ava-Timestamp' => (string) $timestamp,
            'X-Ava-Signature' => $this->sign($endpoint->secret, $timestamp, $body),
        ];

        if ($endpoint->previousSecretIsValid()) {
            $headers['X-Ava-Signature-Previous'] = $this->sign($endpoint->previous_secret, $timestamp, $body);
        }

        return $headers;
    }

    /** What a receiver does (kept here so the documentation example and the tests are the same code). Constant-time comparison and a tolerance window. */
    public function verify(string $secret, string $timestampHeader, string $signatureHeader, string $body, int $toleranceSeconds = 300): bool
    {
        if (! ctype_digit($timestampHeader) || abs(now()->timestamp - (int) $timestampHeader) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($secret, (int) $timestampHeader, $body), $signatureHeader);
    }
}
