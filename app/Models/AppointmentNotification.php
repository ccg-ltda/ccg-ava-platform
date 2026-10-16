<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message Ava planned to send about an appointment (receipt, confirmation or reminder) and what happened to it. The states say only what is
 * known: `sent` = the channel ACCEPTED it, `delivered`/`read` = the channel reported so later, `uncertain` = it may or may not have left (never
 * resent by itself), `skipped` = Ava decided not to send it and `reason` says why (a fixed code), `cancelled` = the plan became obsolete.
 */
#[Fillable(['workspace_id', 'appointment_id', 'kind', 'offset_minutes', 'version', 'channel', 'mode', 'scheduled_for', 'status', 'attempts', 'reason', 'external_id', 'sent_at', 'delivered_at', 'read_at'])]
class AppointmentNotification extends Model
{
    public const SCHEDULED = 'scheduled';

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    /** States in which the message has not left Ava yet and can still be planned away. */
    public const PENDING = [self::SCHEDULED, self::QUEUED];

    public const LABELS = [
        self::SCHEDULED => 'Programado', self::QUEUED => 'En cola', self::SENDING => 'Enviando', self::SENT => 'Aceptado por WhatsApp', self::DELIVERED => 'Entregado',
        self::READ => 'Leído', self::FAILED => 'Falló', self::UNCERTAIN => 'Sin confirmar', self::SKIPPED => 'No enviado', self::CANCELLED => 'Cancelado',
    ];

    public const KIND_LABELS = ['received' => 'Recepción', 'confirmed' => 'Confirmación', 'reminder' => 'Recordatorio'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
