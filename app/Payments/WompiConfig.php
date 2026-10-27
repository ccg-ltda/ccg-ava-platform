<?php

namespace App\Payments;

/** The Wompi settings, validated: a half-configured or mixed setup (a sandbox key in production) is "not usable", never a silent charge attempt. */
class WompiConfig
{
    public function environment(): string
    {
        return config('wompi.environment') === 'production' ? 'production' : 'sandbox';
    }

    /** The `environment` value Wompi puts in its events. */
    public function eventEnvironment(): string
    {
        return $this->environment() === 'production' ? 'prod' : 'test';
    }

    public function publicKey(): string
    {
        return (string) config('wompi.public_key');
    }

    public function checkoutBase(): string
    {
        return (string) config('wompi.checkout_url');
    }

    public function apiUrl(): string
    {
        return rtrim((string) config('wompi.api_urls.'.$this->environment()), '/');
    }

    public function eventsSecret(): string
    {
        return (string) config('wompi.events_secret');
    }

    public function usable(): bool
    {
        $prefix = $this->environment() === 'production' ? 'pub_prod_' : 'pub_test_';

        return str_starts_with($this->publicKey(), $prefix) && filled(config('wompi.integrity_secret')) && filled(config('wompi.events_secret'));
    }

    public function integritySignature(string $reference, int $amountCents, string $currency): string
    {
        return hash('sha256', $reference.$amountCents.$currency.config('wompi.integrity_secret'));
    }
}
