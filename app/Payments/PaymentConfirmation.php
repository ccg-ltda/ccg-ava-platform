<?php

namespace App\Payments;

/**
 * What a payment provider's VERIFIED notification tells Ava: which sign-up (`reference`, the request's public id), the provider's own transaction id,
 * and what was really charged. SignupService compares the amount and currency with the price the customer saw before it provisions anything.
 */
final class PaymentConfirmation
{
    public function __construct(
        public readonly string $reference,
        public readonly string $transactionId,
        public readonly int $amountCents,
        public readonly string $currency,
    ) {}
}
