<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join ONE Workspace with ONE role. The token itself is never stored: only its SHA-256 (`token_hash`). Created only through
 * `App\Services\WorkspaceInvitations`.
 */
#[Fillable(['workspace_id', 'email', 'name', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at', 'accepted_user_id'])]
class WorkspaceInvitation extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::ACCEPTED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === self::PENDING;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
