<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One try of sending a reminder: its outcome and a fixed reason code, never provider text. Written only by App\Reminders\ReminderDispatcher. */
#[Fillable(['reminder_id', 'workspace_id', 'attempt', 'outcome', 'reason'])]
class ReminderAttempt extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
