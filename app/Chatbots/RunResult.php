<?php

namespace App\Chatbots;

/**
 * What one run of an execution ended with, for the job to act on: `done` (answered, or answered with a transfer),
 * `discarded` (on purpose: late, superseded, duplicate, no workflow...), `retry` (a temporary failure: run again after
 * `delay` seconds) or `failed` (permanent: retrying will not help). `code` is always a fixed word, never provider text.
 */
final class RunResult
{
    private function __construct(
        public readonly string $kind,
        public readonly string $code,
        public readonly int $delay = 0,
    ) {}

    public static function done(): self
    {
        return new self('done', 'ok');
    }

    public static function discarded(string $code): self
    {
        return new self('discarded', $code);
    }

    public static function retry(string $code, int $delay): self
    {
        return new self('retry', $code, $delay);
    }

    public static function failed(string $code): self
    {
        return new self('failed', $code);
    }
}
