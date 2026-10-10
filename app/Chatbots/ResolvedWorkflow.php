<?php

namespace App\Chatbots;

/** A workflow of the platform's n8n that Ava has authorized for ONE chatbot: its catalog key, n8n id, webhook path and optional version. */
final class ResolvedWorkflow
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $n8nId,
        public readonly string $path,
        public readonly ?string $version = null,
    ) {}
}
