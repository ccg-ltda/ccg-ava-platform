<?php

namespace App\Payments\Gateways;

/**
 * A provider's answer about ONE transaction, already authenticated by the adapter (a signed notification re-read through the API, or a server-to-server query).
 * PaymentService compares amount, currency and reference with the transaction before it believes an `Approved`.
 */
final class GatewayResult
{
    public function __construct(
        public readonly GatewayOutcome $outcome,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $externalId = null,
        public readonly ?string $authorizationCode = null,
        /** Normalized code (never provider text). */
        public readonly ?string $code = null,
        public readonly ?int $httpStatus = null,
        public readonly ?int $durationMs = null,
    ) {}

    public static function unknown(string $code, ?int $httpStatus = null, ?int $durationMs = null): self
    {
        return new self(GatewayOutcome::Unknown, code: $code, httpStatus: $httpStatus, durationMs: $durationMs);
    }
}
