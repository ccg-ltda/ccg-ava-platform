<?php

namespace App\Privacy;

/** Masks identifiers of final contacts when someone outside their Workspace has to see that a record exists but not who it is. */
final class Mask
{
    /** "573001112233" -> "••••2233"; short values are fully hidden. */
    public static function identifier(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return '••••'.(mb_strlen($value) > 6 ? mb_substr($value, -4) : '');
    }

    /** "ana@example.com" -> "a•••@example.com" */
    public static function email(?string $email): ?string
    {
        if (! filled($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
