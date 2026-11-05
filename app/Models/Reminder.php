<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scheduled commercial message to one customer (module Recordatorios, see App\Reminders and RECOMENDACIONES.md). Written only by App\Reminders\ReminderService
 * (the person's changes) and App\Reminders\ReminderDispatcher (the lifecycle of sending). The workspace is never taken from a request.
 */
#[Fillable(['customer_id', 'name', 'purpose', 'related_type', 'related_id', 'channel', 'subject', 'body', 'template_name', 'template_language', 'template_params', 'send_at', 'timezone', 'idempotency_key', 'source', 'created_by_user_id', 'reminder_rule_id', 'batch_key'])]
class Reminder extends Model
{
    public const SCHEDULED = 'scheduled';

    /** Handed to the queue by the scheduler and waiting for a worker: nothing has been sent yet. */
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const SKIPPED = 'skipped';

    public const UNCERTAIN = 'uncertain';

    protected function casts(): array
    {
        return ['template_params' => 'array', 'send_at' => 'datetime', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->status === self::SCHEDULED || $this->status === self::QUEUED;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReminderRule::class, 'reminder_rule_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(ReminderAttempt::class);
    }
}
