<?php

namespace App\Integrations;

use App\Models\Integration;
use Illuminate\Http\Client\Response;

/**
 * Messenger for ONE Workspace's Facebook Page, authorized by a person who can manage it (Facebook Login). The token is that Page's access token; the account is the Page ID.
 * Replies go to the contact's Page-scoped ID (PSID) with `POST graph.facebook.com/{version}/{page-id}/messages` as a `RESPONSE` message (inside the 24-hour window).
 */
class MessengerType extends MetaMessagingType
{
    public function key(): string
    {
        return 'messenger';
    }

    public function label(): string
    {
        return 'Messenger';
    }

    protected function provider(): string
    {
        return 'messenger';
    }

    protected function accountNoun(): string
    {
        return 'página de Facebook';
    }

    protected function url(string $path): string
    {
        return rtrim(config('integrations.meta.facebook_graph_url'), '/').'/'.MetaGraph::version().'/'.ltrim($path, '/');
    }

    protected function identityRequest(Integration $integration): array
    {
        return [(string) ($integration->config['account_id'] ?? 'me'), 'id,name'];
    }

    protected function identityMatches(Response $response, Integration $integration): bool
    {
        return (string) $response->json('id') !== '' && (string) $response->json('id') === (string) ($integration->config['account_id'] ?? '');
    }

    protected function sendPath(Integration $integration): string
    {
        return ($integration->config['account_id'] ?? 'me').'/messages';
    }

    protected function messageBody(string $to, string $text): array
    {
        return ['recipient' => ['id' => $to], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $text]];
    }
}
