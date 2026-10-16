<?php

namespace App\Orders;

use RuntimeException;

/** An order operation Ava refuses, with a message that is safe to show (no personal data, no internals) and the HTTP status an API would answer. */
class OrderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function unavailable(string $message): self
    {
        return new self($message, 422);
    }

    public static function state(string $message): self
    {
        return new self($message, 409);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }
}
