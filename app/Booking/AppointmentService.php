<?php

namespace App\Booking;

use App\Audit\AuditLogger;
use App\Models\Appointment;
use App\Models\BookingCustomer;
use App\Models\BookingSetting;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes to Ava's own agenda. Every change that can take a time slot runs in one transaction that first locks the
 * professional (so two requests for the same professional queue up), asks AvailabilityCalculator again whether the slot is
 * still free, and only then writes. On PostgreSQL an exclusion constraint on the table is the last guard: whatever slips
 * through the checks is refused by the database and reported as an unavailable slot. Always scoped to the Workspace given.
 */
class AppointmentService
{
    /** Whoever the audit trail names when nobody is signed in (the web page, Ava Chat). */
    private const SOURCES = ['web' => 'Reserva en línea (automático)', 'chat' => 'Asistente (automático)', 'admin' => 'Consola de la plataforma'];

    public function __construct(private readonly AvailabilityCalculator $calculator, private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, phone: ?string, email: ?string}  $customer
     * @return array{appointment: Appointment, token: string} the token is the customer's link to manage it and is not stored in plain
     */
    public function book(Workspace $workspace, Service $service, ?int $professionalId, CarbonImmutable $startsAt, array $customer, string $channel, ?string $notes = null, ?int $conversationId = null): array
    {
        $this->assertService($workspace, $service);
        $staff = $channel === 'admin';
        $startsAt = $startsAt->utc();
        $this->assertContact($workspace, $customer, $staff);

        if (! $staff && $this->activeAppointmentsOf($workspace, $customer) >= (int) config('bookings.public.max_active_per_contact')) {
            throw BookingException::outsidePolicy('Ya tienes varias reservas pendientes. Cancela o espera a que pasen antes de pedir otra.');
        }

        foreach ($this->calculator->slotsAt($workspace, $service, $professionalId, $startsAt, null, $staff) as $candidate) {
            $token = Str::random(40);

            try {
                $appointment = $this->locked($workspace, $candidate['professional_id'], $candidate['resource_id'], function () use ($workspace, $service, $candidate, $startsAt, $customer, $channel, $notes, $conversationId, $token, $staff) {
                    // Another request may have taken it between the search and the lock.
                    $slot = $this->calculator->slotAt($workspace, $service, $candidate['professional_id'], $startsAt, null, $staff);

                    if (! $slot) {
                        return null;
                    }

                    return $workspace->appointments()->create([
                        'service_id' => $service->id,
                        'professional_id' => $slot['professional_id'],
                        'resource_id' => $slot['resource_id'],
                        'customer_id' => $this->customer($workspace, $customer)->id,
                        'conversation_id' => $conversationId,
                        'service_name' => $service->name,
                        'professional_name' => $slot['professional_name'],
                        'starts_at' => $slot['starts_at'],
                        'ends_at' => $slot['ends_at'],
                        'blocks_until' => $slot['ends_at']->addMinutes($service->buffer_minutes),
                        'status' => ! $staff && BookingSetting::for($workspace)->requires_confirmation ? Appointment::REQUESTED : Appointment::CONFIRMED,
                        'provider' => 'internal',
                        'sync_status' => 'none',
                        'channel' => $channel,
                        'token_hash' => hash('sha256', $token),
                        'notes' => $notes ? Str::limit(trim($notes), 500, '') : null,
                    ]);
                });
            } catch (QueryException $exception) {
                if ($this->isOverlap($exception)) {
                    continue; // the database refused it: try the next free professional, if any
                }

                throw $exception;
            }

            if ($appointment) {
                $this->auditAutomatic($appointment, 'created', [AuditLogger::change('Estado', null, Appointment::LABELS[$appointment->status])], $channel);

                return ['appointment' => $appointment, 'token' => $token];
            }
        }

        throw BookingException::slotUnavailable();
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, ?int $professionalId, string $channel): Appointment
    {
        $workspace = $appointment->workspace;
        $this->assertOpen($appointment);
        $service = $workspace->services()->find($appointment->service_id) ?? throw BookingException::state('El servicio de esta cita ya no existe.');
        $staff = $channel === 'admin';
        $startsAt = $startsAt->utc();
        $before = $appointment->starts_at->copy();

        foreach ($this->calculator->slotsAt($workspace, $service, $professionalId ?? $appointment->professional_id, $startsAt, $appointment->id, $staff) as $candidate) {
            try {
                $done = $this->locked($workspace, $candidate['professional_id'], $candidate['resource_id'], function () use ($workspace, $service, $appointment, $candidate, $startsAt, $staff) {
                    $slot = $this->calculator->slotAt($workspace, $service, $candidate['professional_id'], $startsAt, $appointment->id, $staff);

                    if (! $slot) {
                        return false;
                    }

                    $appointment->forceFill([
                        'professional_id' => $slot['professional_id'],
                        'professional_name' => $slot['professional_name'],
                        'resource_id' => $slot['resource_id'],
                        'starts_at' => $slot['starts_at'],
                        'ends_at' => $slot['ends_at'],
                        'blocks_until' => $slot['ends_at']->addMinutes($service->buffer_minutes),
                        // A customer who moves a confirmed booking by themselves puts it back to be confirmed when the business asks for that.
                        'status' => ! $staff && BookingSetting::for($workspace)->requires_confirmation ? Appointment::REQUESTED : $appointment->status,
                        'reschedule_count' => $appointment->reschedule_count + 1,
                    ])->save();

                    return true;
                });
            } catch (QueryException $exception) {
                if ($this->isOverlap($exception)) {
                    continue;
                }

                throw $exception;
            }

            if ($done) {
                $this->auditAutomatic($appointment, 'updated', [AuditLogger::change('Inicio', $before->toDateTimeString(), $appointment->starts_at->toDateTimeString())], $channel);

                return $appointment;
            }
        }

        throw BookingException::slotUnavailable();
    }

    public function cancel(Appointment $appointment, ?string $reason, string $channel): Appointment
    {
        $this->assertOpen($appointment);
        $before = Appointment::LABELS[$appointment->status];
        $appointment->forceFill(['status' => Appointment::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason ? Str::limit(trim($reason), 255, '') : null])->save();
        $this->auditAutomatic($appointment, 'updated', [AuditLogger::change('Estado', $before, Appointment::LABELS[Appointment::CANCELLED])], $channel);

        return $appointment;
    }

    /** Staff moves an appointment along: confirm what was requested, or mark it done / missed once it started. */
    public function transition(Appointment $appointment, string $to): Appointment
    {
        $allowed = [
            Appointment::CONFIRMED => [Appointment::REQUESTED],
            Appointment::COMPLETED => [Appointment::CONFIRMED],
            Appointment::NO_SHOW => [Appointment::CONFIRMED],
        ];

        if (! isset($allowed[$to]) || ! in_array($appointment->status, $allowed[$to], true)) {
            throw BookingException::state('Esa cita no puede pasar a ese estado.');
        }

        if ($to !== Appointment::CONFIRMED && $appointment->starts_at->isFuture()) {
            throw BookingException::state('Solo se puede marcar una cita que ya comenzó.');
        }

        $appointment->forceFill(['status' => $to])->save();

        return $appointment;
    }

    /** The customer of a Workspace identified by phone, else by email; created when new, name refreshed otherwise. */
    public function customer(Workspace $workspace, array $data): BookingCustomer
    {
        $phone = BookingCustomer::normalizePhone($data['phone'] ?? null);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $found = $this->findCustomer($workspace, $data);

        if ($found) {
            $found->forceFill(['name' => $data['name'], 'email' => $email ?? $found->email])->save();

            return $found;
        }

        return $workspace->bookingCustomers()->create(['name' => $data['name'], 'phone' => $phone, 'email' => $email]);
    }

    private function findCustomer(Workspace $workspace, array $data): ?BookingCustomer
    {
        $phone = BookingCustomer::normalizePhone($data['phone'] ?? null);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;

        return $workspace->bookingCustomers()
            ->when($phone, fn ($query) => $query->where('phone', $phone), fn ($query) => $query->whereNull('phone')->where('email', $email))
            ->first();
    }

    /** Future active appointments of whoever this contact data identifies (0 for someone new). */
    private function activeAppointmentsOf(Workspace $workspace, array $customer): int
    {
        $found = $this->findCustomer($workspace, $customer);

        return $found ? $workspace->appointments()->where('customer_id', $found->id)->whereIn('status', Appointment::BLOCKING)->where('starts_at', '>', now())->count() : 0;
    }

    public function assertOpen(Appointment $appointment): void
    {
        if (! $appointment->isActive()) {
            throw BookingException::state('Esta cita ya no está activa.');
        }
    }

    /** Rule of the Workspace: the contact data it asks for is there (at least a phone or an email in any case). */
    private function assertContact(Workspace $workspace, array $customer, bool $staff): void
    {
        $settings = BookingSetting::for($workspace);

        if (! filled($customer['phone'] ?? null) && ! filled($customer['email'] ?? null)) {
            throw BookingException::invalid('Indica un teléfono o un correo para poder contactar a quien reserva.');
        }

        if (! $staff && $settings->require_phone && ! filled($customer['phone'] ?? null)) {
            throw BookingException::invalid('Indica un teléfono de contacto.');
        }

        if (! $staff && $settings->require_email && ! filled($customer['email'] ?? null)) {
            throw BookingException::invalid('Indica un correo de contacto.');
        }
    }

    private function assertService(Workspace $workspace, Service $service): void
    {
        if ($service->workspace_id !== $workspace->id || ! $service->is_active) {
            throw BookingException::invalid('Ese servicio no está disponible.');
        }
    }

    /** Runs `$work` in a transaction after locking the professional and the resource it would use, always in the same order. */
    private function locked(Workspace $workspace, int $professionalId, ?int $resourceId, Closure $work): mixed
    {
        return DB::transaction(function () use ($workspace, $professionalId, $resourceId, $work) {
            Professional::where('workspace_id', $workspace->id)->whereKey($professionalId)->lockForUpdate()->first();

            if ($resourceId) {
                $workspace->bookableResources()->whereKey($resourceId)->lockForUpdate()->first();
            }

            return $work();
        });
    }

    /** SQLSTATE 23P01 (exclusion) and 23505 (unique): the database refused an overlap or a duplicate. */
    private function isOverlap(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23P01', '23505'], true);
    }

    /** What nobody signed in did is recorded as the automatic process that did it; a signed-in person's changes are recorded by the model itself. */
    private function auditAutomatic(Appointment $appointment, string $action, array $changes, string $channel): void
    {
        if (Auth::user() && request()->attributes->has('workspace')) {
            return;
        }

        $this->audit->system($action, 'appointment', $appointment->id, $appointment->auditLabel(), $changes, $appointment->workspace, self::SOURCES[$channel] ?? self::SOURCES['web']);
    }
}
