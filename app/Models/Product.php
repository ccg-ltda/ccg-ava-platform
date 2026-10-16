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
#[Fillable(['sku', 'name', 'description', 'category', 'price', 'is_active'])]
class Product extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price' => 'decimal:2'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
        return ['sku' => 'SKU', 'name' => 'Nombre', 'description' => 'Descripción', 'category' => 'Categoría', 'price' => 'Precio', 'is_active' => 'Activo'];
    }
}
