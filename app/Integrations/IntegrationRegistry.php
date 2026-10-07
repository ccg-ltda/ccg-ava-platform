<?php

namespace App\Integrations;

use App\Services\ChannelCatalog;
use InvalidArgumentException;

/** The integration types the application knows (config/integrations.php). */
class IntegrationRegistry
{
    public function __construct(private readonly ChannelCatalog $channels) {}

    public function get(string $key): IntegrationType
    {
        $class = config("integrations.types.{$key}");

        if (! $class) {
            throw new InvalidArgumentException("Unknown integration type [{$key}].");
        }

        return app($class);
    }

    /** @return list<string> the generic connection types (those a chatbot channel owns are left out) */
    public function keys(): array
    {
        return array_values(array_diff(array_keys(config('integrations.types')), $this->channels->integrationTypes()));
    }

    /** @return list<array{value: string, label: string}> */
    public function options(): array
    {
        return collect($this->keys())
            ->map(fn (string $key) => ['value' => $key, 'label' => $this->get($key)->label()])
            ->all();
    }
}
