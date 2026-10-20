<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A valid category of the products of a Workspace. `normalized_name` (lower case, single spaces) is unique per Workspace, so the same text written with another
 * capitalization or spacing is the same category. Created only through `App\Orders\ProductCategories`; never deleted (a category with products cannot be).
 */
#[Fillable(['workspace_id', 'name', 'normalized_name', 'is_active'])]
class ProductCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
