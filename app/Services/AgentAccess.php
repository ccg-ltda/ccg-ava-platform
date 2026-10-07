<?php

namespace App\Services;

use App\Models\Chatbot;
use Illuminate\Support\Str;

/**
 * The token an automation (n8n) uses to read the configuration of ONE chatbot from Ava. The token identifies the
 * chatbot, and through it the Workspace, so nothing else is ever sent by the caller. Only its SHA-256 is stored; the
 * plain token exists once, in the response that generates it.
 */
class AgentAccess
{
    private const PREFIX = 'ava_';

    /** Replaces any previous token and returns the new one in plain text (the only time it is available). */
    public function generate(Chatbot $chatbot): string
    {
        $token = self::PREFIX.Str::random(40);

        $chatbot->forceFill([
            'agent_token_hash' => $this->hash($token),
            'agent_token_hint' => substr($token, -4),
            'agent_token_created_at' => now(),
        ])->save();

        return $token;
    }

    public function revoke(Chatbot $chatbot): void
    {
        $chatbot->forceFill(['agent_token_hash' => null, 'agent_token_hint' => null, 'agent_token_created_at' => null])->save();
    }

    public function find(string $token): ?Chatbot
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 128) {
            return null;
        }

        return Chatbot::with('workspace.organization')->where('agent_token_hash', $this->hash($token))->first();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
