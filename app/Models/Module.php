<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Services\ModuleAccess;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A capability a plan can include, identified by its stable `key` (never by its name or category, which can change).
 * Whether it works is not stored here: see `isImplemented()`.
 */
#[Fillable(['key', 'module_category_id', 'name', 'description', 'is_active'])]
class Module extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Only a module declared in config/modules.php has working functionality; a registered one is just a plan for the future. */
    public function isImplemented(): bool
    {
        return app(ModuleAccess::class)->isImplemented($this->key);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ModuleCategory::class, 'module_category_id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class)->withTimestamps();
    }

    public function auditResource(): string
    {
        return 'module';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['key' => 'Identificador', 'name' => 'Nombre', 'description' => 'Descripción', 'is_active' => 'Activo'];
    }

    public function auditSnapshot(Closure $get): array
    {
        return $this->auditBaseSnapshot($get) + ['Categoría' => ModuleCategory::whereKey($get('module_category_id'))->value('name')];
    }
}
