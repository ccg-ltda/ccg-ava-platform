<?php

namespace App\Payments\Gateways;

use App\Integrations\IntegrationType;

/**
 * The integration type of a payment gateway: the connection ONE Workspace keeps with its own commercial account (one per gateway and Workspace). Like a channel it describes its
 * own form, so the Integraciones page knows nothing about the provider. Saving it is never a connection: only a real connection test validates it (GatewayAvailability), and a
 * type whose test is not possible yet says so instead of pretending.
 */
interface PaymentGatewayType extends IntegrationType
{
    /**
     * The inputs of the configuration form, grouped by `section`. `kind`: text, select (with `options`), textarea or secret (write-only, encrypted).
     * `pending`: set while the provider's official specification has not confirmed the field (the form says so).
     *
     * @return list<array{name: string, label: string, kind: 'text'|'textarea'|'secret'|'select', section: string, required: bool, default?: string, placeholder?: string, hint?: string, pending?: string, options?: list<array{value: string, label: string}>}>
     */
    public function fields(): array;

    /** Text shown at the top of the form: what the data is for and what Ava does (and does not do) with it. */
    public function notice(): string;

    /** One sentence for the card: what this gateway is. */
    public function description(): string;

    /** @return list<string> what still depends on the provider's official documentation or laboratory (shown on the card; empty once everything is verified) */
    public function blockedBy(): array;

    /**
     * What is missing for this configuration to be complete (empty = complete). It looks at the stored data only; it never reaches the provider.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $secrets
     * @return list<string>
     */
    public function problems(array $config, ?array $secrets): array;
}
