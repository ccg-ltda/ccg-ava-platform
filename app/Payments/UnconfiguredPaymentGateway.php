<?php

namespace App\Payments;

use App\Models\SignupRequest;
use LogicException;

/** The gateway while no payment provider is connected: it never pretends to charge. */
class UnconfiguredPaymentGateway implements PaymentGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function checkoutUrl(SignupRequest $request): string
    {
        throw new LogicException('No hay un proveedor de pagos configurado.');
    }
}
