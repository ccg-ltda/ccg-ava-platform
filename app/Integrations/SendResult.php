<?php

namespace App\Integrations;

/**
 * Outcome of handing a message to a channel: the channel's own id when it ACCEPTED it, a fixed reason when it REFUSED it
 * for sure (nothing went out), or UNCERTAIN when the request may have reached the channel but its answer did not (a
 * timeout after sending, a server error): the message may or may not have been delivered, so it must never be sent again
 * automatically.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $externalId = null,
        public readonly ?string $reason = null,
        public readonly bool $uncertain = false,
    ) {}

    public static function accepted(?string $externalId): self
    {
        return new self(true, $externalId);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public static function uncertain(string $reason): self
    {
        return new self(false, null, $reason, true);
    }
}
