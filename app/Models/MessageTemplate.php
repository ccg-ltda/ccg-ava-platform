<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reusable message with {{variables}} (see App\Campaigns\MessageTemplates). Created through `$workspace->messageTemplates()`; never deleted. */
#[Fillable(['name', 'category', 'body', 'is_active'])]
class MessageTemplate extends Model
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

    public function auditResource(): string
    {
        return 'message_template';
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
        return ['name' => 'Nombre', 'category' => 'Categoría', 'body' => 'Mensaje', 'is_active' => 'Activa'];
    }
}
