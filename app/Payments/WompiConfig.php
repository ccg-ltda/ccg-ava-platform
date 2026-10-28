<?php

namespace App\Payments;

use App\Models\PaymentProviderSetting;

/**
 * The Wompi settings, validated: a half-configured or mixed setup (a sandbox key in production) is "not usable", never a silent charge attempt.
 * Source of truth: the ACTIVE environment row of `payment_provider_settings` (managed from the platform, secrets encrypted). Only when no row is active
 * do the WOMPI_* environment variables apply (compatible fallback). The two sources are never mixed field by field.
 */
class WompiConfig
{
    private ?PaymentProviderSetting $row = null;

    private bool $resolved = false;

    private function row(): ?PaymentProviderSetting
    {
        if (! $this->resolved) {
            $this->row = app(PaymentSettings::class)->active();
            $this->resolved = true;
        }

        return $this->row;
    }

    /** Forget the cached row (after saving settings in the same process). */
    public function refresh(): void
    {
        $this->resolved = false;
        $this->row = null;
    }

    public function source(): string
    {
        return $this->row() ? 'database' : 'environment';
    }

    public function environment(): string
    {
        if ($row = $this->row()) {
            return $row->environment === 'production' ? 'production' : 'sandbox';
        }

        return config('wompi.environment') === 'production' ? 'production' : 'sandbox';
    }

    /** The `environment` value Wompi puts in its events. */
    public function eventEnvironment(): string
    {
        return $this->environment() === 'production' ? 'prod' : 'test';
    }

    public function publicKey(): string
    {
        return (string) ($this->row() ? $this->row()->public_key : config('wompi.public_key'));
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
        return (string) ($this->row() ? $this->row()->events_secret : config('wompi.events_secret'));
    }

    public function integritySecret(): string
    {
        return (string) ($this->row() ? $this->row()->integrity_secret : config('wompi.integrity_secret'));
    }

    public function usable(): bool
    {
        $prefix = $this->environment() === 'production' ? 'pub_prod_' : 'pub_test_';

        return str_starts_with($this->publicKey(), $prefix) && filled($this->integritySecret()) && filled($this->eventsSecret());
    }

    public function integritySignature(string $reference, int $amountCents, string $currency): string
    {
        return hash('sha256', $reference.$amountCents.$currency.$this->integritySecret());
    }
}
