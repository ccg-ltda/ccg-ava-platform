<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One weekly working interval of a professional (ISO weekday, local time). Audited as part of the professional's schedule. */
#[Fillable(['workspace_id', 'professional_id', 'weekday', 'starts_at', 'ends_at'])]
class ScheduleRule extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
