<?php

namespace App\Services;

use App\Models\Workspace;

/**
 * The global catalog of chatbot channels (config/chatbots.php). It holds no Workspace data. A channel that is connected with Meta's OAuth (`oauth` in the config) is available only while
 * the platform's own Meta app is configured (`MetaConnection::platformIssue`): the catalog says why when it is not, so the interface never offers what cannot work.
 */
class ChannelCatalog
{
    public function __construct(private readonly WorkspaceChannels $plans) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('chatbots.channels'));
    }

    /** @return array{label: string, description: string, integration: ?string, available: bool, unavailable: ?string} */
    public function get(string $key, ?Workspace $workspace = null): array
    {
        $channel = config("chatbots.channels.{$key}");

        abort_unless($channel, 404);

        return $this->restrict($this->resolve($key, $channel), $key, $workspace);
    }

    /** @return list<string> keys of the integration types that belong to a channel (not generic connections) */
    public function integrationTypes(): array
    {
        return collect(config('chatbots.channels'))->pluck('integration')->filter()->unique()->values()->all();
    }

    /** @return list<array{key: string, label: string, description: string, integration: ?string, available: bool, unavailable: ?string}> */
    public function all(?Workspace $workspace = null): array
    {
        return collect(config('chatbots.channels'))->map(fn (array $channel, string $key) => $this->restrict(['key' => $key] + $this->resolve($key, $channel), $key, $workspace))->values()->all();
    }

    /**
     * With a Workspace: a channel its plan does not include is shown as unavailable with the reason (`restricted`), so no screen offers it and the services refuse it. The connection data of the
     * Workspace is not touched: it works again when the plan includes the channel.
     *
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    private function restrict(array $channel, string $key, ?Workspace $workspace): array
    {
        if ($workspace === null || $this->plans->allows($workspace, $key)) {
            return $channel + ['restricted' => false];
        }

        return ['available' => false, 'unavailable' => $this->plans->restriction(), 'restricted' => true] + $channel;
    }

    /**
     * @param  array<string, mixed>  $channel
     * @return array<string, mixed>
     */
    private function resolve(string $key, array $channel): array
    {
        if (! ($channel['oauth'] ?? false)) {
            return $channel;
        }

        $issue = MetaConnection::platformIssue($key);

        return ['available' => $issue === null, 'unavailable' => $issue] + $channel;
    }
}
