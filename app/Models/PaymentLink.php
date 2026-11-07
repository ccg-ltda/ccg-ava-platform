<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The temporary, revocable link a customer opens to pay one payable. Only the SHA-256 of the token is stored; the token itself exists once, when the link is created. */
#[Fillable(['workspace_id', 'payable_type', 'payable_id', 'token_hash', 'expires_at', 'revoked_at', 'created_by'])]
class PaymentLink extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
