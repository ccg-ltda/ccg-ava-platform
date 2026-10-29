<?php

namespace App\Chatbots;

/** Where and with which secret Ava runs the workflows of ONE Workspace. `source` says whose configuration it is: `workspace` (its own n8n connection) or `environment` (the legacy platform variables). */
final class ExecutionTarget
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $secret,
        public readonly string $source,
    ) {}
}
