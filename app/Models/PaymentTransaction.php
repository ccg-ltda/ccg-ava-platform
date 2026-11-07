<?php

namespace App\Models;

use App\Payments\Gateways\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to collect a payable through one gateway connection. Created and moved ONLY by App\Payments\Gateways\PaymentService (state machine, locks, events).
 * `secrets` is encrypted at rest and hidden from serialization. Identifiers never overlap: `reference` is Ava's, `provider_reference` is what Ava sent the provider,
 * `external_id` is what the provider answered.
 */
#[Fillable(['workspace_id', 'integration_id', 'payment_link_id', 'provider', 'environment', 'payable_type', 'payable_id', 'reference', 'provider_reference', 'external_id', 'authorization_code', 'refund_external_id', 'secrets', 'callback_token_hash', 'amount_minor', 'currency', 'status', 'failure_code', 'idempotency_key', 'active_key', 'metadata', 'expires_at', 'confirmed_at', 'last_checked_at', 'check_attempts', 'next_check_at', 'watch_until'])]
#[Hidden(['secrets', 'callback_token_hash'])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'secrets' => 'encrypted:array', 'metadata' => 'array', 'amount_minor' => 'integer', 'check_attempts' => 'integer',
            'expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'last_checked_at' => 'datetime', 'next_check_at' => 'datetime', 'watch_until' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentTransactionEvent::class)->orderBy('id');
    }

    public function state(): PaymentStatus
    {
        return PaymentStatus::from($this->status);
    }
}
