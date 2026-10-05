<?php

namespace App\Integrations;

use InvalidArgumentException;

/** The integration types the application knows (config/integrations.php). */
class IntegrationRegistry
{
    public function get(string $key): IntegrationType
    {
        $class = config("integrations.types.{$key}");

        if (! $class) {
            throw new InvalidArgumentException("Unknown integration type [{$key}].");
        }

        return app($class);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('integrations.types'));
    }

    /** @return list<array{value: string, label: string}> */
    public function options(): array
    {
        return collect($this->keys())
            ->map(fn (string $key) => ['value' => $key, 'label' => $this->get($key)->label()])
            ->all();
    }
}
