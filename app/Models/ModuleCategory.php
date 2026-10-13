<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An organizational group of modules (Salud, Delivery...). It limits nothing: modules of any category combine in a plan. */
#[Fillable(['key', 'name', 'description', 'sort_order', 'is_active'])]
class ModuleCategory extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    public function auditResource(): string
    {
        return 'module_category';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['key' => 'Identificador', 'name' => 'Nombre', 'description' => 'Descripción', 'is_active' => 'Activa'];
    }
}
