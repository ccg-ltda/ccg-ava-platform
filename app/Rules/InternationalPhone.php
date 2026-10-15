<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A NEW or CHANGED phone must be international ("+573001112233"). `$stored` is the phone the record already has: sending it back
 * unchanged (editing the notes of an old customer) is not new input and does not force the person to rewrite a historical number.
 * `$verifiedDigits` is the one exception to the "+" rule: the id of a WhatsApp conversation, which Meta always issues as a full
 * international number without the "+", so the digits of THAT contact (and only that one) are known to be international.
 */
class InternationalPhone implements ValidationRule
{
    public function __construct(private readonly ?string $stored = null, private readonly ?string $verifiedDigits = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if ($this->stored !== null && trim($value) === trim($this->stored)) {
            return;
        }

        if ($this->verifiedDigits !== null && trim($value) === $this->verifiedDigits) {
            return;
        }

        if ($message = PhoneNumber::error($value)) {
            $fail($message);
        }
    }
}
