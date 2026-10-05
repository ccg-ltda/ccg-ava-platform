<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Integrations\TestResult;
use App\Models\Integration;
use App\Models\Workspace;

/**
 * Reads and writes the integrations of ONE Workspace. Every query starts from the Workspace given by the
 * request context, so an integration (or its secrets) of another Workspace can never be reached from here.
 * Nothing returned for the UI contains a secret: the types only report whether one is set.
 */
class IntegrationService
{
    public function __construct(private readonly IntegrationRegistry $types) {}

    /** @return list<array<string, mixed>> */
    public function list(Workspace $workspace): array
    {
        $settings = $workspace->settingsOrDefault();
        $when = fn ($moment) => $moment ? $settings->formatDate($moment).' '.$settings->formatTime($moment) : null;

        return $workspace->integrations()->orderBy('name')->get()->map(function (Integration $integration) use ($when) {
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

    private function nullable(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
