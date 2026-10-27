<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What can honestly be said about the WhatsApp connection of a Workspace, stage by stage. "Conectado" needs ALL of them: one superficial test proves only the first.
 *  1. credentials: the last real test against Meta's API (token valid for that number);
 *  2. webhook configured in Zonno: the platform has the Meta app secret and the verify token (the webhook answers 404 otherwise);
 *  3. webhook verified by Meta: Meta called the URL with the right verify token (recorded when it happens; not persistent across a cache flush);
 *  4. real traffic: at least one non-simulated message arrived for this Workspace on this channel.
 * Two ways to receive Meta's events, both honest about what they know:
 *  - per Workspace (preferred): the integration has its own public URL `/api/webhooks/whatsapp/{key}`, its own Client Secret (signature of each event) and Verify Token (handshake);
 *    «verified by Meta» is recorded in the integration when the handshake with ITS token happens;
 *  - platform-wide (legacy, one URL for every Workspace, resolved by phone number ID): META_APP_SECRET and META_WEBHOOK_VERIFY_TOKEN of the server. Used only by an
 *    integration saved before the per-Workspace webhook existed.
 * None of these secrets is ever shown.
 */
class WhatsAppConnection
{
    private const VERIFIED_KEY = 'ava:meta:webhook_verified_at';

    public function webhookUrl(): string
    {
        return url('/api/webhooks/whatsapp');
    }

    /** The URL to paste in Meta for this integration: its own when it has one, the platform's otherwise. */
    public function urlFor(?Integration $integration): string
    {
        $key = $integration?->config['webhook_key'] ?? null;

        return is_string($key) && $key !== '' ? url('/api/webhooks/whatsapp/'.$key) : $this->webhookUrl();
    }

    /**
     * Whether Meta could reach the URL at all: HTTPS on a host that is not local or private. The URL is built from the address the application is served at, so one
     * generated while using `localhost` (or an internal address) is right for this machine and useless for Meta. It says nothing about whether Meta DID reach it.
     */
    public static function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || (! str_contains($host, '.') && ! str_contains($host, ':'));

        if (($parts['scheme'] ?? '') !== 'https' || $local) {
            return false;
        }

        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** Whether this integration verifies events itself (Client Secret and Verify Token saved) instead of relying on the platform settings. */
    public function ownWebhook(?Integration $integration): bool
    {
        return $integration !== null && filled($integration->config['webhook_key'] ?? null);
    }

    public function integrationConfigured(?Integration $integration): bool
    {
        return $this->ownWebhook($integration) && filled($integration->secrets['client_secret'] ?? null) && filled($integration->secrets['verify_token'] ?? null);
    }

    /** Records, in the integration, that Meta completed the handshake with its verify token (a fact about the connection, not an edit: no audit event). */
    public function markIntegrationVerified(Integration $integration): void
    {
        $integration->forceFill(['config' => $integration->config + ['webhook_verified_at' => now()->timestamp]])->saveQuietly();
    }

    public function platformConfigured(): bool
    {
        return filled(config('services.meta.app_secret')) && filled(config('services.meta.webhook_verify_token'));
    }

    /** Called by the webhook when Meta passes the verification handshake. */
    public function markVerified(): void
    {
        Cache::forever(self::VERIFIED_KEY, now()->timestamp);
    }

    public function verifiedAt(): ?int
    {
        $at = Cache::get(self::VERIFIED_KEY);

        return $at === null ? null : (int) $at;
    }

    /** The newest real (non-simulated) message received on WhatsApp by this Workspace, or null. */
    public function lastInboundAt(Workspace $workspace): ?int
    {
        $last = DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.workspace_id', $workspace->id)->where('conversations.channel', 'whatsapp')
            ->where('messages.direction', 'in')->where('messages.simulated', false)->max('messages.created_at');

        return $last ? (int) strtotime((string) $last) : null;
    }

    /**
     * @return array{state: string, label: string, tone: string, webhook: array{url: string, configured: bool, public: bool}, stages: list<array{key: string, label: string, done: bool, detail: string}>}
     */
    public function status(Workspace $workspace, ?Integration $integration): array
    {
        $tested = $integration?->last_tested_at !== null;
        $credentials = $tested && (bool) $integration->last_test_ok;
        $own = $this->ownWebhook($integration);
        $configured = $own ? $this->integrationConfigured($integration) : $this->platformConfigured();
        $verified = $own ? isset($integration->config['webhook_verified_at']) : $this->verifiedAt() !== null;
        $traffic = $integration !== null ? $this->lastInboundAt($workspace) : null;

        $stages = [
            ['key' => 'credentials', 'label' => 'Credenciales aceptadas por Meta', 'done' => $credentials,
                'detail' => match (true) {
                    $integration === null => 'Falta guardar el ID del número y el token.',
                    ! $tested => 'Falta pulsar «Probar conexión».',
                    $credentials => 'Meta aceptó el token para este número.',
                    default => 'La última prueba falló: revisa el mensaje de la tarjeta.',
                }],
            ['key' => 'webhook_configured', 'label' => 'Webhook configurado en Zonno', 'done' => $configured,
                'detail' => match (true) {
                    $configured && $own => 'Guardaste el Client Secret y el Verify Token: Zonno ya puede verificar a Meta y firmar sus eventos.',
                    $configured => 'La plataforma tiene el secreto de la app de Meta y el token de verificación.',
                    $own => 'Falta el Client Secret y/o el Verify Token: edita la conexión y guárdalos. Hasta entonces el webhook responde 404.',
                    $integration !== null => 'Edita y guarda la conexión para generar la URL de tu webhook y añadir el Client Secret y el Verify Token.',
                    default => 'Guarda las credenciales para generar la URL de tu webhook.',
                }],
            ['key' => 'webhook_verified', 'label' => 'Webhook verificado por Meta', 'done' => $verified,
                'detail' => $verified ? 'Meta ya verificó la URL.' : 'Pendiente: en Meta for Developers, WhatsApp > Configuración, pega la URL y el token de verificación y pulsa «Verificar y guardar».'],
            ['key' => 'traffic', 'label' => 'Mensajes reales recibidos', 'done' => $traffic !== null,
                'detail' => $traffic !== null ? 'Ya llegó al menos un mensaje real a este Workspace.' : 'Pendiente: suscribe el campo «messages» y envía un mensaje de prueba al número.'],
        ];

        [$state, $label, $tone] = match (true) {
            $integration === null => ['not_configured', 'Sin configurar', 'amber'],
            $tested && ! $credentials => ['error', 'Error de verificación', 'red'],
            ! $tested => ['pending', 'Credenciales guardadas · falta probar la conexión', 'blue'],
            $configured && $verified && $traffic !== null => ['connected', 'Mensaje recibido · WhatsApp conectado', 'green'],
            ! $configured => ['partial', 'Credenciales verificadas · falta configurar el webhook', 'amber'],
            ! $verified => ['partial', 'Webhook pendiente de verificar en Meta', 'amber'],
            default => ['partial', 'Webhook verificado · esperando un mensaje real', 'amber'],
        };

        return ['state' => $state, 'label' => $label, 'tone' => $tone, 'webhook' => ['url' => $this->urlFor($integration), 'configured' => $configured, 'public' => self::isPublicUrl($this->urlFor($integration))], 'stages' => $stages];
    }
}
