<?php

namespace App\Support;

/**
 * Whether the application can really deliver an email. `log` and `array` write the message to a file or to memory, and `null`
 * drops it: none reaches a person, so outside local/testing they count as "not configured" and the account-recovery flow
 * says so instead of pretending a link was sent. Nothing here contacts a mail server.
 */
class MailAvailability
{
    private const NON_DELIVERING = ['log', 'array', 'null'];

    /** The mailer is a real transport with a sender address that is not the framework placeholder. */
    public static function deliversRealMail(): bool
    {
        $default = (string) config('mail.default');
        $mailer = config("mail.mailers.{$default}");

        if ($mailer === null || in_array($default, self::NON_DELIVERING, true)) {
            return false;
        }

        // `failover` and `roundrobin` are real only if their members are.
        if (in_array($mailer['transport'] ?? null, ['failover', 'roundrobin'], true)) {
            return collect($mailer['mailers'] ?? [])->contains(fn ($name) => ! in_array($name, self::NON_DELIVERING, true));
        }

        $from = strtolower((string) config('mail.from.address'));

        return $from !== '' && ! str_ends_with($from, '@example.com') && ! str_ends_with($from, '@example.org');
    }

    /** The recovery flow may run: real mail, or a local/testing environment where the message is read from the log or fake. */
    public static function usable(): bool
    {
        return self::deliversRealMail() || app()->environment(['local', 'testing']);
    }
}
