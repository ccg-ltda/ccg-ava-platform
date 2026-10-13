<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A commercial plan: the modules a Workspace may use. Plans are created by CCG and never deleted, only deactivated. */
#[Fillable(['key', 'name', 'description', 'is_active'])]
class Plan extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** The system plan that every Workspace without an explicit choice falls back to (config `modules.default_plan`). */
    public function isDefault(): bool
    {
        return $this->key !== null && $this->key === config('modules.default_plan');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class)->withTimestamps();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function auditResource(): string
    {
        return 'plan';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['name' => 'Nombre', 'description' => 'Descripción', 'is_active' => 'Activo'];
    }
}
