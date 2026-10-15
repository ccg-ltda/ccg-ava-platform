<?php

namespace App\Support;

/**
 * International phone numbers (ITU-T E.164) without assuming any country: the number must carry its own country calling code, so
 * "+573001112233" is valid and "3001112233" is not (which country it belongs to cannot be known). This checks the SHAPE of the number
 * (leading "+", a real country calling code, 7 to 15 digits in total, and the fixed 11-digit length of the +1 and +7 plans); it does not
 * know each country's numbering plan, so it can accept a well-formed number that nobody owns.
 *
 * Only NEW input is held to this rule. Historical numbers are never rewritten: `e164()` is a pure function of the original text and
 * is used to derive an additional value, not to replace what was stored.
 */
class PhoneNumber
{
    /**
     * Every assigned country calling code. The set is prefix-free, so the first prefix that matches is the code. Non-geographic codes
     * (800, 808, 870, 878, 881-883, 888, 979) are not personal numbers and are left out.
     *
     * @var list<string>
     */
    private const COUNTRY_CODES = [
        '1', '7',
        '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49',
        '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66',
        '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98',
        '211', '212', '213', '216', '218',
        '220', '221', '222', '223', '224', '225', '226', '227', '228', '229', '230', '231', '232', '233', '234', '235', '236', '237', '238', '239',
        '240', '241', '242', '243', '244', '245', '246', '247', '248', '249', '250', '251', '252', '253', '254', '255', '256', '257', '258',
        '260', '261', '262', '263', '264', '265', '266', '267', '268', '269',
        '290', '291', '297', '298', '299',
        '350', '351', '352', '353', '354', '355', '356', '357', '358', '359',
        '370', '371', '372', '373', '374', '375', '376', '377', '378', '380', '381', '382', '383', '385', '386', '387', '389',
        '420', '421', '423',
        '500', '501', '502', '503', '504', '505', '506', '507', '508', '509',
        '590', '591', '592', '593', '594', '595', '596', '597', '598', '599',
        '670', '672', '673', '674', '675', '676', '677', '678', '679', '680', '681', '682', '683', '685', '686', '687', '688', '689', '690', '691', '692',
        '850', '852', '853', '855', '856', '880', '886',
        '960', '961', '962', '963', '964', '965', '966', '967', '968',
        '970', '971', '972', '973', '974', '975', '976', '977',
        '992', '993', '994', '995', '996', '998',
    ];

    public const EXAMPLE = '+573001112233';

    /**
     * "+573001112233" for a well-formed international number, null otherwise. Spaces, dots, dashes and parentheses are allowed as separators.
     * `$allowDoubleZero` also reads the "00" international call prefix; it exists for the controlled review of historical numbers, never for new input.
     */
    public static function e164(?string $raw, bool $allowDoubleZero = false): ?string
    {
        $digits = self::international($raw, $allowDoubleZero);

        return $digits === null ? null : '+'.$digits;
    }

    /** The reason a NEW number is refused, in words for the person who typed it; null when it is valid (or empty). */
    public static function error(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (! str_starts_with($raw, '+')) {
            return 'Escribe el teléfono en formato internacional: empieza por + y el prefijo del país, por ejemplo '.self::EXAMPLE.'.';
        }

        if (preg_match('/^\+[\d\s().\-]+$/', $raw) !== 1) {
            return 'El teléfono solo puede tener números y los separadores espacio, punto, guion o paréntesis, por ejemplo '.self::EXAMPLE.'.';
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return 'El teléfono debe tener entre 7 y 15 dígitos en total, incluido el prefijo del país, por ejemplo '.self::EXAMPLE.'.';
        }

        if (self::countryCode($digits) === null) {
            return 'El prefijo de país del teléfono no es válido; por ejemplo +57 para Colombia: '.self::EXAMPLE.'.';
        }

        return self::international($raw, false) === null ? 'El teléfono no tiene un formato internacional válido, por ejemplo '.self::EXAMPLE.'.' : null;
    }

    public static function isValid(?string $raw): bool
    {
        return self::e164($raw) !== null;
    }

    /** Digits only of a valid international number ("573001112233"), the form Ava compares. */
    public static function digits(?string $e164): ?string
    {
        return $e164 === null ? null : ltrim($e164, '+');
    }

    private static function international(?string $raw, bool $allowDoubleZero): ?string
    {
        $raw = trim((string) $raw);

        $rest = match (true) {
            str_starts_with($raw, '+') => substr($raw, 1),
            $allowDoubleZero && str_starts_with($raw, '00') => substr($raw, 2),
            default => null,
        };

        if ($rest === null || preg_match('/^[\d\s().\-]+$/', $rest) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $rest);
        $length = strlen($digits);

        if ($length < 7 || $length > 15 || ($code = self::countryCode($digits)) === null) {
            return null;
        }

        // The North American plan (+1) and Russia/Kazakhstan (+7) are closed plans of exactly 10 national digits.
        return in_array($code, ['1', '7'], true) && $length !== 11 ? null : $digits;
    }

    private static function countryCode(string $digits): ?string
    {
        foreach ([1, 2, 3] as $length) {
            if (in_array($prefix = substr($digits, 0, $length), self::COUNTRY_CODES, true)) {
                return $prefix;
            }
        }

        return null;
    }
}
