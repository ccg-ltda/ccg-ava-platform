<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of an order's trace: the state it left, the state it entered, who did it and why. Written only by App\Orders\OrderService. */
#[Fillable(['workspace_id', 'order_id', 'from_status', 'to_status', 'user_id', 'reason'])]
class OrderStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
