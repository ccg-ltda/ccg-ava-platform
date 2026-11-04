<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One try of sending a reminder: its outcome, a fixed reason code and (for a failure) a short detail: the error class and its text with addresses masked. Written only by App\Reminders\ReminderDispatcher. */
#[Fillable(['reminder_id', 'workspace_id', 'attempt', 'outcome', 'reason', 'detail'])]
class ReminderAttempt extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
