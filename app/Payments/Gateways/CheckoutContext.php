<?php

namespace App\Payments\Gateways;

/** What an adapter needs to build a session, always computed by Ava on the server (never typed by the browser). */
final class CheckoutContext
{
    public function __construct(
        public readonly string $returnUrl,
        public readonly string $cancelUrl,
        public readonly ?string $notificationUrl,
        public readonly string $description,
        public readonly string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $customerPhone,
    ) {}
}
