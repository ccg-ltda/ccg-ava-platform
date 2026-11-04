<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reservation made in ANY system (Booker, Lava Studio, Ava's own agenda), kept for reporting. Written only by App\Services\BookingRecords, keyed by the id the
 * source gave it. See the migration for what it holds and what it never invents.
 */
#[Fillable(['workspace_id', 'source', 'external_id', 'engine', 'location_id', 'location_ref', 'service_name', 'professional_name', 'customer_id', 'appointment_id', 'status', 'origin', 'booked_at', 'starts_at', 'cancelled_at', 'last_reported_at'])]
class BookingRecord extends Model
{
    public const SOURCES = ['booker', 'lava', 'ava'];

    public const STATUSES = ['booked', 'confirmed', 'completed', 'no_show', 'cancelled'];

    protected function casts(): array
    {
        return ['booked_at' => 'datetime', 'starts_at' => 'datetime', 'cancelled_at' => 'datetime', 'last_reported_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
