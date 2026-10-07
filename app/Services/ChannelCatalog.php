<?php

namespace App\Services;

/** The global catalog of chatbot channels (config/chatbots.php). It holds no Workspace data. */
class ChannelCatalog
{
    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('chatbots.channels'));
    }

    /** @return array{label: string, description: string, integration: ?string, available: bool, unavailable: ?string} */
    public function get(string $key): array
    {
        $channel = config("chatbots.channels.{$key}");

        abort_unless($channel, 404);

        return $channel;
    }

    /** @return list<string> keys of the integration types that belong to a channel (not generic connections) */
    public function integrationTypes(): array
    {
        return collect(config('chatbots.channels'))->pluck('integration')->filter()->unique()->values()->all();
    }

    /** @return list<array{key: string, label: string, description: string, integration: ?string, available: bool, unavailable: ?string}> */
    public function all(): array
    {
        return collect(config('chatbots.channels'))->map(fn (array $channel, string $key) => ['key' => $key] + $channel)->values()->all();
    }
}
