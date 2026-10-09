<?php

namespace App\Integrations;

/** Outcome of handing a message to a channel: the channel's own id when it accepted it, or a fixed reason when not. */
final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $externalId = null,
        public readonly ?string $reason = null,
    ) {}

    public static function accepted(?string $externalId): self
    {
        return new self(true, $externalId);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
