<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\AgentToken;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The token an automation (n8n) uses to read the configuration of ONE chatbot from Ava. The token identifies the chatbot, and through it the Workspace, so nothing
 * else is ever sent by the caller. Only its SHA-256 is stored; the plain token exists once, in the response that issues it.
 *
 * Lifecycle (ledger `agent_tokens`, policy of RECOMENDACIONES.md §75):
 *  - VALIDITY: a new token lasts `chatbots.agent_token.days` (90). An expired token is refused with a clear, traceable error; the team is warned beforehand
 *    (App\Services\AgentTokenNotices).
 *  - LAST USE: every token records its first and last use (the last one is refreshed at most once a minute).
 *  - ROTATION without interruption: `rotate()` issues a new token and leaves the old one valid. The old one is retired automatically ONLY after the new one has
 *    been used once (proof that n8n holds it), or when it expires, or when a person revokes it. If the new token is never used, the old one keeps working until
 *    its own expiry: a failed or forgotten rotation never cuts the integration off.
 *  - REVOCATION is immediate (`revoke`, `revokeToken`): for a compromised token.
 *  - `generate()` is the immediate replacement (the previous behavior): every other token stops working at once.
 * `chatbots.agent_token_hash / hint / created_at` mirror the CURRENT token (the newest usable one) for the interface and older code. Without the ledger table (a database
 * not migrated yet) everything falls back to that single token with no expiry.
 */
class AgentAccess
{
    public const PREFIX = 'ava_';

    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('agent_tokens');
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    public static function validityDays(): int
    {
        return max(1, (int) config('chatbots.agent_token.days', 90));
    }

    /** Immediate replacement: every previous token stops working now, and the new one is returned in plain text (the only time it is available). */
    public function generate(Chatbot $chatbot, ?User $by = null): string
    {
        return DB::transaction(function () use ($chatbot, $by) {
            if (self::ready()) {
                $this->revokeActive($chatbot, 'replaced');
            }

            return $this->issue($chatbot, $by);
        });
    }

    /**
     * Issues a replacement WITHOUT invalidating anything. The tokens that were valid are marked as superseded and keep working until the new token is first used.
     * A replacement that was never used is itself replaced (it cannot be in n8n yet), so tokens do not pile up.
     */
    public function rotate(Chatbot $chatbot, ?User $by = null): string
    {
        if (! self::ready()) {
            return $this->generate($chatbot, $by);
        }

        $token = DB::transaction(function () use ($chatbot, $by) {
            Chatbot::whereKey($chatbot->id)->lockForUpdate()->first();

            $active = $this->usable($chatbot)->orderByDesc('id')->get();
            $newest = $active->first();

            if ($newest && $newest->first_used_at === null && $active->count() > 1) {
                $newest->forceFill(['revoked_at' => now(), 'revoked_reason' => 'replaced_unused'])->save();
                $active = $active->skip(1)->values();
            }

            foreach ($active as $old) {
                if ($old->superseded_at === null) {
                    $old->forceFill(['superseded_at' => now()])->save();
                }
            }

            return $this->issue($chatbot, $by);
        });

        $this->audit($chatbot, 'Se emitió un token de reemplazo: el anterior sigue activo hasta que el nuevo se use');

        return $token;
    }

    /** Revokes every token of the chatbot now (nothing can read it until a new one is issued). */
    public function revoke(Chatbot $chatbot, ?User $by = null): void
    {
        DB::transaction(function () use ($chatbot) {
            if (self::ready()) {
                $this->revokeActive($chatbot, 'revoked');
            }

            $chatbot->forceFill(['agent_token_hash' => null, 'agent_token_hint' => null, 'agent_token_created_at' => null])->save();
        });
    }

    /** Revokes ONE token of this chatbot (for example the old one after a rotation, or a compromised one). Returns false when it is not an active token of the chatbot. */
    public function revokeToken(Chatbot $chatbot, int $tokenId, ?User $by = null): bool
    {
        if (! self::ready()) {
            return false;
        }

        $revoked = DB::transaction(function () use ($chatbot, $tokenId) {
            $token = AgentToken::where('chatbot_id', $chatbot->id)->where('workspace_id', $chatbot->workspace_id)->whereNull('revoked_at')->whereKey($tokenId)->lockForUpdate()->first();

            if (! $token) {
                return false;
            }

            $token->forceFill(['revoked_at' => now(), 'revoked_reason' => 'revoked'])->save();
            $this->syncMirror($chatbot);

            return true;
        });

        if ($revoked) {
            $this->audit($chatbot, 'Se revocó un token del agente');
        }

        return $revoked;
    }

    /**
     * What a presented token is: `ok` (with its chatbot and ledger row), `expired`, `revoked` or `unknown`. Never throws and never writes anything for a refused token.
     *
     * @return array{status: string, chatbot: ?Chatbot, token: ?AgentToken}
     */
    public function authenticate(string $plain): array
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 128) {
            return ['status' => 'unknown', 'chatbot' => null, 'token' => null];
        }

        $hash = $this->hash($plain);

        if (! self::ready()) {
            $chatbot = Chatbot::with('workspace.organization')->where('agent_token_hash', $hash)->first();

            return ['status' => $chatbot ? 'ok' : 'unknown', 'chatbot' => $chatbot, 'token' => null];
        }

        $token = AgentToken::where('token_hash', $hash)->first() ?? $this->adoptMirrored($hash);

        if (! $token) {
            return ['status' => 'unknown', 'chatbot' => null, 'token' => null];
        }

        $chatbot = Chatbot::with('workspace.organization')->find($token->chatbot_id);

        return match (true) {
            $token->isRevoked() => ['status' => 'revoked', 'chatbot' => $chatbot, 'token' => $token],
            $token->isExpired() => ['status' => 'expired', 'chatbot' => $chatbot, 'token' => $token],
            default => ['status' => $chatbot ? 'ok' : 'unknown', 'chatbot' => $chatbot, 'token' => $token],
        };
    }

    public function find(string $plain): ?Chatbot
    {
        $result = $this->authenticate($plain);

        return $result['status'] === 'ok' ? $result['chatbot'] : null;
    }

    /**
     * Records a successful use: first and last use, and — the moment a REPLACEMENT is used for the first time — the retirement of the tokens it replaces (the proof that n8n
     * holds the new one). At most one write a minute per token in the usual case.
     */
    public function used(AgentToken $token, Chatbot $chatbot): void
    {
        $firstUse = $token->first_used_at === null;

        if (! $firstUse && $token->last_used_at !== null && $token->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $update = ['last_used_at' => now()] + ($firstUse ? ['first_used_at' => now()] : []);
        AgentToken::whereKey($token->id)->update($update);
        $token->forceFill($update)->syncOriginal();

        if ($firstUse && $token->superseded_at === null) {
            $retired = AgentToken::where('chatbot_id', $chatbot->id)->where('id', '!=', $token->id)->whereNull('revoked_at')->whereNotNull('superseded_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'rotated']);

            if ($retired > 0) {
                $this->syncMirror($chatbot);
                $this->audit($chatbot, 'El token de reemplazo ya se usa: se retiró el anterior', true);
            }
        }
    }

    /**
     * What the interface shows for a chatbot's token: the current one, its state, whether a rotation is waiting for its first use, and the older tokens still valid.
     *
     * @return array{configured: bool, current: ?array<string, mixed>, pendingRotation: bool, previous: list<array<string, mixed>>, validityDays: int, ledger: bool}
     */
    public function status(Chatbot $chatbot): array
    {
        if (! self::ready()) {
            return ['configured' => $chatbot->agent_token_hash !== null, 'current' => null, 'pendingRotation' => false, 'previous' => [], 'validityDays' => self::validityDays(), 'ledger' => false];
        }

        $active = $this->usable($chatbot)->orderByDesc('id')->get();
        $current = $active->first();
        $notify = (array) config('chatbots.agent_token.notify_days', [30]);
        $window = $notify === [] ? 30 : max($notify);

        $shape = fn (AgentToken $t) => [
            'id' => $t->id, 'hint' => $t->hint, 'createdAt' => $t->created_at, 'expiresAt' => $t->expires_at, 'daysLeft' => $t->daysLeft(),
            'lastUsedAt' => $t->last_used_at, 'used' => $t->first_used_at !== null, 'legacy' => $t->legacy,
            'state' => $t->daysLeft() <= $window ? 'expiring' : 'ok',
        ];

        return [
            'configured' => $current !== null,
            'current' => $current ? $shape($current) : null,
            'pendingRotation' => $current !== null && $current->first_used_at === null && $active->count() > 1,
            'previous' => $active->skip(1)->map($shape)->values()->all(),
            'validityDays' => self::validityDays(),
            'ledger' => true,
        ];
    }

    // --- internals -----------------------------------------------------------------------------------------------------------------

    private function issue(Chatbot $chatbot, ?User $by): string
    {
        $token = self::PREFIX.Str::random(40);
        $created = now();

        if (self::ready()) {
            AgentToken::create([
                'chatbot_id' => $chatbot->id, 'workspace_id' => $chatbot->workspace_id, 'token_hash' => $this->hash($token), 'hint' => substr($token, -4),
                'created_by_user_id' => $by?->id, 'created_at' => $created, 'expires_at' => $created->copy()->addDays(self::validityDays()),
            ]);
        }

        $chatbot->forceFill(['agent_token_hash' => $this->hash($token), 'agent_token_hint' => substr($token, -4), 'agent_token_created_at' => $created])->save();

        return $token;
    }

    /** @return Builder<AgentToken> */
    private function usable(Chatbot $chatbot)
    {
        return AgentToken::usable()->where('chatbot_id', $chatbot->id)->where('workspace_id', $chatbot->workspace_id);
    }

    private function revokeActive(Chatbot $chatbot, string $reason): void
    {
        AgentToken::where('chatbot_id', $chatbot->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    /** The mirror columns follow the newest usable token (or empty when there is none). */
    private function syncMirror(Chatbot $chatbot): void
    {
        $current = $this->usable($chatbot)->orderByDesc('id')->first();

        $chatbot->forceFill([
            'agent_token_hash' => $current?->token_hash, 'agent_token_hint' => $current?->hint, 'agent_token_created_at' => $current?->created_at,
        ])->save();
    }

    /** A token that is only in the chatbot's mirror columns (issued before the ledger existed on this database) is adopted with the same rule as the migration. */
    private function adoptMirrored(string $hash): ?AgentToken
    {
        $chatbot = Chatbot::where('agent_token_hash', $hash)->first();

        if (! $chatbot) {
            return null;
        }

        $created = $chatbot->agent_token_created_at ? Carbon::parse($chatbot->agent_token_created_at) : now();
        $expires = $created->copy()->addDays(self::validityDays())->max(now()->addDays((int) config('chatbots.agent_token.legacy_grace_days', 30)));

        return AgentToken::firstOrCreate(['token_hash' => $hash], [
            'chatbot_id' => $chatbot->id, 'workspace_id' => $chatbot->workspace_id, 'hint' => $chatbot->agent_token_hint, 'created_at' => $created, 'expires_at' => $expires,
            'first_used_at' => $chatbot->agent_last_seen_at, 'last_used_at' => $chatbot->agent_last_seen_at, 'legacy' => true,
        ]);
    }

    /** One audit line about the token of a chatbot: never a token, a hash or its characters. */
    private function audit(Chatbot $chatbot, string $text, bool $automatic = false): void
    {
        try {
            $changes = [['field' => 'Token de acceso del agente', 'before' => null, 'after' => $text]];
            $workspace = $chatbot->workspace;

            $automatic
                ? app(AuditLogger::class)->recordForActor('updated', 'chatbot', $chatbot->id, $chatbot->name, $changes, $workspace, 'Rotación de tokens (automática)')
                : app(AuditLogger::class)->record('updated', 'chatbot', $chatbot->id, $chatbot->name, $changes, $workspace);
        } catch (\Throwable) {
            // The audit trail must never break the operation it describes.
        }
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
