<?php

namespace App\Chatbots;

use App\Models\Integration;
use App\Models\Workspace;

/**
 * Which n8n a Workspace's conversations run on, decided on the server from the Workspace of the chatbot, never from a request.
 *
 *  1. The Workspace's OWN n8n connection (Integraciones): an active `n8n` integration with an execution secret. The webhooks live under `{instance}/webhook`.
 *     Its credentials are used for that Workspace only and are never read for another one.
 *  2. Transition (explicit, `config('n8n.legacy_environment')`): the platform variables N8N_EXECUTION_URL / N8N_EXECUTION_SECRET, for the scope
 *     named there (`administrative` = only Desarrollo_DEV, the default; `all` = every Workspace without its own connection, the behavior before
 *     this existed; `none` = never). A Workspace that has its own connection is NEVER served by the environment.
 *
 * No connection = null: the caller fails safe (the message is stored and a person answers).
 */
class N8nConnection
{
    public const WEBHOOK_PATH = '/webhook';

    public function forWorkspace(Workspace $workspace): ?ExecutionTarget
    {
        $own = Integration::where('workspace_id', $workspace->id)->where('type', 'n8n')->where('is_active', true)->first();

        if ($own && filled($own->config['base_url'] ?? null) && filled($own->secrets['execution_secret'] ?? null)) {
            return new ExecutionTarget(rtrim((string) $own->config['base_url'], '/').self::WEBHOOK_PATH, (string) $own->secrets['execution_secret'], 'workspace');
        }

        $scope = (string) config('n8n.legacy_environment', 'administrative');
        $legacy = $scope === 'all' || ($scope === 'administrative' && $workspace->isAdministrative());

        if ($legacy && filled(config('n8n.execution_url')) && filled(config('n8n.secret'))) {
            return new ExecutionTarget((string) config('n8n.execution_url'), (string) config('n8n.secret'), 'environment');
        }

        return null;
    }

    /** What the interface and the diagnosis may say about it: where it comes from, never a value. @return array{ready: bool, source: ?string, host: ?string} */
    public function status(Workspace $workspace): array
    {
        $target = $this->forWorkspace($workspace);

        return ['ready' => $target !== null, 'source' => $target?->source, 'host' => $target ? (parse_url($target->baseUrl, PHP_URL_HOST) ?: null) : null];
    }
}
