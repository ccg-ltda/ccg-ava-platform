<?php

namespace App\Payments\Gateways;

/** What a provider REALLY supports, as the adapter declares it. Ava never offers (or simulates) what is false here. */
final class GatewayCapabilities
{
    public function __construct(
        /** The result of a payment can be asked to the provider from the server. */
        public readonly bool $query = true,
        /** The provider sends notifications Ava receives (webhook). */
        public readonly bool $notifications = false,
        public readonly bool $refund = false,
        public readonly bool $partialRefund = false,
    ) {}
}
