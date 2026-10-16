<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Booking rules of ONE Workspace. A Workspace without a row uses `config/bookings.php` `defaults` (see `BookingSetting::for`). */
#[Fillable(['provider', 'requires_confirmation', 'min_notice_minutes', 'max_advance_days', 'cancel_notice_hours', 'slot_step_minutes', 'require_phone', 'require_email', 'public_enabled', 'notify_received', 'notify_confirmed', 'reminders_enabled', 'reminder_offsets', 'notify_integration_id', 'notify_messages'])]
class BookingSetting extends Model
{
    use Audited;

    protected function casts(): array
    {
        return [
            'requires_confirmation' => 'boolean', 'require_phone' => 'boolean', 'require_email' => 'boolean', 'public_enabled' => 'boolean',
            'notify_received' => 'boolean', 'notify_confirmed' => 'boolean', 'reminders_enabled' => 'boolean', 'reminder_offsets' => 'array', 'notify_messages' => 'array',
        ];
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

    /** Whether this Workspace asked Ava to send anything at all about its appointments. */
    public function sendsNotifications(): bool
    {
        return $this->notify_received || $this->notify_confirmed || $this->reminders_enabled;
    }

    /** @return list<int> minutes before the start of each configured reminder, longest first */
    public function offsets(): array
    {
        $offsets = array_values(array_unique(array_map('intval', (array) $this->reminder_offsets)));
        rsort($offsets);

        return $offsets;
    }

    /** Reads a JSON column from either a cast value or the raw stored string (the audit sees both). @return array<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_string($value) ? (array) json_decode($value, true) : (array) $value;
    }

    public function auditSnapshot(\Closure $get): array
    {
        $values = $this->auditBaseSnapshot($get);
        $values['Recordatorios (min antes)'] = implode(', ', self::listOf($get('reminder_offsets'))) ?: null;
        $values['Plantillas de Meta'] = collect(self::listOf($get('notify_messages')))->map(fn ($message, $kind) => isset($message['template']['name']) ? "{$kind}: {$message['template']['name']}" : null)->filter()->implode(', ') ?: null;

        return $values;
    }

    public function auditFields(): array
    {
        return [
            'provider' => 'Agenda', 'requires_confirmation' => 'Reservas por confirmar', 'min_notice_minutes' => 'Anticipación mínima (min)',
            'max_advance_days' => 'Anticipación máxima (días)', 'cancel_notice_hours' => 'Cancelación hasta (h antes)', 'slot_step_minutes' => 'Intervalo de horarios (min)',
            'require_phone' => 'Pedir teléfono', 'require_email' => 'Pedir correo', 'public_enabled' => 'Reserva en línea activa',
            'notify_received' => 'Avisar que se recibió la reserva', 'notify_confirmed' => 'Avisar que se confirmó la cita', 'reminders_enabled' => 'Recordatorios activos', 'notify_integration_id' => 'Cuenta de WhatsApp para avisos',
        ];
    }
}
