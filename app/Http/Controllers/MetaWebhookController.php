<?php

namespace App\Http\Controllers;

use App\Services\MetaMessagingInbound;
use App\Services\PlatformMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The webhook of the platform's Meta app for Instagram Messaging and Messenger (`/api/webhooks/meta`). It does not exist (404) until the verify token and at least one app secret are
 * configured. Meta verifies the URL with a GET (the verify token) and then posts every event signed with the app secret (`X-Hub-Signature-256` over the RAW body): an event whose signature
 * matches none of the platform's app secrets is refused (403) before anything is read or stored. Which Workspace an event belongs to is never taken from the request: it comes from the
 * integration Ava holds for the account id (see MetaMessagingInbound). The answer is sent only AFTER the messages were stored, so a failure makes Meta retry (the message id makes that harmless).
 */
class MetaWebhookController extends Controller
{
    private const MAX_BYTES = 1_000_000;

    public function verify(Request $request): Response
    {
        $token = PlatformMeta::get('webhook_verify_token');
        abort_unless(filled($token) && $this->secrets() !== [], 404);

        $given = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        abort_unless($request->query('hub_mode') === 'subscribe' && is_string($given) && hash_equals($token, $given) && is_string($challenge), 403);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, MetaMessagingInbound $inbound): Response
    {
        abort_unless(filled(PlatformMeta::get('webhook_verify_token')) && $this->secrets() !== [], 404);

        $raw = $request->getContent();
        abort_if(strlen($raw) > self::MAX_BYTES, 413);

        $signature = (string) $request->header('X-Hub-Signature-256');
        abort_unless(collect($this->secrets())->contains(fn (string $secret) => hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)), 403);

        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400);

        // Meta's documentation shows the notification wrapped in a list in some setups: both shapes are accepted.
        foreach (array_is_list($payload) ? $payload : [$payload] as $notification) {
            if (is_array($notification)) {
                $inbound->handle($notification);
            }
        }

        return response('', 200);
    }

    /** @return list<string> the app secrets that may sign an event (Facebook app and Instagram app) */
    private function secrets(): array
    {
        return array_values(array_filter([PlatformMeta::get('app_secret'), PlatformMeta::get('instagram_app_secret')], 'filled'));
    }
}
