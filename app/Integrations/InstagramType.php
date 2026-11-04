<?php

namespace App\Integrations;

use App\Models\Integration;
use Illuminate\Http\Client\Response;

/**
 * Instagram Messaging through «Instagram API with Instagram Login»: the Instagram professional account (Business or Creator) of ONE Workspace, authorized by its owner. The token
 * is the long-lived Instagram User access token (about 60 days, renewable); the account is identified by its Instagram professional account ID. Replies go to the contact's
 * Instagram-scoped ID (IGSID) with `POST graph.instagram.com/{version}/{account-id}/messages`.
 */
class InstagramType extends MetaMessagingType
{
    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    protected function provider(): string
    {
        return 'instagram';
    }

    protected function accountNoun(): string
    {
        return 'cuenta profesional de Instagram';
    }

    protected function url(string $path): string
    {
        return rtrim(config('integrations.meta.instagram_graph_url'), '/').'/'.MetaGraph::version().'/'.ltrim($path, '/');
    }

    protected function identityRequest(Integration $integration): array
    {
        return ['me', 'user_id,username'];
    }

    protected function identityMatches(Response $response, Integration $integration): bool
    {
        $account = (string) ($integration->config['account_id'] ?? '');

        return $account !== '' && in_array($account, [(string) $response->json('user_id'), (string) $response->json('id')], true);
    }

    protected function sendPath(Integration $integration): string
    {
        return ($integration->config['account_id'] ?? 'me').'/messages';
    }

    protected function messageBody(string $to, string $text): array
    {
        return ['recipient' => ['id' => $to], 'message' => ['text' => $text]];
    }
}
