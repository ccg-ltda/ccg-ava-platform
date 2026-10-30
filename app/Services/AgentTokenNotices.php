<?php

namespace App\Services;

use App\Models\AgentToken;
use App\Models\Chatbot;

/**
 * Tells the team about a chatbot token BEFORE it stops working, and when it already has, so an expiry is never a silent failure of the integration.
 * One notification per threshold of `chatbots.agent_token.notify_days` (only the smallest one that applies, so a token found at 5 days gets the "7 days" notice, not
 * three at once), deduplicated by key; one more when a token has expired, and one a day while an automation keeps presenting an expired or revoked token.
 * The text never contains a token, a hash or its characters. The permission is `manage-agent-tokens`: only people who can fix it see it.
 */
class AgentTokenNotices
{
    private const PERMISSION = 'manage-agent-tokens';

    public function __construct(private readonly Notifications $notifications) {}

    /** The scheduled pass. Returns how many notifications it created. */
    public function sweep(): int
    {
        if (! AgentAccess::ready() || ! Notifications::ready()) {
            return 0;
        }

        $created = 0;
        $thresholds = collect((array) config('chatbots.agent_token.notify_days', [30, 14, 7, 3, 1]))->map(fn ($d) => (int) $d)->sort()->values();

        // Only the CURRENT token of each chatbot (not one already replaced) is worth a warning, and only while the chatbot and its Workspace are active.
        AgentToken::query()->whereNull('revoked_at')->whereNull('superseded_at')->with('chatbot.workspace')->orderBy('id')->chunkById(200, function ($tokens) use (&$created, $thresholds) {
            foreach ($tokens as $token) {
                $chatbot = $token->chatbot;

                if (! $chatbot || ! $chatbot->is_active || ! $chatbot->workspace?->is_active) {
                    continue;
                }

                $created += $this->forToken($token, $chatbot, $thresholds->all()) ? 1 : 0;
            }
        });

        return $created;
    }

    /** @param  list<int>  $thresholds  ascending */
    private function forToken(AgentToken $token, Chatbot $chatbot, array $thresholds): bool
    {
        if ($token->isExpired()) {
            return $this->send($chatbot, "agent-token:{$token->id}:expired", 'agent_token_expired', "El token del agente de «{$chatbot->name}» venció", 'n8n ya no puede leer este chatbot. Genera un token nuevo y actualízalo en n8n.') !== null;
        }

        $days = $token->daysLeft();
        $threshold = collect($thresholds)->first(fn (int $t) => $days <= $t);

        if ($threshold === null) {
            return false;
        }

        $when = $token->expires_at->format('Y-m-d');
        $text = $days <= 1 ? 'vence mañana o hoy' : "vence en {$days} días";

        return $this->send($chatbot, "agent-token:{$token->id}:t{$threshold}", 'agent_token_expiring', "El token del agente de «{$chatbot->name}» {$text}", "Vigente hasta {$when}. Usa «Rotar token»: el actual sigue funcionando hasta que n8n use el nuevo.") !== null;
    }

    /** An automation presented an expired or revoked token: at most one notification per token per day, so the failure is visible and does not flood. */
    public function refusedUse(Chatbot $chatbot, AgentToken $token, string $status): void
    {
        if ($chatbot->workspace === null) {
            return;
        }

        $expired = $status === 'expired';

        $this->send(
            $chatbot,
            "agent-token:{$token->id}:refused:{$status}:".now()->format('Ymd'),
            'agent_token_refused',
            $expired ? "n8n usó un token vencido de «{$chatbot->name}»" : "n8n usó un token revocado de «{$chatbot->name}»",
            $expired ? 'La automatización fue rechazada. Genera o rota el token y actualízalo en n8n.' : 'La automatización fue rechazada. Si no fue intencional, genera un token nuevo y actualízalo en n8n.',
        );
    }

    private function send(Chatbot $chatbot, string $dedupe, string $type, string $title, string $body)
    {
        return $this->notifications->notify($chatbot->workspace, $type, $title, $body, route('chatbots.show', $chatbot->id, false), self::PERMISSION, null, 'chatbot', $chatbot->id, $dedupe);
    }
}
