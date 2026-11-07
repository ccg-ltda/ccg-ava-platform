<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What happened to a payment transaction. Append-only: written by PaymentService, never edited. `event_key` is unique per transaction (replay protection). */
#[Fillable(['workspace_id', 'payment_transaction_id', 'kind', 'source', 'event_key', 'from_status', 'to_status', 'outcome', 'code', 'http_status', 'duration_ms', 'detail'])]
class PaymentTransactionEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['detail' => 'array', 'created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
