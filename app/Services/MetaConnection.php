<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Integrations\MetaGraph;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a Workspace's Instagram professional account or Facebook Page with Meta's OAuth (never by pasting a token) and keeps that connection alive. Everything Meta returns
 * is turned into the Workspace's OWN integration (`instagram` / `messenger`, one per Workspace) with the token encrypted; the interface only ever learns the account's name and
 * whether the webhook is subscribed.
 *
 *  - `begin`: a one-time `state` (cache, bound to the Workspace, the person and the channel, with an expiry) goes to Meta and comes back; `complete` refuses anything that does not match.
 *  - Instagram («Instagram API with Instagram Login»): code -> short-lived token -> long-lived token (60 days) -> the professional account (`user_id`, `username`, type Business/Creator).
 *  - Messenger (Facebook Login): code -> user token -> long-lived user token -> the Pages the person manages (`/me/accounts`). The person CHOOSES one; its Page token (kept encrypted for
 *    a few minutes while they choose) is stored in the integration.
 *  - Both subscribe the account to the webhook for `messages` and record whether Meta accepted it (`webhook_subscribed_at`); a failure is shown, never hidden.
 *  - Instagram tokens expire after 60 days: `refresh` renews them (Meta allows it after 24 hours and before expiry).
 * A failure carries a FIXED, human message (`MetaConnectionException`): neither Meta's text, a code nor a token is echoed. The platform's Meta app (IDs and secrets) is global; every
 * authorization and token belongs to the Workspace that made it.
 */
class MetaConnection
{
    public function __construct(private readonly IntegrationRegistry $types) {}

    /** Why a channel cannot be connected on this platform (the Meta app is not configured), or null when it can. */
    public static function platformIssue(string $channel): ?string
    {
        $secret = $channel === 'instagram' ? PlatformMeta::get('instagram_app_secret') : PlatformMeta::get('app_secret');
        $appId = $channel === 'instagram' ? PlatformMeta::get('instagram_app_id') : PlatformMeta::get('app_id');

        return match (true) {
            blank($appId) || blank($secret) => 'Falta configurar la app de Meta de la plataforma ('.($channel === 'instagram' ? 'App ID y secreto de Instagram' : 'App ID y secreto de Facebook').'): la administración de CCG la configura una sola vez en Configuraciones > Meta.',
            blank(PlatformMeta::get('webhook_verify_token')) => 'Falta el token de verificación del webhook de Meta: la administración de CCG lo configura en Configuraciones > Meta.',
            default => null,
        };
    }

    /** The URL Meta must be told (Valid OAuth Redirect URI) and the webhook URL, both fixed by the platform. @return array{redirect: string, webhook: string, public: bool} */
    public function urls(): array
    {
        $redirect = route('integrations.meta.callback');

        return ['redirect' => $redirect, 'webhook' => route('webhooks.meta.receive'), 'public' => str_starts_with($redirect, 'https://')];
    }

    /** The status of a channel's connection AS AVA CAN PROVE IT, for the card: each stage is done only when it was really observed. @return array<string, mixed> */
    public function status(string $channel, ?Integration $integration): array
    {
        $issue = self::platformIssue($channel);
        $urls = $this->urls();
        $connected = $integration !== null;
        $subscribed = $connected && filled($integration->config['webhook_subscribed_at'] ?? null);
        $expires = $integration?->config['token_expires_at'] ?? null;
        $expired = $connected && filled($expires) && now()->greaterThan($expires);

        $stages = [
            ['key' => 'app', 'label' => 'App de Meta de la plataforma', 'done' => $issue === null, 'detail' => $issue ?? 'La plataforma tiene su app de Meta configurada.'],
            ['key' => 'authorized', 'label' => $channel === 'instagram' ? 'Cuenta de Instagram autorizada' : 'Página de Facebook autorizada', 'done' => $connected, 'detail' => $connected ? 'El permiso fue concedido en Meta y guardado cifrado.' : 'Todavía nadie la ha conectado en este Workspace.'],
            ['key' => 'webhook', 'label' => 'Webhook de mensajes suscrito', 'done' => $subscribed, 'detail' => $subscribed ? 'Meta aceptó la suscripción al campo «messages».' : ($connected ? 'Meta no confirmó la suscripción: usa «Reintentar suscripción».' : 'Se suscribe al conectar.')],
            ['key' => 'tested', 'label' => 'Conexión verificada con Meta', 'done' => (bool) ($connected && $integration->last_test_ok), 'detail' => $connected && $integration->last_tested_at ? ($integration->last_test_ok ? 'La última prueba leyó la cuenta con éxito.' : 'La última prueba falló: '.$integration->last_test_message) : 'Usa «Probar conexión».'],
        ];

        return [
            'label' => match (true) {
                ! $connected => 'No conectado',
                $expired => 'Autorización vencida',
                ! $subscribed => 'Conectado sin webhook',
                $integration->last_test_ok === false => 'Error de conexión',
                $integration->last_test_ok === true => 'Conexión verificada',
                default => 'Conectado sin probar',
            },
            'tone' => match (true) {
                ! $connected => 'amber',
                $expired || ! $subscribed || $integration->last_test_ok === false => 'red',
                $integration->last_test_ok === true => 'green',
                default => 'blue',
            },
            'stages' => $stages,
            'oauth' => ['ready' => $issue === null, 'issue' => $issue, 'redirect' => $urls['redirect'], 'webhook' => $urls['webhook'], 'public' => $urls['public'], 'scopes' => config("integrations.meta.scopes.{$channel}")],
        ];
    }

    /**
     * Starts an authorization: the URL to send the person to. The `state` is single use and bound to this Workspace, person and channel.
     *
     * @throws MetaConnectionException
     */
    public function begin(Workspace $workspace, User $user, string $channel): string
    {
        $this->assertReady($channel);
        $state = Str::random(48);
        Cache::put($this->stateKey($state), ['workspace' => $workspace->id, 'user' => $user->id, 'channel' => $channel], now()->addMinutes((int) config('integrations.meta.state_minutes')));

        return $channel === 'instagram'
            ? config('integrations.meta.instagram_oauth_url').'?'.http_build_query(['client_id' => PlatformMeta::get('instagram_app_id'), 'redirect_uri' => $this->urls()['redirect'], 'response_type' => 'code', 'scope' => implode(',', config('integrations.meta.scopes.instagram')), 'state' => $state])
            : rtrim(config('integrations.meta.facebook_dialog_url'), '/').'/'.MetaGraph::version().'/dialog/oauth?'.http_build_query(['client_id' => PlatformMeta::get('app_id'), 'redirect_uri' => $this->urls()['redirect'], 'response_type' => 'code', 'scope' => implode(',', config('integrations.meta.scopes.messenger')), 'state' => $state]);
    }

    /**
     * Finishes an authorization with what Meta sent back. Returns the channel and, for Messenger, the id of the Pages selection the person must choose from; for Instagram the
     * integration is already saved.
     *
     * @return array{channel: string, selection: ?string}
     *
     * @throws MetaConnectionException
     */
    public function complete(Workspace $workspace, User $user, string $state, string $code): array
    {
        $saved = Cache::pull($this->stateKey($state));

        if (! is_array($saved) || $saved['workspace'] !== $workspace->id || $saved['user'] !== $user->id) {
            throw new MetaConnectionException('La autorización no es válida o venció. Empieza de nuevo desde «Conectar».');
        }

        $channel = $saved['channel'];
        $this->assertReady($channel);

        try {
            return $channel === 'instagram'
                ? ['channel' => 'instagram', 'selection' => $this->completeInstagram($workspace, $code)]
                : ['channel' => 'messenger', 'selection' => $this->startPages($workspace, $user, $code)];
        } catch (MetaConnectionException $e) {
            throw $e;
        } catch (ConnectionException) {
            throw new MetaConnectionException('No se pudo conectar con Meta. Vuelve a intentarlo en unos minutos.');
        } catch (Throwable) {
            // The text of an unexpected error may carry a code or a token: only a fixed message leaves here.
            throw new MetaConnectionException('No se pudo completar la conexión con Meta. Vuelve a intentarlo.');
        }
    }

    /** The Pages the person may choose from (id and name only), or null when the selection expired or is not theirs. @return list<array{id: string, name: string}>|null */
    public function pages(Workspace $workspace, User $user, string $selection): ?array
    {
        $saved = $this->selection($workspace, $user, $selection);

        return $saved === null ? null : array_map(fn (array $page) => ['id' => $page['id'], 'name' => $page['name']], $saved['pages']);
    }

    /**
     * Connects the chosen Page: stores its token (encrypted) in the Workspace's integration and subscribes it to the webhook.
     *
     * @throws MetaConnectionException
     */
    public function connectPage(Workspace $workspace, User $user, string $selection, string $pageId): Integration
    {
        $saved = $this->selection($workspace, $user, $selection);
        $page = $saved === null ? null : collect($saved['pages'])->firstWhere('id', $pageId);

        if ($page === null) {
            throw new MetaConnectionException('La selección venció o la página no está entre las autorizadas. Vuelve a conectar.');
        }

        $this->assertNotClaimed('messenger', $pageId, $workspace);
        $integration = $this->store($workspace, 'messenger', ['account_id' => $pageId, 'account_name' => mb_substr($page['name'], 0, 120), 'connected_at' => now()->toIso8601String()], ['access_token' => Crypt::decryptString($page['token'])]);
        Cache::forget($this->selectionKey($selection));
        $this->subscribe($integration);

        return $integration->fresh();
    }

    /** Asks Meta again to send this account's messages to Ava's webhook. Returns whether Meta accepted it. */
    public function subscribe(Integration $integration): bool
    {
        $channel = $integration->type;
        $fields = (string) config("integrations.meta.webhook_fields.{$channel}");
        $id = (string) ($integration->config['account_id'] ?? '');

        try {
            $response = $this->http()->withToken((string) ($integration->secrets['access_token'] ?? ''))->asForm()->post($this->graph($channel, "{$id}/subscribed_apps"), ['subscribed_fields' => $fields]);
            $ok = $response->successful() && $response->json('success') === true;
        } catch (Throwable) {
            $ok = false;
        }

        $config = $integration->config;
        $ok ? $config['webhook_subscribed_at'] = now()->toIso8601String() : $config = array_diff_key($config, ['webhook_subscribed_at' => 1]);
        $integration->forceFill(['config' => $config])->save();

        return $ok;
    }

    /** Removes the connection of a channel: Meta is told to stop sending (best effort) and the integration, with its token, is deleted. */
    public function disconnect(Integration $integration): void
    {
        if ($integration->type === 'messenger') {
            try {
                $this->http()->withToken((string) ($integration->secrets['access_token'] ?? ''))->delete($this->graph('messenger', ($integration->config['account_id'] ?? '').'/subscribed_apps'));
            } catch (Throwable) {
                // The token may already be revoked: the local connection is removed either way, and Meta stops delivering when the Page's permission is gone.
            }
        }

        $integration->delete();
    }

    /**
     * Renews an Instagram token that is close to expiring (Meta allows it after 24 hours and before expiry; each renewal adds 60 days). Returns whether it was renewed; a token that
     * can no longer be renewed leaves the integration as it is and the card says it is expired.
     */
    public function refresh(Integration $integration): bool
    {
        if ($integration->type !== 'instagram') {
            return false;
        }

        try {
            $response = $this->http()->get(rtrim(config('integrations.meta.instagram_graph_url'), '/').'/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => (string) ($integration->secrets['access_token'] ?? '')]);
        } catch (Throwable) {
            return false;
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            return false;
        }

        $config = $integration->config;
        $config['token_expires_at'] = now()->addSeconds((int) $response->json('expires_in', 5_184_000))->toIso8601String();
        $config['token_refreshed_at'] = now()->toIso8601String();
        $integration->forceFill(['config' => $config, 'secrets' => ['access_token' => $token]])->save();

        return true;
    }

    private function completeInstagram(Workspace $workspace, string $code): ?string
    {
        $short = $this->http()->asForm()->post(config('integrations.meta.instagram_token_url'), [
            'client_id' => PlatformMeta::get('instagram_app_id'), 'client_secret' => PlatformMeta::get('instagram_app_secret'), 'grant_type' => 'authorization_code', 'redirect_uri' => $this->urls()['redirect'], 'code' => $code,
        ]);
        $shortToken = $short->json('access_token') ?? $short->json('data.0.access_token');

        if (! $short->successful() || ! is_string($shortToken) || $shortToken === '') {
            throw new MetaConnectionException('Meta no aceptó la autorización de Instagram. Vuelve a intentarlo.');
        }

        $long = $this->http()->get(rtrim(config('integrations.meta.instagram_graph_url'), '/').'/access_token', ['grant_type' => 'ig_exchange_token', 'client_secret' => PlatformMeta::get('instagram_app_secret'), 'access_token' => $shortToken]);
        $token = $long->json('access_token');

        if (! $long->successful() || ! is_string($token) || $token === '') {
            throw new MetaConnectionException('No se pudo obtener la autorización de larga duración de Instagram. Vuelve a intentarlo.');
        }

        $profile = $this->http()->withToken($token)->get($this->graph('instagram', 'me'), ['fields' => 'user_id,username,account_type']);
        $accountId = (string) ($profile->json('user_id') ?? $profile->json('id'));

        if (! $profile->successful() || $accountId === '') {
            throw new MetaConnectionException('No se pudo leer la cuenta de Instagram autorizada.');
        }

        if (! in_array($profile->json('account_type'), ['BUSINESS', 'MEDIA_CREATOR'], true)) {
            throw new MetaConnectionException('La cuenta debe ser profesional (Business o Creator). Cámbiala en Instagram y vuelve a conectar.');
        }

        $this->assertNotClaimed('instagram', $accountId, $workspace);
        $integration = $this->store($workspace, 'instagram', [
            'account_id' => $accountId, 'account_name' => mb_substr((string) $profile->json('username'), 0, 120), 'connected_at' => now()->toIso8601String(),
            'token_expires_at' => now()->addSeconds((int) $long->json('expires_in', 5_184_000))->toIso8601String(),
        ], ['access_token' => $token]);
        $this->subscribe($integration);

        return null;
    }

    private function startPages(Workspace $workspace, User $user, string $code): string
    {
        $user_token = $this->http()->get($this->graph('messenger', 'oauth/access_token'), ['client_id' => PlatformMeta::get('app_id'), 'client_secret' => PlatformMeta::get('app_secret'), 'redirect_uri' => $this->urls()['redirect'], 'code' => $code]);
        $shortToken = $user_token->json('access_token');

        if (! $user_token->successful() || ! is_string($shortToken) || $shortToken === '') {
            throw new MetaConnectionException('Meta no aceptó la autorización de Facebook. Vuelve a intentarlo.');
        }

        $long = $this->http()->get($this->graph('messenger', 'oauth/access_token'), ['grant_type' => 'fb_exchange_token', 'client_id' => PlatformMeta::get('app_id'), 'client_secret' => PlatformMeta::get('app_secret'), 'fb_exchange_token' => $shortToken]);
        $userToken = is_string($long->json('access_token')) ? $long->json('access_token') : $shortToken;
        $accounts = $this->http()->withToken($userToken)->get($this->graph('messenger', 'me/accounts'), ['fields' => 'id,name,access_token', 'limit' => 100]);

        if (! $accounts->successful()) {
            throw new MetaConnectionException('No se pudo leer la lista de páginas de Facebook autorizadas.');
        }

        $pages = collect($accounts->json('data', []))->filter(fn ($page) => is_array($page) && is_string($page['id'] ?? null) && is_string($page['access_token'] ?? null))
            ->map(fn (array $page) => ['id' => $page['id'], 'name' => (string) ($page['name'] ?? $page['id']), 'token' => Crypt::encryptString($page['access_token'])])->values()->all();

        if ($pages === []) {
            throw new MetaConnectionException('No se autorizó ninguna página de Facebook. Vuelve a conectar y marca la página que quieres usar.');
        }

        $selection = Str::random(40);
        Cache::put($this->selectionKey($selection), ['workspace' => $workspace->id, 'user' => $user->id, 'pages' => $pages], now()->addMinutes((int) config('integrations.meta.state_minutes')));

        return $selection;
    }

    /** @return array{workspace: int, user: int, pages: list<array{id: string, name: string, token: string}>}|null */
    private function selection(Workspace $workspace, User $user, string $selection): ?array
    {
        $saved = Cache::get($this->selectionKey($selection));

        return is_array($saved) && $saved['workspace'] === $workspace->id && $saved['user'] === $user->id ? $saved : null;
    }

    /** One account belongs to ONE Workspace: another Workspace that already connected it must disconnect it first (an event could not tell whose it is). */
    private function assertNotClaimed(string $type, string $accountId, Workspace $workspace): void
    {
        $other = Integration::where('type', $type)->where('config->account_id', $accountId)->where('workspace_id', '!=', $workspace->id)->exists();

        if ($other) {
            throw new MetaConnectionException('Esa cuenta ya está conectada en otro Workspace. Desconéctala allí antes de usarla aquí.');
        }
    }

    /** Creates or replaces the Workspace's integration of the channel (one per Workspace); what was verified about the old one no longer applies. @param array<string, mixed> $config @param array<string, mixed> $secrets */
    private function store(Workspace $workspace, string $type, array $config, array $secrets): Integration
    {
        $current = $workspace->integrations()->where('type', $type)->first();
        $reset = ['last_tested_at' => null, 'last_test_ok' => null, 'last_test_status' => null, 'last_test_message' => null];

        if ($current) {
            $current->update(['config' => $config, 'secrets' => $secrets, 'is_active' => true] + $reset);

            return $current;
        }

        return $workspace->integrations()->create(['name' => $this->types->get($type)->label(), 'type' => $type, 'is_active' => true, 'config' => $config, 'secrets' => $secrets]);
    }

    /** @throws MetaConnectionException */
    private function assertReady(string $channel): void
    {
        if ($issue = self::platformIssue($channel)) {
            throw new MetaConnectionException($issue);
        }
    }

    private function graph(string $channel, string $path): string
    {
        $base = $channel === 'instagram' ? config('integrations.meta.instagram_graph_url') : config('integrations.meta.facebook_graph_url');

        return rtrim($base, '/').'/'.MetaGraph::version().'/'.ltrim($path, '/');
    }

    private function http(): PendingRequest
    {
        $timeout = (int) config('integrations.meta.timeout');

        return Http::acceptJson()->timeout($timeout)->connectTimeout(min($timeout, 10))->withoutRedirecting();
    }

    private function stateKey(string $state): string
    {
        return 'meta-oauth:'.hash('sha256', $state);
    }

    private function selectionKey(string $selection): string
    {
        return 'meta-pages:'.hash('sha256', $selection);
    }
}
