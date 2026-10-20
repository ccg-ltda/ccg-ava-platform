<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The quantity of ONE product in ONE location (`location_id` NULL = the Workspace's general stock). The product is the same everywhere; only the stock varies by
 * place, and stocks of different locations are never added up as if they were available in one. Changed only through `App\Orders\InventoryService`, which
 * writes the movement that explains each change.
 */
#[Fillable(['workspace_id', 'product_id', 'location_id', 'quantity', 'min_quantity'])]
class InventoryStock extends Model
{
    public const OUT = 'out';

    public const LOW = 'low';

    public const OK = 'ok';

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'min_quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** `out` (nothing left), `low` (at or below the minimum of that location) or `ok`. A deactivated product is a different condition (see Product::is_active). */
    public function level(): string
    {
        return match (true) {
            $this->quantity <= 0 => self::OUT,
            $this->min_quantity > 0 && $this->quantity <= $this->min_quantity => self::LOW,
            default => self::OK,
        };
    }
}
