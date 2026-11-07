<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;

/** The transaction a checkout produced (or reused) and how to send the customer to the provider. */
final class CheckoutResult
{
    public function __construct(public readonly PaymentTransaction $transaction, public readonly GatewayCheckout $checkout, public readonly bool $created) {}
}
