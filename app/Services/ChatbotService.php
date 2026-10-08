<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Integration;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads and writes the chatbots of ONE Workspace. Every query starts from the Workspace given by the request
 * context, so a chatbot, a channel or an integration of another Workspace can never be reached from here. A
 * channel never holds credentials: while active it points at the Workspace's own integration for that channel.
 */
class ChatbotService
{
    public function __construct(
        private readonly ChannelCatalog $channels,
        private readonly IntegrationRegistry $types,
        private readonly ChannelAppearanceService $appearance,
    ) {}

    /**
     * @param  bool  $foreign  the Workspace is not the one of the session (an administrator looking at another one)
     * @return list<array<string, mixed>>
     */
    public function list(Workspace $workspace, bool $foreign): array
    {
        $settings = $workspace->settingsOrDefault();

        return $workspace->chatbots()->with('channels')->orderBy('name')->get()->map(fn (Chatbot $chatbot) => [
            'id' => $chatbot->id,
            'name' => $chatbot->name,
            'description' => $chatbot->description,
            'isActive' => $chatbot->is_active,
            'avatarUrl' => $this->avatarUrl($chatbot, $foreign),
            'activeChannels' => $chatbot->channels->where('is_active', true)->map(fn (ChatbotChannel $channel) => $this->channels->get($channel->channel)['label'])->values()->all(),
            'updatedAt' => $settings->formatDate($chatbot->updated_at).' '.$settings->formatTime($chatbot->updated_at),
        ])->all();
    }

    /** @return array<string, mixed> values of the General and behavior tabs */
    public function form(Chatbot $chatbot, bool $foreign): array
    {
        return [
            'id' => $chatbot->id,
            'name' => $chatbot->name,
            'description' => $chatbot->description ?? '',
            'instructions' => $chatbot->instructions ?? '',
            'isActive' => $chatbot->is_active,
            // False until the identity has been saved once: General then asks for it instead of showing the chatbot.
            'profileSaved' => $chatbot->profile_saved_at !== null,
            'avatarUrl' => $this->avatarUrl($chatbot, $foreign),
        ];
    }

    /**
     * Every channel of the global catalog with the state it has FOR THIS chatbot (see `state()`).
     *
     * @return list<array<string, mixed>>
     */
    public function channelStates(Chatbot $chatbot): array
    {
        $settings = $chatbot->workspace->settingsOrDefault();
        $integrations = $chatbot->workspace->integrations()->whereIn('type', $this->channels->integrationTypes())->get()->keyBy('type');
        $rows = $chatbot->channels()->get()->keyBy('channel');
        $takenBy = ChatbotChannel::whereIn('integration_id', $integrations->pluck('id'))->where('is_active', true)
            ->where('chatbot_id', '!=', $chatbot->id)->with('chatbot:id,name')->get()->keyBy('integration_id');

        return array_map(function (array $channel) use ($chatbot, $integrations, $rows, $takenBy, $settings) {
            $integration = $channel['integration'] ? $integrations->get($channel['integration']) : null;
            $other = $integration ? $takenBy->get($integration->id)?->chatbot : null;

            $row = $rows->get($channel['key']);
            $state = $this->state($channel, $integration, $row, $other !== null);

            return [
                'key' => $channel['key'],
                'label' => $channel['label'],
                'description' => $channel['description'],
                'unavailable' => $channel['unavailable'],
                'state' => $state,
                'usedBy' => $other?->name,
                'conversations' => (bool) ($channel['conversations'] ?? false),
                'testable' => $channel['integration'] ? $this->types->get($channel['integration'])->supportsTest() : false,
                'connection' => $integration ? $this->connection($integration, $settings) : null,
                'installCode' => $state === 'active' && ($channel['embeddable'] ?? false) && $row?->public_key && $this->appearance->destination($chatbot->workspace, $channel['key'])['ready'] ? $this->installCode($row->public_key) : null,
            ];
        }, $this->channels->all());
    }

    /**
     * What the user needs to wire the automation: the endpoint, whether a token exists (never its value, except the one
     * just generated, passed in by the caller) and its last characters.
     *
     * @return array{endpoint: string, configured: bool, hint: ?string, createdAt: ?string, token: ?string}
     */
    public function agentAccess(Chatbot $chatbot, ?string $newToken): array
    {
        $settings = $chatbot->workspace->settingsOrDefault();
        $created = $chatbot->agent_token_created_at;

        return [
            'endpoint' => url('/api/agent/config'),
            'configured' => $chatbot->agent_token_hash !== null,
            'hint' => $chatbot->agent_token_hint,
            'createdAt' => $created ? $settings->formatDate($created).' '.$settings->formatTime($created) : null,
            'token' => $newToken,
        ];
    }

    /**
     * The automation (n8n) as the Workspace can verify it: which chatbots have a token and when n8n last read each
     * one. It is the only evidence Ava has of the connection, so "connected" means n8n DID read a configuration.
     *
     * @return array{state: string, endpoint: string, messagesEndpoint: string, lastSeen: ?string, chatbots: list<array<string, mixed>>}
     */
    public function automation(Workspace $workspace): array
    {
        $settings = $workspace->settingsOrDefault();
        $moment = fn ($at) => $at ? $settings->formatDate($at).' '.$settings->formatTime($at) : null;
        $chatbots = $workspace->chatbots()->orderBy('name')->get();
        $seen = $chatbots->whereNotNull('agent_last_seen_at')->max('agent_last_seen_at');

        return [
            'state' => $seen ? 'connected' : ($chatbots->whereNotNull('agent_token_hash')->isNotEmpty() ? 'configured' : 'not_configured'),
            'endpoint' => url('/api/agent/config'),
            'messagesEndpoint' => url('/api/agent/messages'),
            'lastSeen' => $moment($seen),
            'chatbots' => $chatbots->map(fn (Chatbot $chatbot) => [
                'id' => $chatbot->id,
                'name' => $chatbot->name,
                'isActive' => $chatbot->is_active,
                'hasToken' => $chatbot->agent_token_hash !== null,
                'hint' => $chatbot->agent_token_hint,
                'lastSeen' => $moment($chatbot->agent_last_seen_at),
            ])->all(),
        ];
    }

    /**
     * The non-secret facts about the account behind a channel, for its operation page: what the integration says
     * about itself and the last real test. Null while the Workspace has no integration for it.
     *
     * @return array{rows: list<array{label: string, value: string}>, isActive: bool, updatedAt: string, lastTest: ?array{ok: bool, message: ?string, at: string}}|null
     */
    public function channelAccount(Chatbot $chatbot, string $channelKey): ?array
    {
        $type = $this->channels->get($channelKey)['integration'];
        $integration = $type ? $chatbot->workspace->integrations()->where('type', $type)->first() : null;

        if (! $integration) {
            return null;
        }

        $settings = $chatbot->workspace->settingsOrDefault();
        $when = fn ($at) => $settings->formatDate($at).' '.$settings->formatTime($at);

        return [
            'rows' => $this->types->get($type)->summary($integration)['rows'],
            'isActive' => $integration->is_active,
            'updatedAt' => $when($integration->updated_at),
            'lastTest' => $integration->last_tested_at ? ['ok' => (bool) $integration->last_test_ok, 'message' => $integration->last_test_message, 'at' => $when($integration->last_tested_at)] : null,
        ];
    }

    /** @param  array<string, mixed>  $data  validated by SaveChatbotRequest */
    public function create(Workspace $workspace, array $data): Chatbot
    {
        return $workspace->chatbots()->create([
            'name' => trim($data['name']),
            'description' => $this->nullable($data['description'] ?? null),
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $data  validated by SaveChatbotRequest */
    public function update(Chatbot $chatbot, array $data, ?UploadedFile $avatar, bool $removeAvatar): void
    {
        $stored = $avatar ? Storage::putFile("workspaces/{$chatbot->workspace_id}/chatbots/{$chatbot->id}/avatar", $avatar) : null;
        $previous = null;

        try {
            DB::transaction(function () use ($chatbot, $data, $stored, $removeAvatar, &$previous) {
                $chatbot->fill([
                    'name' => trim($data['name']),
                    'description' => $this->nullable($data['description'] ?? null),
                    'instructions' => $this->nullable($data['instructions'] ?? null),
                ]);
                $chatbot->profile_saved_at ??= now();

                if ($stored || $removeAvatar) {
                    $previous = $chatbot->avatar_path;
                    $chatbot->avatar_path = $stored;
                }

                $chatbot->save();
            });
        } catch (Throwable $exception) {
            // Nothing was saved, so the file just uploaded must not stay behind.
            if ($stored) {
                Storage::delete($stored);
            }

            throw $exception;
        }

        // The old avatar goes only after the new state is safely stored.
        if ($previous) {
            Storage::delete($previous);
        }
    }

    public function setActive(Chatbot $chatbot, bool $active): void
    {
        $chatbot->update(['is_active' => $active]);
    }

    /**
     * Turns a channel of the chatbot on or off. Turning it on needs the channel to be available and the Workspace's
     * integration for it to be configured, active and not used by another chatbot; the link is resolved here from the
     * Workspace, never taken from the client. Turning it off releases the integration.
     *
     * @throws ValidationException
     */
    public function setChannelActive(Chatbot $chatbot, string $channelKey, bool $active): void
    {
        $channel = $this->channels->get($channelKey);

        if (! $active) {
            $chatbot->channels()->where('channel', $channelKey)->get()->each->update(['is_active' => false, 'integration_id' => null]);

            return;
        }

        $integration = $channel['integration']
            ? $chatbot->workspace->integrations()->where('type', $channel['integration'])->first()
            : null;
        $inUse = $integration !== null
            && ChatbotChannel::where('integration_id', $integration->id)->where('chatbot_id', '!=', $chatbot->id)->exists();
        $state = $this->state($channel, $integration, null, $inUse);

        if ($state !== 'inactive') {
            throw ValidationException::withMessages(['channel' => $this->refusal($channel, $state)]);
        }

        $row = $chatbot->channels()->firstOrNew(['channel' => $channelKey]);
        $row->fill(['workspace_id' => $chatbot->workspace_id, 'integration_id' => $integration?->id, 'is_active' => true]);

        // An embeddable channel is named by a public key that stays the same when the channel is switched off and on.
        if (($channel['embeddable'] ?? false) && ! $row->public_key) {
            $row->public_key = Str::lower(Str::random(32));
        }

        $row->save();
    }

    /**
     * unavailable (the platform cannot serve it yet) | not_configured (the Workspace has no integration for it) |
     * integration_inactive | active | in_use (another chatbot of the Workspace answers through that account) |
     * inactive (ready to be turned on).
     *
     * @param  array<string, mixed>  $channel
     */
    private function state(array $channel, ?Integration $integration, ?ChatbotChannel $row, bool $inUse): string
    {
        return match (true) {
            ! $channel['available'] => 'unavailable',
            $channel['integration'] && ! $integration => 'not_configured',
            $integration && ! $integration->is_active => 'integration_inactive',
            (bool) $row?->is_active => 'active',
            $inUse => 'in_use',
            default => 'inactive',
        };
    }

    /**
     * What Ava has verified about the integration of a channel: nothing (never tested, or not testable), or the result
     * of the last real test. Saved credentials alone never count as a working connection.
     *
     * @return array{ok: bool, message: ?string, at: ?string}|null
     */
    private function connection(Integration $integration, WorkspaceSetting $settings): ?array
    {
        if ($integration->last_tested_at === null) {
            return null;
        }

        return ['ok' => (bool) $integration->last_test_ok, 'message' => $integration->last_test_message, 'at' => $settings->formatDate($integration->last_tested_at).' '.$settings->formatTime($integration->last_tested_at)];
    }

    private function installCode(string $publicKey): string
    {
        return '<script src="'.url('/widget/ava-widget.js').'" data-chatbot="'.$publicKey.'" defer></script>';
    }

    /** @param  array<string, mixed>  $channel */
    private function refusal(array $channel, string $state): string
    {
        return match ($state) {
            'unavailable' => $channel['unavailable'] ?? 'Este canal todavía no está disponible.',
            'not_configured' => "Configura primero la integración de {$channel['label']} de este Workspace en Integraciones.",
            'integration_inactive' => "La integración de {$channel['label']} está inactiva. Actívala en Integraciones.",
            'in_use' => "Otro chatbot de este Workspace ya responde por {$channel['label']}.",
            default => 'No se pudo activar el canal.',
        };
    }

    private function avatarUrl(Chatbot $chatbot, bool $foreign): ?string
    {
        if (! $chatbot->avatar_path) {
            return null;
        }

        return route('chatbots.avatar', array_filter([
            'chatbot' => $chatbot->id,
            'v' => $chatbot->updated_at?->timestamp,
            // An administrator looking at another Workspace asks for its avatar explicitly; the server revalidates it.
            'workspace' => $foreign ? $chatbot->workspace_id : null,
        ]));
    }

    private function nullable(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
