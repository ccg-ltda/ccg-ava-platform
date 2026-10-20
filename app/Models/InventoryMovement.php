<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of the trace of a stock: what changed, the quantity before and after, who did it and, for a sale, the order. Append-only; written only by InventoryService. */
#[Fillable(['workspace_id', 'product_id', 'location_id', 'type', 'quantity_change', 'quantity_before', 'quantity_after', 'user_id', 'order_id', 'reason'])]
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    public const ENTRY = 'entry';

    public const ADJUSTMENT = 'adjustment';

    public const SALE = 'sale';

    public const CANCELLATION = 'cancellation';

    /** How the screen names each type. */
    public const LABELS = [self::ENTRY => 'Entrada', self::ADJUSTMENT => 'Ajuste', self::SALE => 'Venta', self::CANCELLATION => 'Cancelación'];

    protected function casts(): array
    {
        return ['quantity_change' => 'integer', 'quantity_before' => 'integer', 'quantity_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
