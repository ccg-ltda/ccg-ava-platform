<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One answer of a customer to the buttons of a WhatsApp reminder. See the migration for the guarantees. */
#[Fillable(['workspace_id', 'appointment_id', 'notification_id', 'action', 'result', 'channel', 'external_id', 'responded_at'])]
class AppointmentReply extends Model
{
    public const ACTIONS = ['confirm', 'reschedule', 'cancel'];

    public const APPLIED = 'applied';

    public const ALREADY_DONE = 'already_done';

    public const CLOSED = 'closed';

    public const EXPIRED = 'expired';

    public const NOT_AUTHORIZED = 'not_authorized';

    public const ACTION_LABELS = ['confirm' => 'Confirmó', 'reschedule' => 'Pidió reprogramar', 'cancel' => 'Canceló'];

    public const RESULT_LABELS = [self::APPLIED => 'Aplicada', self::ALREADY_DONE => 'Ya estaba aplicada', self::CLOSED => 'La cita ya no estaba activa', self::EXPIRED => 'Botón vencido', self::NOT_AUTHORIZED => 'Remitente no autorizado'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
