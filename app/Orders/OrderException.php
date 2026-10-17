<?php

namespace App\Orders;

use RuntimeException;

/** An order operation Ava refuses, with a message that is safe to show (no personal data, no internals) and the HTTP status an API would answer. */
class OrderException extends RuntimeException
{
    /** @param  string  $reason  a fixed code the assistant can act on: `invalid` (422), `state` (409), `forbidden` (403) or the one the caller gave */
    public function __construct(string $message, public readonly int $status = 422, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public static function unavailable(string $message): self
    {
        return new self($message, 422);
    }

    public static function state(string $message): self
    {
        return new self($message, 409, 'state');
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403, 'forbidden');
    }
}
