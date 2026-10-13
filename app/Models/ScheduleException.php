<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Days (or hours of a day) that cannot be booked: for one professional, or for the whole business when it has none. */
#[Fillable(['professional_id', 'date_from', 'date_to', 'starts_at', 'ends_at', 'reason'])]
class ScheduleException extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function auditResource(): string
    {
        return 'schedule_exception';
    }

    public function auditLabel(): string
    {
        return ($this->reason ?: 'Excepción').' ('.$this->date_from->format('Y-m-d').')';
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['date_from' => 'Desde', 'date_to' => 'Hasta', 'starts_at' => 'Hora inicial', 'ends_at' => 'Hora final', 'reason' => 'Motivo'];
    }
}
