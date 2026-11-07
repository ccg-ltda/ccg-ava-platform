<?php

namespace App\Payments\Gateways;

use App\Models\Integration;
use App\Models\PaymentTransaction;

/** Implemented only by adapters whose provider really supports voiding/refunding (`GatewayCapabilities::$refund`). */
interface RefundsPayments
{
    /** Must use the provider's own identifier of the payment (`external_id`), never Ava's reference. */
    public function refund(PaymentTransaction $transaction, Integration $integration, int $amountMinor): RefundResult;
}
