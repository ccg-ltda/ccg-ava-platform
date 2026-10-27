<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the Workspace sells. Created and read only through `$workspace->products()`; never deleted, only deactivated (an inactive product is not
 * offered for new orders, and the orders that already used it keep their own copy of its name, sku and price).
 * It is NOT a bookable service: those have a duration and a professional and live in Servicios y Reservas.
 */
#[Fillable(['sku', 'name', 'description', 'category', 'category_id', 'image_path', 'price', 'cost', 'is_active', 'tracks_inventory', 'offers_all_locations'])]
class Product extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'tracks_inventory' => 'boolean', 'offers_all_locations' => 'boolean', 'price' => 'decimal:2', 'cost' => 'decimal:2'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** The normalized category (`category` keeps a copy of its name for the consumers that read the text). */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** The stock of this product, one row per location (and one for the general stock when the Workspace has no locations). */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** The locations that offer it; none = offered in every location. */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_product')->withPivot('workspace_id');
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function auditResource(): string
    {
        return 'product';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['sku' => 'SKU', 'name' => 'Nombre', 'description' => 'Descripción', 'category' => 'Categoría', 'price' => 'Precio', 'cost' => 'Coste', 'is_active' => 'Activo', 'tracks_inventory' => 'Control de inventario'];
    }
}
