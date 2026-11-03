<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a reminder rule decided for ONE appointment: scheduled, skipped or cancelled, with a fixed reason. One row per rule and appointment (the guard against duplicates and the history). */
#[Fillable(['reminder_rule_id', 'workspace_id', 'appointment_id', 'customer_id', 'reminder_id', 'status', 'reason', 'evaluated_at'])]
class ReminderRuleEvent extends Model
{
    public const SCHEDULED = 'scheduled';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['evaluated_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReminderRule::class, 'reminder_rule_id');
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
