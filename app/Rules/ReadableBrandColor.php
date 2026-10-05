<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A #RRGGBB color on which white text keeps a readable contrast (buttons and badges use white text). */
class ReadableBrandColor implements ValidationRule
{
    public static function contrastWithWhite(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return 1.05 / ($luminance + 0.05);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $fail('El color debe tener el formato #RRGGBB.');

            return;
        }

        if (self::contrastWithWhite($value) < config('workspace.min_contrast')) {
            $fail('El color es demasiado claro: el texto blanco no se leería bien sobre él.');
        }
    }
}
