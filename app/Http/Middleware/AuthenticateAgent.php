<?php

namespace App\Http\Middleware;

use App\Services\AgentAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an automation (n8n) by the access token of a chatbot (`Authorization: Bearer ava_...`). The request
 * then works on that chatbot and its Workspace only: both are placed in the request attributes and nothing the caller
 * sends can change them. An unknown token is a 401; a known one whose chatbot, Workspace or Organization is
 * disabled is a 403, so a misconfigured workflow can tell why it stopped.
 */
class AuthenticateAgent
{
    public function __construct(private readonly AgentAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $chatbot = $token ? $this->access->find($token) : null;

        abort_unless($chatbot, 401, 'Token inválido.');
        abort_unless($chatbot->is_active && $chatbot->workspace->is_active && $chatbot->workspace->organization->is_active, 403, 'El chatbot, su Workspace o su organización están desactivados.');

        // The only real evidence that n8n is wired to this chatbot; at most once a minute, with no audit event and
        // without touching `updated_at` (the chatbot did not change).
        if ($chatbot->agent_last_seen_at === null || $chatbot->agent_last_seen_at->lt(now()->subMinute())) {
            DB::table('chatbots')->where('id', $chatbot->id)->update(['agent_last_seen_at' => now()]);
        }

        $request->attributes->set('chatbot', $chatbot);
        $request->attributes->set('workspace', $chatbot->workspace);

        return $next($request);
    }
}
