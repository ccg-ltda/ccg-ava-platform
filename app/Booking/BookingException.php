<?php

namespace App\Booking;

use RuntimeException;

/**
 * A booking operation Ava refuses, with a fixed `reason` code the callers (the admin pages, the public booking page, Ava
 * Chat) can act on and a message that is safe to show: never a provider's text, a credential or personal data.
 */
class BookingException extends RuntimeException
{
    public const SLOT_UNAVAILABLE = 'slot_unavailable';

    public const OUTSIDE_POLICY = 'outside_policy';

    public const INVALID = 'invalid';

    public const NOT_FOUND = 'not_found';

    public const STATE = 'state';

    public const UNSUPPORTED = 'unsupported';

    public const PROVIDER = 'provider';

    /** A write to an external agenda whose outcome is unknown: it may already exist there. Never repeated before somebody verifies it. */
    public const UNCERTAIN = 'uncertain';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $appointmentId = null)
    {
        parent::__construct($message);
    }

    public static function uncertain(int $appointmentId): self
    {
        return new self(self::UNCERTAIN, 'No pudimos confirmar si la agenda externa recibió la solicitud. No la repitas: el negocio la verificará.', $appointmentId);
    }

    public static function slotUnavailable(): self
    {
        return new self(self::SLOT_UNAVAILABLE, 'Ese horario ya no está disponible. Elige otro.');
    }

    public static function outsidePolicy(string $message): self
    {
        return new self(self::OUTSIDE_POLICY, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }

    public static function state(string $message): self
    {
        return new self(self::STATE, $message);
    }

    public static function unsupported(string $message): self
    {
        return new self(self::UNSUPPORTED, $message);
    }

    /** HTTP status the public and agent APIs answer with. */
    public function status(): int
    {
        return match ($this->reason) {
            self::SLOT_UNAVAILABLE, self::STATE, self::UNCERTAIN => 409,
            self::NOT_FOUND => 404,
            self::UNSUPPORTED => 422,
            self::PROVIDER => 502,
            default => 422,
        };
    }
}
