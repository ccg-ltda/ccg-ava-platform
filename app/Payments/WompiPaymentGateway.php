<?php

namespace App\Payments;

use App\Models\SignupRequest;
use LogicException;

/**
 * The hosted Web Checkout of Wompi. The server builds the checkout URL: amount, currency and reference come from the stored sign-up request (never the
 * browser) and the integrity signature is SHA-256(reference . amount . currency . integrity secret). Card data never touches Ava and the secrets never
 * leave the server.
 */
class WompiPaymentGateway implements PaymentGateway
{
    public function __construct(private readonly WompiConfig $config) {}

    public function isConfigured(): bool
    {
        return $this->config->usable();
    }

    public function checkoutUrl(SignupRequest $request): string
    {
        if (! $this->isConfigured()) {
            throw new LogicException('Wompi no está configurado.');
        }

        if ($request->price_cents === null || $request->price_cents <= 0 || $request->currency !== 'COP') {
            throw new LogicException('La contratación no tiene un importe en COP para cobrar.');
        }

        $amount = (int) $request->price_cents; // minor units (centavos), exactly Wompi's amount-in-cents: no multiplication here

        return $this->config->checkoutBase().'?'.http_build_query([
            'public-key' => $this->config->publicKey(),
            'currency' => 'COP',
            'amount-in-cents' => $amount,
            'reference' => $request->public_id,
            'signature:integrity' => $this->config->integritySignature($request->public_id, $amount, 'COP'),
            'redirect-url' => route('signup.index'),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
