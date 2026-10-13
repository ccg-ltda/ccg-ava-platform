<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An optional thing a service needs besides a professional (a chair, a room). Never deleted, only deactivated. */
#[Fillable(['name', 'kind', 'is_active'])]
class BookableResource extends Model
{
    use Audited;

    protected $table = 'bookable_resources';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function auditResource(): string
    {
        return 'bookable_resource';
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
        return ['name' => 'Nombre', 'kind' => 'Tipo', 'is_active' => 'Activo'];
    }
}
