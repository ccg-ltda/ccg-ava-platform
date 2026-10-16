<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One weekly working interval of a professional (ISO weekday, local time). Audited as part of the professional's schedule. */
#[Fillable(['workspace_id', 'professional_id', 'location_id', 'weekday', 'starts_at', 'ends_at'])]
class ScheduleRule extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    /** Where the interval applies; NULL = every location (all the schedules that existed before locations). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
