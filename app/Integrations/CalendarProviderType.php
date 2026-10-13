<?php

namespace App\Integrations;

use App\Booking\CalendarProvider;
use App\Models\Integration;

/**
 * An integration type that is an external AGENDA (Booker today). Like a channel it describes its own form (the page knows
 * nothing about the provider) and, once configured, it can hand Ava a `CalendarProvider` that speaks to it. It exists once per
 * Workspace and is only offered to Workspaces whose plan includes the module `calendar_providers`.
 */
interface CalendarProviderType extends IntegrationType
{
    /**
     * The inputs of the configuration form. `kind`: text, select (with `options`), textarea or secret (write-only).
     *
     * @return list<array{name: string, label: string, kind: 'text'|'textarea'|'secret'|'select', required: bool, placeholder?: string, hint?: string, options?: list<array{value: string, label: string}>}>
     */
    public function fields(): array;

    /** Text shown at the top of the form: what the data is for and what Ava does (and does not do) with it. */
    public function notice(): string;

    /** One sentence for the card: what this agenda is. */
    public function description(): string;

    /** The agenda behind this integration, for the Workspace that owns it. */
    public function calendar(Integration $integration): CalendarProvider;
}
