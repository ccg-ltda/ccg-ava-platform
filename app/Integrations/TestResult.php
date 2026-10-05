<?php

namespace App\Integrations;

/** Outcome of a connection test: a human message and a status code, never a response body or a secret. */
final class TestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $durationMs = null,
    ) {}

    public static function failure(string $message, ?int $httpStatus = null, ?int $durationMs = null): self
    {
        return new self(false, $message, $httpStatus, $durationMs);
    }
}
