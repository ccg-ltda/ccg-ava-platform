<?php

namespace App\Payments;

use App\Models\SignupRequest;

/**
 * The payment provider of the public sign-up. Ava has NO provider connected: the bound implementation is `UnconfiguredPaymentGateway`, which says so, and
 * nothing in the application can mark a payment as made. A real provider implements this interface (hosted checkout: card data never touches Ava) and
 * its VERIFIED notification (signature, reference, amount and currency) must end in `SignupService::confirmPayment` with a `PaymentConfirmation`.
 */
interface PaymentGateway
{
    /** Whether a provider is configured and can really charge. */
    public function isConfigured(): bool;

    /** The URL of the provider's hosted checkout for this request, carrying `$request->public_id` as the reference the notification returns. */
    public function checkoutUrl(SignupRequest $request): string;
}
