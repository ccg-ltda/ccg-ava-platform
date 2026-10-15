<?php

namespace App\Booking;

use App\Audit\AuditLogger;
use App\Models\Appointment;
use App\Models\BookingSetting;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * The one entry point of the booking features (admin pages, public booking page, Ava Chat): it asks the agenda that is the
 * source of truth of the Workspace (CalendarProviders) and never anything else. Ava's own agenda is written by AppointmentService;
 * an external one is written through its provider, and the appointment Ava keeps for it records how far that provider has
 * accepted it (`sync_status`): it is `confirmed` only when the provider said so, `failed` with a fixed code when it refused or
 * could not be reached, and never silently turned into an appointment of Ava's own agenda.
 */
class BookingManager
{
    public function __construct(
        private readonly CalendarProviders $providers,
        private readonly AppointmentService $appointments,
        private readonly AuditLogger $audit,
    ) {}

    public function provider(Workspace $workspace): CalendarProvider
    {
        return $this->providers->for($workspace);
    }

    /** What the Workspace's agenda can do right now, for the pages to explain what is missing instead of failing. @return array<string, mixed> */
    public function capabilities(Workspace $workspace): array
    {
        $provider = $this->provider($workspace);
        $key = BookingSetting::for($workspace)->provider;

        return $provider->capabilities() + ['provider' => $key, 'label' => config("bookings.providers.{$key}.label", $key), 'external' => $key !== 'internal', 'connected' => ! $provider instanceof UnavailableCalendar];
    }

    /** @return list<array<string, mixed>> */
    public function services(Workspace $workspace): array
    {
        return $this->guard(fn () => $this->provider($workspace)->services());
    }

    /**
     * The services a customer can really book: with Ava's own agenda, only those that have an active professional who works some day
     * (a service nobody can perform has no times to offer). An external agenda decides for itself what is bookable.
     *
     * @return list<array<string, mixed>>
     */
    public function bookableServices(Workspace $workspace): array
    {
        $services = $this->services($workspace);

        if (! $this->providers->isInternal($this->provider($workspace))) {
            return $services;
        }

        $bookable = $workspace->services()->where('is_active', true)
            ->whereHas('professionals', fn ($query) => $query->where('professionals.is_active', true)->whereHas('scheduleRules'))->pluck('id')->all();

        return array_values(array_filter($services, fn (array $service) => in_array($service['id'], $bookable, true)));
    }

    /** @return list<array<string, mixed>> */
    public function professionals(Workspace $workspace, int|string|null $serviceId = null): array
    {
        return $this->guard(fn () => $this->provider($workspace)->professionals($serviceId));
    }

    /**
     * Free starts of a service over `$days` local days from `$from` (UTC), at most the public window.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, professional_id: int|string|null, professional_name: ?string}>
     */
    public function availability(Workspace $workspace, int|string $serviceId, int|string|null $professionalId, CarbonImmutable $from, int $days, ?Appointment $ignore = null): array
    {
        $days = max(1, min($days, (int) config('bookings.public.max_days')));
        $provider = $this->provider($workspace);

        // Ava's own agenda only knows numeric ids: an id of another kind is simply not one of its services or professionals.
        if ($this->providers->isInternal($provider) && (! $this->internalId($serviceId) || ($professionalId !== null && ! $this->internalId($professionalId)))) {
            return [];
        }

        if (! $provider->capabilities()['availability']) {
            throw BookingException::unsupported($this->unsupported($workspace, 'consultar horarios'));
        }

        return $this->guard(fn () => $provider->availability($serviceId, $professionalId, $from->utc(), $from->utc()->addDays($days), $ignore?->id));
    }

    /**
     * Books on the Workspace's agenda. `$data`: service_id, professional_id?, starts_at (CarbonImmutable), customer{name,phone,email}, notes?.
     *
     * @return array{appointment: Appointment, token: ?string} the token (the customer's link) exists only for appointments of Ava's own agenda
     */
    public function request(Workspace $workspace, array $data, string $channel, ?int $conversationId = null): array
    {
        $provider = $this->provider($workspace);

        // The same request again (a retried workflow, a message delivered twice): one conversation asking for the same service at the same
        // moment is ONE booking. Ava returns the one it already has instead of writing again; if its outcome in the external agenda is
        // still unknown it is refused until somebody verifies it (never a second write on a guess).
        if ($conversationId !== null && ($existing = $this->existing($workspace, $provider, $data, $conversationId))) {
            if ($existing->sync_status === 'uncertain') {
                throw BookingException::uncertain($existing->id);
            }

            return ['appointment' => $existing, 'token' => null, 'replayed' => true];
        }

        // Without a conversation (the public page) a repeated request is not merged (the visitor needs the link of a NEW booking), but one
        // whose outcome in the external agenda is unknown is still not written again: same customer, same moment, same agenda.
        if ($conversationId === null && ! $this->providers->isInternal($provider)
            && ($pending = $this->uncertainFor($workspace, $provider, $data))) {
            throw BookingException::uncertain($pending->id);
        }

        if ($this->providers->isInternal($provider)) {
            $service = $this->internalId($data['service_id']) ? $workspace->services()->find($data['service_id']) : null;
            $service ?? throw BookingException::invalid('Ese servicio no está disponible.');
            $professional = $data['professional_id'] ?? null;

            if ($professional !== null && ! $this->internalId($professional)) {
                throw BookingException::invalid('Ese profesional no está disponible.');
            }

            return $this->appointments->book($workspace, $service, $professional === null ? null : (int) $professional, $data['starts_at'], $data['customer'], $channel, $data['notes'] ?? null, $conversationId);
        }

        if (! $provider->capabilities()['book']) {
            throw BookingException::unsupported($this->unsupported($workspace, 'reservar'));
        }

        return ['appointment' => $this->bookExternally($workspace, $provider, $data, $channel, $conversationId), 'token' => null];
    }

    private function uncertainFor(Workspace $workspace, CalendarProvider $provider, array $data): ?Appointment
    {
        return $workspace->appointments()->where('customer_id', $this->appointments->customer($workspace, $data['customer'])->id)->where('starts_at', $data['starts_at']->utc())
            ->where('provider', $provider->key())->where('sync_status', 'uncertain')->latest('id')->first();
    }

    private function existing(Workspace $workspace, CalendarProvider $provider, array $data, int $conversationId): ?Appointment
    {
        $query = $workspace->appointments()->where('conversation_id', $conversationId)->where('starts_at', $data['starts_at']->utc())
            ->where('provider', $provider->key())->whereIn('status', Appointment::BLOCKING);

        return ($this->providers->isInternal($provider) ? $query->where('service_id', $data['service_id']) : $query)->latest('id')->first();
    }

    public function reschedule(Appointment $appointment, CarbonImmutable $startsAt, int|string|null $professionalId, string $channel): Appointment
    {
        $this->assertSelfService($appointment, $channel);

        if ($appointment->provider === 'internal') {
            if ($professionalId !== null && ! $this->internalId($professionalId)) {
                throw BookingException::invalid('Ese profesional no está disponible.');
            }

            return $this->appointments->reschedule($appointment, $startsAt, $professionalId === null ? null : (int) $professionalId, $channel);
        }

        $this->appointments->assertOpen($appointment);
        $provider = $this->providers->for($appointment->workspace, $appointment->provider);

        if (! $provider->capabilities()['reschedule']) {
            throw BookingException::unsupported($this->unsupported($appointment->workspace, 'reprogramar'));
        }

        try {
            $result = $provider->reschedule($appointment, $startsAt->utc(), $professionalId);
        } catch (ProviderException $exception) {
            $this->markSyncFailure($appointment, $exception);

            throw $this->translate($exception);
        }

        $appointment->forceFill(['starts_at' => $result->startsAt ?? $startsAt->utc(), 'ends_at' => $result->endsAt ?? $appointment->ends_at, 'blocks_until' => $result->endsAt ?? $appointment->blocks_until, 'status' => $result->status, 'sync_status' => 'synced', 'sync_error' => null, 'reschedule_count' => $appointment->reschedule_count + 1])->save();

        return $appointment;
    }

    public function cancel(Appointment $appointment, ?string $reason, string $channel): Appointment
    {
        $this->assertSelfService($appointment, $channel);

        if ($appointment->provider === 'internal') {
            return $this->appointments->cancel($appointment, $reason, $channel);
        }

        $this->appointments->assertOpen($appointment);

        // A request whose outcome was never known has no id in the other agenda: staff, after checking there, may close it here.
        if ($channel === 'admin' && $appointment->sync_status === 'uncertain' && $appointment->external_id === null) {
            $appointment->forceFill(['status' => Appointment::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason ? mb_substr(trim($reason), 0, 255) : 'Verificada sin registro en la agenda externa.', 'sync_status' => 'failed', 'sync_error' => 'not_found'])->save();

            return $appointment;
        }

        try {
            $this->providers->for($appointment->workspace, $appointment->provider)->cancel($appointment);
        } catch (ProviderException $exception) {
            // Still active in the other agenda: it is not shown as cancelled here either.
            $this->markSyncFailure($appointment, $exception);

            throw $this->translate($exception);
        }

        $appointment->forceFill(['status' => Appointment::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason ? mb_substr(trim($reason), 0, 255) : null, 'sync_status' => 'synced', 'sync_error' => null])->save();

        return $appointment;
    }

    /** Confirm / complete / no-show, only for appointments of Ava's own agenda: an external agenda confirms and closes its own. */
    public function transition(Appointment $appointment, string $to): Appointment
    {
        if ($appointment->provider !== 'internal') {
            throw BookingException::state('Esta cita pertenece a una agenda externa: se confirma y se cierra allí.');
        }

        return $this->appointments->transition($appointment, $to);
    }

    /** Asks the owning external agenda what it knows about the appointment and aligns the cancelled ones. */
    public function refresh(Appointment $appointment): Appointment
    {
        if ($appointment->provider === 'internal') {
            return $appointment;
        }

        try {
            $current = $this->providers->for($appointment->workspace, $appointment->provider)->find($appointment);
        } catch (ProviderException $exception) {
            $this->markSyncFailure($appointment, $exception);

            throw $this->translate($exception);
        }

        $appointment->forceFill($current === null
            ? ['sync_status' => 'failed', 'sync_error' => 'not_found']
            : ['sync_status' => 'synced', 'sync_error' => null] + ($current->status === Appointment::CANCELLED ? ['status' => Appointment::CANCELLED, 'cancelled_at' => $appointment->cancelled_at ?? now()] : []))->save();

        return $appointment;
    }

    /** The appointment of the Workspace named by the customer's token, or a 404-like error. */
    public function findByToken(Workspace $workspace, string $token): Appointment
    {
        return $workspace->appointments()->where('token_hash', hash('sha256', $token))->first() ?? throw new BookingException(BookingException::NOT_FOUND, 'No encontramos esa reserva.');
    }

    private function bookExternally(Workspace $workspace, CalendarProvider $provider, array $data, string $channel, ?int $conversationId): Appointment
    {
        $service = collect($this->services($workspace))->first(fn (array $item) => (string) $item['id'] === (string) $data['service_id']) ?? throw BookingException::invalid('Ese servicio no está disponible.');
        $professional = ($data['professional_id'] ?? null) !== null
            ? collect($this->professionals($workspace, $data['service_id']))->first(fn (array $item) => (string) $item['id'] === (string) $data['professional_id'])
            : null;
        $startsAt = $data['starts_at']->utc();
        $duration = (int) ($service['duration_minutes'] ?? 0) ?: 30;

        // Ava keeps its own record of the request from the first moment: what the provider does with it is written on it, never assumed.
        $appointment = $workspace->appointments()->create([
            'customer_id' => $this->appointments->customer($workspace, $data['customer'])->id,
            'conversation_id' => $conversationId,
            'service_name' => $service['name'],
            'professional_name' => $professional['name'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($duration),
            'blocks_until' => $startsAt->addMinutes($duration),
            'status' => Appointment::REQUESTED,
            'provider' => $provider->key(),
            'sync_status' => 'pending',
            'channel' => $channel,
            'notes' => isset($data['notes']) ? mb_substr(trim($data['notes']), 0, 500) : null,
        ]);

        try {
            $result = $provider->book(new BookingRequest($data['service_id'], $data['professional_id'] ?? null, $startsAt, $data['customer'], $data['notes'] ?? null, $channel, $conversationId));
        } catch (ProviderException $exception) {
            if ($exception->uncertain()) {
                // It may exist in the other agenda: kept as a request whose outcome is unknown (never `failed`, never repeated by itself).
                $appointment->forceFill(['status' => Appointment::REQUESTED, 'sync_status' => 'uncertain', 'sync_error' => $exception->codeName])->save();
                $this->auditSync($appointment, $exception->codeName, $channel, 'Sin confirmar');

                throw BookingException::uncertain($appointment->id);
            }

            $appointment->forceFill(['status' => Appointment::FAILED, 'sync_status' => 'failed', 'sync_error' => $exception->codeName])->save();
            $this->auditSync($appointment, $exception->codeName, $channel);

            throw $this->translate($exception);
        }

        $appointment->forceFill(['external_id' => $result->externalId, 'status' => $result->status, 'sync_status' => 'synced', 'sync_error' => null])->save();

        return $appointment;
    }

    private function internalId(mixed $id): bool
    {
        return is_int($id) || (is_string($id) && ctype_digit($id));
    }

    private function assertSelfService(Appointment $appointment, string $channel): void
    {
        if ($channel === 'admin') {
            return;
        }

        $hours = BookingSetting::for($appointment->workspace)->cancel_notice_hours;

        if ($appointment->starts_at->lt(now()->addHours($hours))) {
            throw BookingException::outsidePolicy("Ya no es posible cambiar esta reserva por aquí: se admite hasta {$hours} h antes. Comunícate con el negocio.");
        }
    }

    private function markSyncFailure(Appointment $appointment, ProviderException $exception): void
    {
        // A lost answer to a change (cancel, reschedule) leaves it unknown: `refresh` asks the agenda; a certain refusal is a failure.
        $appointment->forceFill(['sync_status' => $exception->uncertain() ? 'uncertain' : 'failed', 'sync_error' => $exception->codeName])->save();
    }

    private function auditSync(Appointment $appointment, string $code, string $channel, string $outcome = 'Falló'): void
    {
        $this->audit->recordForActor('updated', 'appointment', $appointment->id, $appointment->auditLabel(), [AuditLogger::change('Sincronización', null, "{$outcome} ({$code})")], $appointment->workspace, $channel === 'chat' ? 'Asistente (automático)' : 'Reserva en línea (automático)', AuditLogger::FAILED);
    }

    private function translate(ProviderException $exception): BookingException
    {
        return match ($exception->codeName) {
            ProviderException::CONFLICT => BookingException::slotUnavailable(),
            ProviderException::UNSUPPORTED => BookingException::unsupported($exception->getMessage()),
            default => new BookingException(BookingException::PROVIDER, $exception->getMessage()),
        };
    }

    /** @template T @param  callable(): T  $call @return T */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (ProviderException $exception) {
            throw $this->translate($exception);
        }
    }

    private function unsupported(Workspace $workspace, string $what): string
    {
        $label = config('bookings.providers.'.BookingSetting::for($workspace)->provider.'.label', 'La agenda externa');

        return "La agenda conectada ({$label}) todavía no permite {$what} desde Ava: esa operación aún no está verificada con ese proveedor.";
    }
}
