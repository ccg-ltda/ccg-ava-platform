<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active'])]
class Organization extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }
}
