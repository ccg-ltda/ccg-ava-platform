<?php

namespace App\Booking;

use App\Audit\AuditLogger;
use App\Models\Appointment;
use App\Models\BookingCustomer;
use App\Models\BookingSetting;
use App\Models\Location;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly AvailabilityCalculator $calculator, private readonly AuditLogger $audit, private readonly Locations $locations, private readonly AppointmentNotifier $notifier) {}

    /**
     * `$locationId` is what the caller asked for (null = none named): Locations decides whether the Workspace needs one and refuses an
     * inactive or foreign one. `$channel` is who creates the booking and is fixed by the code that calls (admin page, public page, assistant),
     * never by a request field; the conversation channel it is credited to (WhatsApp, web) comes from the conversation itself.
     *
     * @param  array{name: string, phone: ?string, email: ?string}  $customer
     * @return array{appointment: Appointment, token: string} the token is the customer's link to manage it and is not stored in plain
     */
    public function book(Workspace $workspace, Service $service, ?int $professionalId, CarbonImmutable $startsAt, array $customer, string $channel, ?string $notes = null, ?int $conversationId = null, int|string|null $locationId = null): array
    {
        $this->assertChannel($channel);

        if (! $this->locations->schemaReady()) {
            throw BookingException::state('Las reservas no están disponibles por mantenimiento. Intenta de nuevo más tarde.');
        }

        $this->assertService($workspace, $service);
        $location = $this->locations->resolve($workspace, $locationId);
        $origin = $this->originChannel($workspace, $channel, $conversationId);
        $staff = $channel === 'admin';
        $startsAt = $startsAt->utc();
        $this->assertContact($workspace, $customer, $staff);

        if (! $staff && $this->activeAppointmentsOf($workspace, $customer) >= (int) config('bookings.public.max_active_per_contact')) {
            throw BookingException::outsidePolicy('Ya tienes varias reservas pendientes. Cancela o espera a que pasen antes de pedir otra.');
        }

        foreach ($this->calculator->slotsAt($workspace, $service, $professionalId, $startsAt, null, $staff, $location?->id) as $candidate) {
            $token = Str::random(40);

            try {
                $appointment = $this->locked($workspace, $candidate['professional_id'], $candidate['resource_id'], $location?->id, function () use ($workspace, $service, $candidate, $startsAt, $customer, $channel, $notes, $conversationId, $token, $staff, $location, $origin) {
                    // Another request may have taken it between the search and the lock (or the location may have been switched off).
                    $slot = $this->calculator->slotAt($workspace, $service, $candidate['professional_id'], $startsAt, null, $staff, $location?->id);

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
                        'location_id' => $location?->id,
                        'location_name' => $location?->name,
                        // What the service cost WHEN it was booked: a later change of the service's price never rewrites it. No price, nothing recorded.
                        'price_amount' => $service->price,
                        'price_currency' => $service->price === null ? null : $workspace->settingsOrDefault()->currency,
                        'starts_at' => $slot['starts_at'],
                        'ends_at' => $slot['ends_at'],
                        'blocks_until' => $slot['ends_at']->addMinutes($service->buffer_minutes),
                        'status' => ! $staff && BookingSetting::for($workspace)->requires_confirmation ? Appointment::REQUESTED : Appointment::CONFIRMED,
                        'provider' => 'internal',
                        'sync_status' => 'none',
                        'channel' => $channel,
                        'origin_channel' => $origin,
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
                $this->safely(fn () => $this->notifier->planEvent($appointment->loadMissing('workspace'), 'created'));

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
        $locationId = $this->locations->ofAppointment($appointment);

        foreach ($this->calculator->slotsAt($workspace, $service, $professionalId ?? $appointment->professional_id, $startsAt, $appointment->id, $staff, $locationId) as $candidate) {
            try {
                $done = $this->locked($workspace, $candidate['professional_id'], $candidate['resource_id'], $locationId, function () use ($workspace, $service, $appointment, $candidate, $startsAt, $staff, $locationId) {
                    $slot = $this->calculator->slotAt($workspace, $service, $candidate['professional_id'], $startsAt, $appointment->id, $staff, $locationId);

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
                        // The customer said they would come to ANOTHER time: that answer does not carry over to the new one.
                        'customer_confirmed_at' => null,
                        'customer_confirmation_source' => null,
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
                $this->safely(fn () => $this->notifier->invalidate($appointment, 'rescheduled'));

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
        $this->safely(fn () => $this->notifier->invalidate($appointment, 'appointment_closed'));

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
        $this->safely(fn () => $to === Appointment::CONFIRMED ? $this->notifier->planEvent($appointment->loadMissing('workspace'), 'confirmed') : $this->notifier->invalidate($appointment, 'appointment_closed'));

        return $appointment;
    }

    /**
     * The customer says they will come. It is a different fact from `status` (the BUSINESS accepting the booking) and never changes it. A repeated
     * answer is the same answer; an answer for an appointment that was cancelled, closed or already started is refused and revives nothing; moving
     * the appointment clears it. `$source`: assistant (Ava Chat), link (the customer's own link) or staff (somebody registered it).
     *
     * @return array{appointment: Appointment, replayed: bool}
     */
    public function customerConfirm(Appointment $appointment, string $source): array
    {
        if (! in_array($source, ['assistant', 'link', 'staff'], true)) {
            throw new \InvalidArgumentException("Unknown confirmation source [{$source}].");
        }

        if ($appointment->provider !== 'internal') {
            throw BookingException::state('Esta cita pertenece a una agenda externa: se confirma allí.');
        }

        return DB::transaction(function () use ($appointment, $source) {
            // Re-read under a lock: a cancellation and a confirmation that arrive together cannot both win.
            $current = Appointment::where('workspace_id', $appointment->workspace_id)->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($current);

            if ($current->starts_at <= now()) {
                throw BookingException::state('Esta cita ya comenzó: no se puede confirmar.');
            }

            if ($current->customer_confirmed_at !== null) {
                return ['appointment' => $current, 'replayed' => true];
            }

            $current->forceFill(['customer_confirmed_at' => now(), 'customer_confirmation_source' => $source])->save();
            $this->auditAutomatic($current, 'updated', [AuditLogger::change('Confirmación del cliente', null, 'Confirmó que asistirá')], ['assistant' => 'chat', 'link' => 'web', 'staff' => 'admin'][$source]);

            return ['appointment' => $current, 'replayed' => false];
        });
    }

    /** Planning or retiring a notification must never break the booking operation that triggered it: a failure is logged (class only) and the operation stands. */
    private function safely(Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::error('appointment_notification_plan_failed', ['exception' => $e::class]);
        }
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

    public function assertChannel(string $channel): void
    {
        if (! in_array($channel, Appointment::CHANNELS, true)) {
            throw new \InvalidArgumentException("Unknown booking channel [{$channel}].");
        }
    }

    /**
     * The channel of the conversation the booking is credited to (whatsapp, web), or null. Only an assistant booking is credited, and only
     * to a conversation of THIS Workspace that really exists: Ava never says a booking came from WhatsApp because a caller said so.
     */
    public function originChannel(Workspace $workspace, string $channel, ?int $conversationId): ?string
    {
        if ($channel !== 'chat' || $conversationId === null) {
            return null;
        }

        $conversation = $workspace->conversations()->find($conversationId)?->channel;

        return in_array($conversation, Appointment::ORIGIN_CHANNELS, true) ? $conversation : null;
    }

    /** Runs `$work` in a transaction after locking the professional, the resource and the location it would use, always in the same order. */
    private function locked(Workspace $workspace, int $professionalId, ?int $resourceId, ?int $locationId, Closure $work): mixed
    {
        return DB::transaction(function () use ($workspace, $professionalId, $resourceId, $locationId, $work) {
            Professional::where('workspace_id', $workspace->id)->whereKey($professionalId)->lockForUpdate()->first();

            if ($resourceId) {
                $workspace->bookableResources()->whereKey($resourceId)->lockForUpdate()->first();
            }

            if ($locationId) {
                Location::where('workspace_id', $workspace->id)->whereKey($locationId)->lockForUpdate()->first();
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
