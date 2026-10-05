<?php

namespace App\Integrations;

use App\Models\Integration;

/**
 * One kind of integration (generic HTTP/REST today; OAuth, webhooks or a specific provider tomorrow).
 * Everything that varies between kinds lives here; the table, the page, the permissions and the
 * Workspace isolation are shared. Register new types in config/integrations.php.
 */
interface IntegrationType
{
    public function key(): string;

    public function label(): string;

    /**
     * Validation rules of the type-specific fields of the form (flat input keys).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(array $input, ?Integration $current): array;

    /**
     * Turns validated input into what is stored: `config` (no secrets) and `secrets` (encrypted by the model).
     * A secret left blank while editing keeps the stored one.
     *
     * @param  array<string, mixed>  $input
     * @return array{config: array<string, mixed>, secrets: array<string, mixed>}
     */
    public function build(array $input, ?Integration $current): array;

    /**
     * Values to edit the integration in the UI. Must NEVER contain a secret: only whether one is set.
     *
     * @return array<string, mixed>
     */
    public function form(Integration $integration): array;

    /**
     * Short, secret-free description for the card (method and host, for example).
     *
     * @return array<string, mixed>
     */
    public function summary(Integration $integration): array;

    /** Talks to the service with the stored configuration. Must not throw and must not leak secrets. */
    public function test(Integration $integration): TestResult;
}
