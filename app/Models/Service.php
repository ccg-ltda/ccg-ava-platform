<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a Workspace offers to be booked. Created and read only through `$workspace->services()`; never deleted, only deactivated. */
#[Fillable(['name', 'description', 'category', 'category_id', 'duration_minutes', 'buffer_minutes', 'price', 'is_active'])]
class Service extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'duration_minutes' => 'integer', 'buffer_minutes' => 'integer', 'price' => 'decimal:2'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'professional_service')->withPivot('workspace_id');
    }

    /** The locations that offer it; none = offered in every location. */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_service')->withPivot('workspace_id');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(BookableResource::class, 'resource_service', 'service_id', 'resource_id')->withPivot('workspace_id');
    }

    public function auditResource(): string
    {
        return 'service';
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
        return ['name' => 'Nombre', 'description' => 'Descripción', 'category' => 'Categoría', 'duration_minutes' => 'Duración (min)', 'buffer_minutes' => 'Tiempo entre citas (min)', 'price' => 'Precio', 'is_active' => 'Activo'];
    }
}
