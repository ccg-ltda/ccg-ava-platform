<?php

namespace App\Http\Controllers;

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
}
