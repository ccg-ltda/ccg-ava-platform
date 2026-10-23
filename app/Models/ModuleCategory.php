<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/** An organizational group of modules (Salud, Delivery...). It limits nothing: modules of any category combine in a plan. */
#[Fillable(['key', 'name', 'description', 'sort_order', 'is_active', 'is_public'])]
class ModuleCategory extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_public' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * Whether the category is a commercial SOLUTION (Salud, Delivery, eCommerce, Servicios y Reservas, or one created in Planes) and not a group of shared
     * capabilities («Módulos compartidos», «Clientes y comunicación»): config/modules.php `categories.*.solution` (false for those two; true by default,
     * which is what a category created in Planes gets). Only a solution can be chosen for a plan in the public sign-up.
     */
    public function isSolution(): bool
    {
        return (bool) config("modules.categories.{$this->key}.solution", true);
    }

    /** Whether the database has the public-visibility column (migration `split_solution_visibility_from_activation`). */
    public static function visibilityReady(): bool
    {
        return Schema::hasColumn('module_categories', 'is_public');
    }

    /**
     * Whether the solution may be OFFERED in the public sign-up. It needs to be active AND public. On a database without the column the earlier behavior
     * stays (active = offered), so a deployment ahead of its migration does not lose its public offer.
     */
    public function isPubliclyOffered(): bool
    {
        return $this->is_active && (! self::visibilityReady() || (bool) $this->is_public);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    /** The plans sold under this solution (`plans.module_category_id`). */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
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
        return ['key' => 'Identificador', 'name' => 'Nombre', 'description' => 'Descripción', 'is_active' => 'Activa', 'is_public' => 'Pública'];
    }
}
