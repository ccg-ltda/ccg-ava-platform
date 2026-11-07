<?php

namespace App\Payments\Gateways;

/** The provider's answer to a refund/void request. `externalId` is the provider's identifier of the void, kept apart from the payment's. */
final class RefundResult
{
    public function __construct(public readonly bool $accepted, public readonly ?string $externalId = null, public readonly ?string $code = null) {}
}
