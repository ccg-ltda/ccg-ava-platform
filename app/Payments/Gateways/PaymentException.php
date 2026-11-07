<?php

namespace App\Payments\Gateways;

use RuntimeException;

/** A refusal with a message safe to show the customer or staff (never provider text, never a secret). */
class PaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'refused')
    {
        parent::__construct($message);
    }
}
