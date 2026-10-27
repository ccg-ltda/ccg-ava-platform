<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\WhatsAppConnection;
use App\Services\WhatsAppInbound;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta's WhatsApp Cloud API webhook. It exists only when `META_APP_SECRET` and `META_WEBHOOK_VERIFY_TOKEN` are set
 * (otherwise 404, so an unconfigured Ava exposes nothing). Meta verifies the URL with a GET (the verify token) and then
 * posts every event signed with the app secret (`X-Hub-Signature-256`); an event whose signature does not match the raw
 * body is refused (403) before anything is read or stored. The answer is sent only AFTER the messages were stored, so a
 * failure makes Meta retry (the stored `external_id` makes that harmless).
 */
class WhatsAppWebhookController extends Controller
{
    private const MAX_BYTES = 1_000_000;

    public function verify(Request $request): Response
    {
        $token = config('services.meta.webhook_verify_token');
        abort_unless(filled($token) && filled(config('services.meta.app_secret')), 404);

        $given = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        abort_unless($request->query('hub_mode') === 'subscribe' && is_string($given) && hash_equals($token, $given) && is_string($challenge), 403);

        // Only the handshake with the right token counts as «verified by Meta» (shown in Integraciones).
        app(WhatsAppConnection::class)->markVerified();

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, WhatsAppInbound $inbound): Response
    {
        $secret = config('services.meta.app_secret');
        abort_unless(filled($secret) && filled(config('services.meta.webhook_verify_token')), 404);

        $raw = $request->getContent();
        abort_if(strlen($raw) > self::MAX_BYTES, 413);

        $signature = (string) $request->header('X-Hub-Signature-256');
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature), 403);

        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400);

        $inbound->handle($payload);

        return response('', 200);
    }

    /**
     * Meta's handshake for ONE Workspace's webhook: the verify token is the one saved in that integration. An unknown key, an integration that is switched off and one
     * without a verify token are all the same 404, so the URL reveals nothing.
     */
    public function verifyIntegration(Request $request, string $key): Response
    {
        $integration = $this->integration($key);
        $token = $integration->secrets['verify_token'] ?? null;
        abort_unless(filled($token), 404);

        $given = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        abort_unless($request->query('hub_mode') === 'subscribe' && is_string($given) && hash_equals($token, $given) && is_string($challenge), 403);

        app(WhatsAppConnection::class)->markIntegrationVerified($integration);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Events of ONE Workspace's number, signed by Meta with the Client Secret saved in that integration. Only events about that integration's own number are used.
     * A repeated delivery stores nothing twice (the message id is the idempotency key); Meta's signature carries no timestamp, so replay is bounded by that, not by a window.
     */
    public function receiveIntegration(Request $request, WhatsAppInbound $inbound, string $key): Response
    {
        $integration = $this->integration($key);
        $secret = $integration->secrets['client_secret'] ?? null;
        abort_unless(filled($secret), 404);

        $raw = $request->getContent();
        abort_if(strlen($raw) > self::MAX_BYTES, 413);
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), (string) $request->header('X-Hub-Signature-256')), 403);

        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400);

        $inbound->handle($payload, $integration);

        return response('', 200);
    }

    private function integration(string $key): Integration
    {
        return Integration::where('type', 'whatsapp')->where('is_active', true)->where('config->webhook_key', $key)->firstOrFail();
    }
}
