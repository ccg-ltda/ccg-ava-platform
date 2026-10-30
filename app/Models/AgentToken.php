<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One access token of a chatbot's automation, as a ledger row (see the migration `create_agent_tokens_table` and App\Services\AgentAccess). It holds only the SHA-256
 * of the token. It is written only by AgentAccess, never from request data.
 */
class AgentToken extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['chatbot_id', 'workspace_id', 'token_hash', 'hint', 'created_by_user_id', 'created_at', 'expires_at', 'first_used_at', 'last_used_at', 'superseded_at', 'revoked_at', 'revoked_reason', 'legacy'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime', 'expires_at' => 'datetime', 'first_used_at' => 'datetime', 'last_used_at' => 'datetime', 'superseded_at' => 'datetime', 'revoked_at' => 'datetime', 'legacy' => 'boolean',
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    /** Not revoked and not expired. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    public function daysLeft(): int
    {
        return (int) max(0, ceil(now()->diffInSeconds($this->expires_at, false) / 86400));
    }
}
