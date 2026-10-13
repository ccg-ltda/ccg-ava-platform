<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Booking rules of ONE Workspace. A Workspace without a row uses `config/bookings.php` `defaults` (see `BookingSetting::for`). */
#[Fillable(['provider', 'requires_confirmation', 'min_notice_minutes', 'max_advance_days', 'cancel_notice_hours', 'slot_step_minutes', 'require_phone', 'require_email', 'public_enabled'])]
class BookingSetting extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['requires_confirmation' => 'boolean', 'require_phone' => 'boolean', 'require_email' => 'boolean', 'public_enabled' => 'boolean'];
    }

    public static function for(Workspace $workspace): self
    {
        return $workspace->bookingSetting ?? (new self(config('bookings.defaults')))->forceFill(['workspace_id' => $workspace->id]);
    }

    /** The stable public key of the booking page, created the first time it is needed and never changed. */
    public function ensurePublicKey(): string
    {
        if (! $this->public_key) {
            $this->forceFill(['public_key' => Str::random(32)])->save();
        }

        return $this->public_key;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function auditResource(): string
    {
        return 'booking_settings';
    }

    public function auditLabel(): string
    {
        return $this->workspace->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return [
            'provider' => 'Agenda', 'requires_confirmation' => 'Reservas por confirmar', 'min_notice_minutes' => 'Anticipación mínima (min)',
            'max_advance_days' => 'Anticipación máxima (días)', 'cancel_notice_hours' => 'Cancelación hasta (h antes)', 'slot_step_minutes' => 'Intervalo de horarios (min)',
            'require_phone' => 'Pedir teléfono', 'require_email' => 'Pedir correo', 'public_enabled' => 'Reserva en línea activa',
        ];
    }
}
