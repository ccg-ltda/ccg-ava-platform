<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A valid category of the services of a Workspace. `normalized_name` (lower case, single spaces) is unique per Workspace, so the same text written with another
 * capitalization or spacing is the same category. Created only through `App\Booking\ServiceCategories`; never deleted (it is deactivated).
 */
#[Fillable(['workspace_id', 'name', 'normalized_name', 'is_active'])]
class ServiceCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
