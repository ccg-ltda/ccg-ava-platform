<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The particular limit of ONE Workspace for one metric; `value` null = unlimited for it. It wins over the plan and affects no other Workspace. */
class WorkspaceLimitOverride extends Model
{
    protected $fillable = ['workspace_id', 'metric', 'value', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
