<?php

namespace App\Booking;

use RuntimeException;

/**
 * What an external agenda can fail with, reduced to a fixed `code`. The provider's own text is never kept: it may carry
 * personal data or hints about credentials. `retryable` says whether trying again later can help.
 */
class ProviderException extends RuntimeException
{
    public const AUTH = 'auth';

    public const TIMEOUT = 'timeout';

    public const RATE_LIMITED = 'rate_limited';

    public const UNAVAILABLE = 'unavailable';

    public const CONFLICT = 'conflict';

    public const UNSUPPORTED = 'unsupported';

    public const INVALID = 'invalid';

    public const UNKNOWN = 'unknown';

    private const MESSAGES = [
        self::AUTH => 'La agenda externa rechazó las credenciales del Workspace.',
        self::TIMEOUT => 'La agenda externa no respondió a tiempo.',
        self::RATE_LIMITED => 'La agenda externa está limitando las solicitudes. Inténtalo en unos minutos.',
        self::UNAVAILABLE => 'La agenda externa no está disponible en este momento.',
        self::CONFLICT => 'La agenda externa indica que ese horario ya no está libre.',
        self::UNSUPPORTED => 'La agenda externa conectada no ofrece esta operación en Ava.',
        self::INVALID => 'La agenda externa no aceptó los datos de la reserva.',
        self::UNKNOWN => 'La agenda externa respondió con un error inesperado.',
    ];

    public function __construct(public readonly string $codeName, public readonly bool $retryable = false)
    {
        parent::__construct(self::MESSAGES[$codeName] ?? self::MESSAGES[self::UNKNOWN]);
    }

    /**
     * Whether the request MAY have been processed although the answer was lost or broken (a timeout, a server error, an unknown
     * failure). Writing again after such a failure could duplicate the operation, so it is recorded as uncertain and verified first;
     * an authentication, validation, conflict or rate-limit refusal is certain: nothing was done.
     */
    public function uncertain(): bool
    {
        return in_array($this->codeName, [self::TIMEOUT, self::UNAVAILABLE, self::UNKNOWN], true);
    }

    public static function auth(): self
    {
        return new self(self::AUTH);
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, true);
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED, true);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, true);
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT);
    }

    public static function unsupported(): self
    {
        return new self(self::UNSUPPORTED);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }
}
