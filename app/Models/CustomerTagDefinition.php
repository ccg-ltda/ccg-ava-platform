<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A valid tag of the customers of a Workspace. `normalized_name` (NFC, single spaces, lower case) is unique per Workspace. Created only through
 * `App\Customers\CustomerTagCatalog`. A tag in use is deactivated, never deleted.
 */
#[Fillable(['workspace_id', 'name', 'normalized_name', 'is_active'])]
class CustomerTagDefinition extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerTag::class, 'tag_id');
    }
}
