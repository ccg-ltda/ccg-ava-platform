<?php

namespace App\Services;

use App\Integrations\ChannelIntegrationType;
use App\Integrations\IntegrationRegistry;
use App\Integrations\TestResult;
use App\Models\Integration;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes the integrations of ONE Workspace. Every query starts from the Workspace given by the
 * request context, so an integration (or its secrets) of another Workspace can never be reached from here.
 * Nothing returned for the UI contains a secret: the types only report whether one is set.
 */
class IntegrationService
{
    public function __construct(
        private readonly IntegrationRegistry $types,
        private readonly ChannelCatalog $channels,
    ) {}

    /** The generic connections of the Workspace (the integrations of its channels are listed by `channels()`). @return list<array<string, mixed>> */
    public function list(Workspace $workspace): array
    {
        $when = $this->moment($workspace);

        return $workspace->integrations()->whereIn('type', $this->types->keys())->orderBy('name')->get()->map(function (Integration $integration) use ($when) {
            $type = $this->types->get($integration->type);

            return [
                'id' => $integration->id,
                'name' => $integration->name,
                'provider' => $integration->provider,
                'description' => $integration->description,
                'type' => $integration->type,
                'typeLabel' => $type->label(),
                'isActive' => $integration->is_active,
                'summary' => $type->summary($integration),
                'lastTest' => $integration->last_tested_at ? [
                    'at' => $when($integration->last_tested_at),
                    'ok' => (bool) $integration->last_test_ok,
                    'status' => $integration->last_test_status,
                    'message' => $integration->last_test_message,
                ] : null,
                'updatedAt' => $when($integration->updated_at),
                'form' => [
                    'name' => $integration->name,
                    'provider' => $integration->provider ?? '',
                    'description' => $integration->description ?? '',
                    'type' => $integration->type,
                    'is_active' => $integration->is_active,
                ] + $type->form($integration),
            ];
        })->all();
    }

    /**
     * Every channel of the global catalog with what THIS Workspace has configured for it (nothing, or its own
     * integration). The credentials of the integration never leave: the form only says whether the token is set.
     *
     * @return list<array<string, mixed>>
     */
    public function channels(Workspace $workspace): array
    {
        $when = $this->moment($workspace);
        $configured = $workspace->integrations()->whereIn('type', $this->channels->integrationTypes())->get()->keyBy('type');

        return array_map(function (array $channel) use ($configured, $when) {
            $integration = $channel['integration'] ? $configured->get($channel['integration']) : null;
            $type = $channel['integration'] ? $this->types->get($channel['integration']) : null;

            return [
                'key' => $channel['key'],
                'label' => $channel['label'],
                'description' => $channel['description'],
                'available' => $channel['available'],
                'unavailable' => $channel['unavailable'],
                'needsIntegration' => $channel['integration'] !== null,
                'fields' => $type instanceof ChannelIntegrationType ? $type->fields() : [],
                'notice' => $type instanceof ChannelIntegrationType ? $type->notice() : null,
                'testable' => (bool) $type?->supportsTest(),
                'integration' => $integration ? [
                    'id' => $integration->id,
                    'isActive' => $integration->is_active,
                    'summary' => $type->summary($integration),
                    'updatedAt' => $when($integration->updated_at),
                    'form' => $type->form($integration),
                    'lastTest' => $integration->last_tested_at ? [
                        'at' => $when($integration->last_tested_at),
                        'ok' => (bool) $integration->last_test_ok,
                        'message' => $integration->last_test_message,
                    ] : null,
                ] : null,
            ];
        }, $this->channels->all());
    }

    /**
     * Creates or updates the Workspace's own integration for a channel (one per channel and Workspace).
     *
     * @param  array<string, mixed>  $data  validated by SaveChannelIntegrationRequest
     */
    public function saveChannel(Workspace $workspace, string $channel, array $data): Integration
    {
        $definition = $this->channels->get($channel);

        return $this->saveSingle($workspace, $definition['integration'], $definition['label'], $data);
    }

    /**
     * The state of the Workspace's n8n connection AS AVA CAN VERIFY IT: not_configured (nothing saved), configured
     * (saved, never tested), verified (the last real test succeeded) or error (it failed). Never the secret.
     *
     * @return array<string, mixed>
     */
    public function n8n(Workspace $workspace): array
    {
        $integration = $workspace->integrations()->where('type', 'n8n')->first();

        if (! $integration) {
            return ['state' => 'not_configured', 'id' => null];
        }

        $when = $this->moment($workspace);

        return [
            'id' => $integration->id,
            'state' => match (true) {
                $integration->last_tested_at === null => 'configured',
                (bool) $integration->last_test_ok => 'verified',
                default => 'error',
            },
            'baseUrl' => $integration->config['base_url'],
            'host' => parse_url($integration->config['base_url'], PHP_URL_HOST),
            'apiKeySet' => filled($integration->secrets['api_key'] ?? null),
            'updatedAt' => $when($integration->updated_at),
            'lastTest' => $integration->last_tested_at ? [
                'at' => $when($integration->last_tested_at),
                'ok' => (bool) $integration->last_test_ok,
                'message' => $integration->last_test_message,
            ] : null,
            'form' => $this->types->get('n8n')->form($integration),
        ];
    }

    /** Saves the Workspace's n8n connection (one per Workspace). @param  array<string, mixed>  $data */
    public function saveN8n(Workspace $workspace, array $data): Integration
    {
        return $this->saveSingle($workspace, 'n8n', 'n8n', $data);
    }

    /** Removes the n8n connection and with it the API Key: a disconnected n8n keeps no credential in Ava. */
    public function disconnectN8n(Workspace $workspace): void
    {
        $workspace->integrations()->where('type', 'n8n')->get()->each->delete();
    }

    /**
     * Creates or updates the Workspace's own integration of a type that exists once per Workspace (a channel's account,
     * n8n). It is named after what it is; a generic connection already using that name would clash.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveSingle(Workspace $workspace, string $typeKey, string $label, array $data): Integration
    {
        $type = $this->types->get($typeKey);
        $current = $workspace->integrations()->where('type', $type->key())->first();
        $built = $type->build($data, $current);

        if ($current) {
            // Whatever was verified before no longer describes the new configuration.
            $current->update([
                'config' => $built['config'],
                'secrets' => $built['secrets'],
                'last_tested_at' => null,
                'last_test_ok' => null,
                'last_test_status' => null,
                'last_test_message' => null,
            ]);

            return $current;
        }

        if ($workspace->integrations()->where('name', $label)->exists()) {
            throw ValidationException::withMessages(['name' => "Ya existe una integración llamada {$label} en este Workspace. Renómbrala para configurarla."]);
        }

        return $workspace->integrations()->create([
            'name' => $label,
            'type' => $type->key(),
            'is_active' => true,
            'config' => $built['config'],
            'secrets' => $built['secrets'],
        ]);
    }

    /** Options the form offers (the same lists the validation uses). */
    public function catalog(): array
    {
        $http = config('integrations.http');
        $options = fn (array $items) => collect($items)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return [
            'types' => $this->types->options(),
            'methods' => array_map(fn ($method) => ['value' => $method, 'label' => $method], $http['methods']),
            'authTypes' => $options($http['auth_types']),
            'apiKeyLocations' => $options($http['api_key_locations']),
            'defaultTimeout' => $http['default_timeout'],
            'maxTimeout' => $http['max_timeout'],
            'maxHeaders' => $http['max_headers'],
            'maxQuery' => $http['max_query'],
        ];
    }

    /** @param  array<string, mixed>  $data  validated by SaveIntegrationRequest */
    public function create(Workspace $workspace, array $data): Integration
    {
        $built = $this->types->get($data['type'])->build($data, null);

        return $workspace->integrations()->create([
            'name' => trim($data['name']),
            'provider' => $this->nullable($data['provider'] ?? null),
            'description' => $this->nullable($data['description'] ?? null),
            'type' => $data['type'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'config' => $built['config'],
            'secrets' => $built['secrets'],
        ]);
    }

    /** @param  array<string, mixed>  $data  validated by SaveIntegrationRequest */
    public function update(Integration $integration, array $data): Integration
    {
        $built = $this->types->get($integration->type)->build($data, $integration);

        $integration->update([
            'name' => trim($data['name']),
            'provider' => $this->nullable($data['provider'] ?? null),
            'description' => $this->nullable($data['description'] ?? null),
            'config' => $built['config'],
            'secrets' => $built['secrets'],
            // Whatever was tested before no longer describes the new configuration.
            'last_tested_at' => null,
            'last_test_ok' => null,
            'last_test_status' => null,
            'last_test_message' => null,
        ]);

        return $integration;
    }

    public function setActive(Integration $integration, bool $active): void
    {
        $integration->update(['is_active' => $active]);
    }

    /** Runs the connection test and keeps only a short summary of the outcome. */
    public function test(Integration $integration): TestResult
    {
        $result = $this->types->get($integration->type)->test($integration);

        $integration->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_status' => $result->httpStatus,
            'last_test_message' => mb_substr($result->message, 0, 255),
        ])->save();

        return $result;
    }

    /** @return Closure(?CarbonInterface): ?string formats a moment with the Workspace's own date and time preferences */
    private function moment(Workspace $workspace): Closure
    {
        $settings = $workspace->settingsOrDefault();

        return fn ($moment) => $moment ? $settings->formatDate($moment).' '.$settings->formatTime($moment) : null;
    }

    private function nullable(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
