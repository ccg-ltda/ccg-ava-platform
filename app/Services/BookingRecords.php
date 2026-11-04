<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookingRecord;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The ONLY writer of `booking_records`: reservations of any system, for reporting. Idempotent by (Workspace, source, external id): reporting the same reservation again
 * updates it, never duplicates it. A report older than the one already stored (`updated_at` of the source) is ignored, so a late retry can not undo a cancellation.
 * A reservation that Ava itself created in Booker is linked to its appointment, and the consolidated report counts the pair once.
 */
class BookingRecords
{
    public function __construct(private readonly ReportingLinks $links) {}

    /**
     * @param  array<string, mixed>  $data  validated payload (see AgentBookingRecordController)
     * @return array{record: BookingRecord, created: bool, ignored: bool}
     */
    public function report(Workspace $workspace, array $data, ?string $engine): array
    {
        $attributes = [
            'engine' => $engine,
            'location_id' => $this->links->location($workspace, $data['location_id'] ?? null),
            'location_ref' => $data['location_ref'] ?? null,
            'service_name' => $data['service_name'] ?? null,
            'professional_name' => $data['professional_name'] ?? null,
            'customer_id' => $this->links->customer($workspace, $data['customer_id'] ?? null),
            'status' => $data['status'],
            'origin' => $data['origin'] ?? null,
            'booked_at' => $data['booked_at'] ?? null,
            'starts_at' => $data['starts_at'],
            'cancelled_at' => $data['cancelled_at'] ?? ($data['status'] === 'cancelled' ? now() : null),
            'last_reported_at' => $data['updated_at'] ?? now(),
        ];

        $key = ['workspace_id' => $workspace->id, 'source' => $data['source'], 'external_id' => $data['external_id']];

        try {
            return $this->store($workspace, $key, $attributes);
        } catch (UniqueConstraintViolationException) {
            return $this->store($workspace, $key, $attributes); // a parallel retry created it first
        }
    }

    /** @return array{record: BookingRecord, created: bool, ignored: bool} */
    private function store(Workspace $workspace, array $key, array $attributes): array
    {
        $record = BookingRecord::where($key)->first();

        if ($record && $record->last_reported_at && $attributes['last_reported_at'] && now()->parse($attributes['last_reported_at'])->lt($record->last_reported_at)) {
            return ['record' => $record, 'created' => false, 'ignored' => true];
        }

        // Leaving a reservation cancelled only with a newer report that says so: a "booked" report never reopens a cancelled one by accident of ordering.
        $attributes['appointment_id'] = $record?->appointment_id ?? $this->appointmentId($workspace, $key);
        $record ??= new BookingRecord($key);
        $created = ! $record->exists;
        $record->fill($attributes)->save();

        return ['record' => $record, 'created' => $created, 'ignored' => false];
    }

    /** The Ava appointment that IS this external reservation (Ava booked it in Booker), or null. */
    private function appointmentId(Workspace $workspace, array $key): ?int
    {
        if ($key['source'] === 'ava') {
            return null;
        }

        return Appointment::where('workspace_id', $workspace->id)->where('provider', $key['source'])->where('external_id', $key['external_id'])->value('id');
    }
}
