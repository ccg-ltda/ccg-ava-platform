<?php

namespace App\Chatbots;

/** How an execution ended: an answer, a failure worth retrying, or a failure that retrying will not fix. Codes are fixed words, never provider text. */
final class ExecutionOutcome
{
    private function __construct(
        public readonly string $kind,
        public readonly string $code,
        public readonly ?string $reply = null,
        public readonly bool $handoff = false,
    ) {}

    public static function answered(?string $reply, bool $handoff): self
    {
        return new self('answered', 'ok', $reply, $handoff);
    }

    public static function temporary(string $code): self
    {
        return new self('temporary', $code);
    }

    public static function permanent(string $code): self
    {
        return new self('permanent', $code);
    }

    public function answeredOk(): bool
    {
        return $this->kind === 'answered';
    }

    public function isTemporary(): bool
    {
        return $this->kind === 'temporary';
    }
}
