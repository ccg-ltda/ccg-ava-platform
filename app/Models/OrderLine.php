<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product of an order as it was bought: its name, sku and unit price are copies, so the catalog can change without touching the past. */
#[Fillable(['workspace_id', 'order_id', 'product_id', 'product_name', 'product_sku', 'unit_price', 'quantity', 'line_total'])]
class OrderLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
