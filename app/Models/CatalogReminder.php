<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A reminder written by a member of a Workspace and emailed, at the scheduled time, to the members it names (`recipients`, validated against the Workspace's own
 * eligible members; a reminder with none goes to its creator, as every one did before recipients existed). Never to an address a client sends. Created and read only
 * through `$workspace->catalogReminders()`; never deleted: cancelling keeps it with its history.
 *
 * States: scheduled (waiting; also what a retryable failure goes back to) -> queued (handed to the queue) -> sending (a worker owns it) -> sent | failed (the
 * attempts ran out or the failure is permanent) | uncertain (a worker died mid-send: it MAY have gone out, so it is never resent by itself) | cancelled | skipped
 * (it could not be sent as written, e.g. the creator is no longer a member or it was due too long ago).
 */
#[Fillable(['subject', 'body', 'scheduled_at'])]
class CatalogReminder extends Model
{
    use Audited;

    public const SCHEDULED = 'scheduled';

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const CANCELLED = 'cancelled';

    public const SKIPPED = 'skipped';

    public const LABELS = [
        self::SCHEDULED => 'Programado', self::QUEUED => 'En cola', self::SENDING => 'Enviando', self::SENT => 'Enviado', self::FAILED => 'Falló',
        self::UNCERTAIN => 'Incierto', self::CANCELLED => 'Cancelado', self::SKIPPED => 'No enviado',
    ];

    /** Statuses in which it may still be edited or cancelled by hand (nothing has gone out yet and no worker owns it). */
    public const OPEN = [self::SCHEDULED, self::FAILED, self::UNCERTAIN];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The members it goes to; the pivot records what was delivered to each (`sent_at`, `sent_to`) and why someone was not notified (`skip_reason`). */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'catalog_reminder_recipients')->withPivot(['workspace_id', 'sent_at', 'sent_to', 'skip_reason'])->withTimestamps();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function auditResource(): string
    {
        return 'catalog_reminder';
    }

    public function auditLabel(): string
    {
        return $this->subject;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['subject' => 'Asunto', 'scheduled_at' => 'Fecha de envío', 'status' => 'Estado'];
    }
}
