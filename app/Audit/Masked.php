<?php

namespace App\Audit;

/**
 * A value that must never be stored or shown (credentials, free-form bodies) but whose CHANGE must still be audited.
 * Two masked values are equal when their fingerprints are; `display` is the harmless text shown instead of the value
 * (null = "valor oculto").
 */
final class Masked
{
    public function __construct(public readonly string $fingerprint, public readonly ?string $display = null) {}

    public static function of(mixed $value, ?string $display = null): ?self
    {
        return blank($value) ? null : new self(hash('sha256', is_scalar($value) ? (string) $value : json_encode($value)), $display);
    }
}
