<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded administrative change (see App\Audit\AuditLogger). Rows are append-only: the application never
 * updates or deletes them.
 */
#[Fillable([
    'workspace_id', 'workspace_code', 'workspace_name', 'user_id', 'user_name', 'user_email', 'action',
    'resource_type', 'resource_id', 'resource_label', 'description', 'changes', 'ip_address',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
