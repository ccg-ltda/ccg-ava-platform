<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where the business attends (sede). Created and read only through `$workspace->locations()`; never deleted, only deactivated,
 * so the appointments that point to it keep their history.
 */
#[Fillable(['name', 'address', 'city', 'phone', 'notes', 'is_active', 'offers_all_services'])]
class Location extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'offers_all_services' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Services offered here. A service with no location at all is offered everywhere (see Service::locations). */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'location_service')->withPivot('workspace_id');
    }

    /** Products offered here. A product with no location at all is offered everywhere (see Product::locations). */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'location_product')->withPivot('workspace_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function scheduleRules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class);
    }

    public function auditResource(): string
    {
        return 'location';
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
        return ['name' => 'Nombre', 'address' => 'Dirección', 'city' => 'Ciudad', 'phone' => 'Teléfono', 'notes' => 'Indicaciones', 'is_active' => 'Activa'];
    }
}
