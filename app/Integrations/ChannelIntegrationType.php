<?php

namespace App\Integrations;

use App\Models\Integration;

/**
 * An integration type that belongs to a chatbot channel (WhatsApp, the web widget...). It is configured from the
 * channel card, so it describes its own form: the page renders `fields()` and knows nothing about the provider.
 */
interface ChannelIntegrationType extends IntegrationType
{
    /**
     * The inputs of the configuration form. `kind`: text, textarea or secret (write-only: the form only learns
     * whether it is set, through `<name>_set`, and a blank one keeps the stored value).
     *
     * @return list<array{name: string, label: string, kind: 'text'|'textarea'|'secret', required: bool, placeholder?: string, hint?: string}>
     */
    public function fields(): array;

    /**
     * What an automation may know about this account when it reads the chatbot: non-secret identifiers only.
     *
     * @return array<string, mixed>
     */
    public function agentView(Integration $integration): array;

    /** Text shown at the top of the form: what the data is for and what Ava does (and does not do) with it. */
    public function notice(): string;
}
