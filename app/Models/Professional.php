<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone who performs services. Created and read only through `$workspace->professionals()`; never deleted, only deactivated. */
#[Fillable(['name', 'specialty', 'phone', 'is_active'])]
class Professional extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'professional_service')->withPivot('workspace_id');
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
        return 'professional';
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
        return ['name' => 'Nombre', 'specialty' => 'Especialidad', 'phone' => 'Teléfono', 'is_active' => 'Activo'];
    }
}
