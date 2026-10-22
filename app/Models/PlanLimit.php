<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The standard limit a plan sells for one metric (see App\Services\WorkspaceLimits). No row = the plan sets no limit. */
class PlanLimit extends Model
{
    protected $fillable = ['plan_id', 'metric', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
