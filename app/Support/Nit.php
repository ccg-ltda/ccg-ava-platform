<?php

namespace App\Support;

/**
 * Colombian tax id (NIT). A NIT is 8 to 10 digits followed by a check digit (DV) computed by the DIAN algorithm (modulo 11 with fixed weights).
 * Accepts "900.123.456-7", "900123456-7" or "9001234567" (the last digit is then the DV). The check digit is required: a bare number is
 * ambiguous (it could be a body or a body plus DV) and nothing is guessed, so it is rejected, and so is a wrong DV.
 */
class Nit
{
    private const WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    /** The check digit of a NIT body (digits only). */
    public static function checkDigit(string $body): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($body)) as $i => $digit) {
            $sum += (int) $digit * self::WEIGHTS[$i];
        }

        $rest = $sum % 11;

        return $rest > 1 ? 11 - $rest : $rest;
    }

    /**
     * The canonical form ("900123456-7"), or null when it is not a valid NIT.
     */
    public static function normalize(string $input): ?string
    {
        $clean = preg_replace('/[\s.]/', '', trim($input)) ?? '';

        if (preg_match('/^(\d{8,10})-(\d)$/', $clean, $m)) {
            return (int) $m[2] === self::checkDigit($m[1]) ? "{$m[1]}-{$m[2]}" : null;
        }

        if (preg_match('/^\d{9,11}$/', $clean)) {
            // Digits only: the last digit is the DV and must satisfy the algorithm.
            $body = substr($clean, 0, -1);
            $dv = (int) substr($clean, -1);

            if (strlen($body) >= 8 && strlen($body) <= 10 && $dv === self::checkDigit($body)) {
                return "{$body}-{$dv}";
            }
        }

        return null;
    }

    /**
     * The tax id as it is stored for ANY country (optional): null when blank, otherwise trimmed with inner spaces collapsed. A well-formed Colombian NIT
     * keeps its canonical form ("900123456-7"); anything else (letters, dots, hyphens, slashes: "GB 123 4567 89", "RFC-ABC010101AB1") is kept as typed.
     * No check digit and no country rule is imposed: the id is informative, never a login or a payment condition.
     */
    public static function identifier(?string $input): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $input) ?? '');

        return $clean === '' ? null : (self::normalize($clean) ?? $clean);
    }

    /** The base number of a canonical NIT (without DV). */
    public static function body(string $normalized): string
    {
        return explode('-', $normalized)[0];
    }
}
