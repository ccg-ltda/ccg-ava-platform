<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking. `status` says what it is for the business; `provider` says which agenda owns it (`internal` = Ava, `booker` = the
 * external agenda, which stays the source of truth) and `sync_status` how far that agenda has accepted it. It is NEVER shown
 * as confirmed unless the agenda that owns it confirmed it.
 */
#[Fillable(['service_id', 'professional_id', 'resource_id', 'customer_id', 'conversation_id', 'service_name', 'professional_name', 'starts_at', 'ends_at', 'blocks_until', 'status', 'provider', 'external_id', 'sync_status', 'sync_error', 'channel', 'token_hash', 'notes', 'cancelled_at', 'cancel_reason', 'reschedule_count'])]
#[Hidden(['token_hash'])]
class Appointment extends Model
{
    use Audited;

    public const REQUESTED = 'requested';

    public const CONFIRMED = 'confirmed';

    public const COMPLETED = 'completed';

    public const NO_SHOW = 'no_show';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const STATUSES = [self::REQUESTED, self::CONFIRMED, self::COMPLETED, self::NO_SHOW, self::CANCELLED, self::FAILED];

    /** The statuses that occupy the calendar. */
    public const BLOCKING = [self::REQUESTED, self::CONFIRMED];

    public const LABELS = [
        self::REQUESTED => 'Por confirmar', self::CONFIRMED => 'Confirmada', self::COMPLETED => 'Realizada',
        self::NO_SHOW => 'No asistió', self::CANCELLED => 'Cancelada', self::FAILED => 'Rechazada por la agenda',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'blocks_until' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BookingCustomer::class, 'customer_id');
    }

    /** Appointments that still occupy the calendar from now on. */
    public function scopeUpcoming($query)
    {
        return $query->whereIn('status', self::BLOCKING)->where('starts_at', '>', now());
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::BLOCKING, true);
    }

    public function auditResource(): string
    {
        return 'appointment';
    }

    /** No customer name: the label identifies the appointment without personal data. */
    public function auditLabel(): string
    {
        return "Cita #{$this->id} · {$this->service_name}";
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['status' => 'Estado', 'service_name' => 'Servicio', 'professional_name' => 'Profesional', 'starts_at' => 'Inicio', 'sync_status' => 'Sincronización', 'cancel_reason' => 'Motivo de cancelación'];
    }
}
