<?php

namespace App\Reminders;

use App\Audit\AuditLogger;
use App\Campaigns\MessageTemplates;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every change a person (or an automation through the agent API) makes to a reminder, validated in the SERVER against the Workspace: the customer, the optional related record
 * and the channel must all belong to / be usable by THIS Workspace, whatever ids the client sends. It is the only writer of the scheduling fields; the lifecycle of sending is
 * ReminderDispatcher's. Every change is audited without the customer's contact data. Creation is idempotent per Workspace: the same key returns the same reminder.
 * The same writer serves the reminders an automatic rule schedules (`source` = `rule`, see ReminderRuleScheduler): one set of checks for every way a reminder is born.
 */
class ReminderService
{
    public function __construct(
        private readonly ReminderChannels $channels,
        private readonly MessageTemplates $templates,
        private readonly ReminderVariables $variables,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by SaveReminderRequest or by the agent request
     * @param  array<string, mixed>  $extra  columns only the code that calls sets (the rule that scheduled it), never a request field
     * @return array{reminder: Reminder, replayed: bool}
     */
    public function create(Workspace $workspace, array $data, ?User $by, string $source = 'user', array $extra = []): array
    {
        $key = (string) ($data['idempotency_key'] ?? Str::uuid());

        $existing = $workspace->reminders()->where('idempotency_key', $key)->first();

        if ($existing) {
            return ['reminder' => $existing, 'replayed' => true];
        }

        if ($workspace->reminders()->where('status', Reminder::SCHEDULED)->count() >= (int) config('reminders.limits.max_scheduled')) {
            throw ValidationException::withMessages(['name' => 'Este Workspace alcanzó el máximo de recordatorios programados. Espera a que se envíen o cancela alguno.']);
        }

        $attributes = $this->attributes($workspace, $data);

        $reminder = DB::transaction(function () use ($workspace, $attributes, $key, $by, $source, $extra) {
            $reminder = $workspace->reminders()->make($attributes + $extra + ['idempotency_key' => $key, 'source' => $source, 'created_by_user_id' => $by?->id]);
            $reminder->forceFill(['status' => Reminder::SCHEDULED, 'next_attempt_at' => $attributes['send_at']])->save();

            return $reminder;
        });

        $this->record('created', $reminder, $workspace, [AuditLogger::change('Envío programado', null, $reminder->send_at->toDateTimeString().' UTC'), AuditLogger::change('Canal', null, $reminder->channel)], $source);

        return ['reminder' => $reminder, 'replayed' => false];
    }

    /**
     * ONE manual send to SEVERAL customers: each gets its OWN reminder (its own message, status, attempts and history), never a shared one, so nobody sees who else received it and
     * a failure of one never touches the rest. They share a `batch_key` that ties them together for the team. A customer who cannot receive it (no consent, no contact data for the
     * channel, deactivated) is NOT scheduled and is reported with the reason; the rest go ahead. Repeating the same request (same `idempotency_key`) returns the same reminders.
     *
     * @param  array<string, mixed>  $data  like `create`, with `customer_ids` instead of `customer_id`
     * @return array{batch: string, created: list<Reminder>, skipped: list<array{id: int, name: string, reason: string}>}
     */
    public function createMany(Workspace $workspace, array $data, ?User $by): array
    {
        $ids = collect((array) ($data['customer_ids'] ?? []))->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty() || $ids->count() > (int) config('reminders.limits.max_recipients')) {
            throw ValidationException::withMessages(['customer_ids' => 'Elige entre 1 y '.config('reminders.limits.max_recipients').' clientes.']);
        }

        $base = (string) ($data['idempotency_key'] ?? Str::uuid());
        $names = $workspace->customers()->whereIn('id', $ids)->get()->keyBy('id');
        $batch = Str::lower(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $base).Str::random(24), 0, 24));
        $created = [];
        $skipped = [];

        foreach ($ids as $id) {
            $name = $names->get($id)?->fullName() ?? 'Cliente '.$id;

            try {
                $created[] = $this->create($workspace, ['customer_id' => $id, 'idempotency_key' => "{$base}-{$id}"] + $data, $by, 'user', Schema::hasColumn('reminders', 'batch_key') ? ['batch_key' => $batch] : [])['reminder'];
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $id, 'name' => $name, 'reason' => (string) collect($e->errors())->flatten()->first()];
            }
        }

        return ['batch' => $batch, 'created' => $created, 'skipped' => $skipped];
    }

    /** Only a reminder that has not started to be sent can change. @param  array<string, mixed>  $data */
    public function update(Reminder $reminder, array $data, string $source = 'user'): Reminder
    {
        $workspace = $reminder->workspace;
        $attributes = $this->attributes($workspace, $data);

        // A query-builder update does not apply the model's casts: the list is encoded here.
        $attributes['template_params'] = $attributes['template_params'] === null ? null : json_encode($attributes['template_params']);

        $updated = Reminder::query()->whereKey($reminder->id)->where('workspace_id', $workspace->id)->where('status', Reminder::SCHEDULED)
            ->update($attributes + ['updated_at' => now(), 'next_attempt_at' => $attributes['send_at']]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['name' => 'Este recordatorio ya no se puede editar: solo los programados que todavía no se envían.']);
        }

        $reminder->refresh();
        $this->record('updated', $reminder, $workspace, [AuditLogger::change('Envío programado', null, $reminder->send_at->toDateTimeString().' UTC')], $source);

        return $reminder;
    }

    /** Cancels a reminder that has not been sent. A conditional UPDATE: a reminder that a worker already claimed can not be cancelled behind its back. */
    public function cancel(Reminder $reminder, string $source = 'user', string $reason = 'cancelled_by_user'): Reminder
    {
        $workspace = $reminder->workspace;

        $cancelled = Reminder::query()->whereKey($reminder->id)->where('workspace_id', $workspace->id)->where('status', Reminder::SCHEDULED)
            ->update(['status' => Reminder::CANCELLED, 'cancelled_at' => now(), 'next_attempt_at' => null, 'reason' => $reason, 'updated_at' => now()]);

        if ($cancelled !== 1) {
            throw ValidationException::withMessages(['reminder' => 'Solo se cancelan los recordatorios programados que todavía no se envían.']);
        }

        $reminder->refresh();
        $this->record('updated', $reminder, $workspace, [AuditLogger::change('Estado', 'Programado', 'Cancelado')], $source);

        return $reminder;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the columns the person controls, normalized and checked
     */
    private function attributes(Workspace $workspace, array $data): array
    {
        $customer = $workspace->customers()->whereNull('anonymized_at')->where('is_active', true)->find($data['customer_id'] ?? 0);

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages(['customer_id' => 'Elige un cliente activo de este Workspace.']);
        }

        // The appointment's data (service, date...) can only be resolved for a reminder tied to an appointment: the ones an automatic rule schedules.
        $message = $this->message($workspace, $data, ($data['related_type'] ?? null) === 'appointment');
        $channel = $message['channel'];

        $this->assertContact($customer, $channel);
        $this->assertRelated($workspace, $data['related_type'] ?? null, $data['related_id'] ?? null);

        $zone = $this->zone($workspace, $data['timezone'] ?? null);

        return [
            'customer_id' => $customer->id,
            'name' => trim((string) $data['name']),
            'purpose' => filled($data['purpose'] ?? null) ? trim((string) $data['purpose']) : null,
            'related_type' => filled($data['related_type'] ?? null) ? $data['related_type'] : null,
            'related_id' => filled($data['related_type'] ?? null) ? (int) $data['related_id'] : null,
            ...$message,
            'send_at' => $this->moment($data['send_at'], $zone),
            'timezone' => $zone,
        ];
    }

    /**
     * The channel and the message of a reminder (or of a rule), checked in the server: the channel must be REALLY available in this Workspace, the variables must exist and
     * WhatsApp needs an approved template. Shared by a single reminder and by an automatic rule, so both obey the same rules.
     *
     * @param  array<string, mixed>  $data
     * @return array{channel: string, subject: ?string, body: string, template_name: ?string, template_language: ?string, template_params: ?array<int, string>}
     */
    public function message(Workspace $workspace, array $data, bool $withAppointment = false): array
    {
        $channel = (string) ($data['channel'] ?? '');
        $availability = $this->channels->check($workspace, $channel);

        if (! $availability['ok']) {
            throw ValidationException::withMessages(['channel' => $availability['reason']]);
        }

        $body = $this->templates->normalize((string) ($data['body'] ?? ''));

        if ($unknown = $this->variables->unknown($body, $withAppointment)) {
            throw ValidationException::withMessages(['body' => 'Variables que no existen: '.implode(', ', $unknown).'.']);
        }

        if ($this->templates->hasMalformedMarkers($body)) {
            throw ValidationException::withMessages(['body' => 'Hay llaves sin cerrar en el mensaje.']);
        }

        $params = array_values((array) ($data['template_params'] ?? []));

        if ($channel === 'whatsapp') {
            if (blank($data['template_name'] ?? null) || blank($data['template_language'] ?? null)) {
                throw ValidationException::withMessages(['template_name' => 'WhatsApp necesita el nombre y el idioma de la plantilla aprobada por Meta.']);
            }

            if ($bad = array_diff($params, array_keys(config('campaigns.variables')))) {
                throw ValidationException::withMessages(['template_params' => 'Variables que no existen: '.implode(', ', $bad).'.']);
            }
        }

        if ($channel === 'email' && blank($data['subject'] ?? null)) {
            throw ValidationException::withMessages(['subject' => 'El correo necesita un asunto.']);
        }

        return [
            'channel' => $channel,
            'subject' => $channel === 'email' ? trim((string) $data['subject']) : null,
            'body' => $body,
            'template_name' => $channel === 'whatsapp' ? trim((string) $data['template_name']) : null,
            'template_language' => $channel === 'whatsapp' ? trim((string) $data['template_language']) : null,
            'template_params' => $channel === 'whatsapp' ? $params : null,
        ];
    }

    /** The recipient must have the data the channel needs; consent is re-checked when sending. */
    private function assertContact(Customer $customer, string $channel): void
    {
        if ($channel === 'email' && blank($customer->email)) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente no tiene correo.']);
        }

        if ($channel === 'whatsapp' && blank($customer->phone_e164) && blank($customer->phone_normalized)) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente no tiene teléfono.']);
        }

        if (config('reminders.require_consent') && $customer->marketing_consent !== Customer::CONSENT_GRANTED) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente no ha dado su consentimiento para recibir mensajes.']);
        }
    }

    /** A related record is a soft reference: it must exist in THIS Workspace (another Workspace's id looks exactly like a missing one). */
    private function assertRelated(Workspace $workspace, mixed $type, mixed $id): void
    {
        if (blank($type)) {
            return;
        }

        $query = match ($type) {
            'appointment' => $workspace->appointments(),
            'order' => $workspace->orders(),
            'service' => $workspace->services(),
            default => null,
        };

        if ($query === null || ! is_numeric($id) || ! $query->whereKey((int) $id)->exists()) {
            throw ValidationException::withMessages(['related_id' => 'El registro relacionado no existe en este Workspace.']);
        }
    }

    private function zone(Workspace $workspace, mixed $requested): string
    {
        $zone = is_string($requested) && in_array($requested, timezone_identifiers_list(), true) ? $requested : null;

        return $zone ?? ($workspace->settingsOrDefault()->timezone ?: 'UTC');
    }

    /** The moment typed in the Workspace's timezone, stored in UTC, inside the allowed window. */
    private function moment(mixed $value, string $zone): Carbon
    {
        try {
            $moment = Carbon::parse((string) $value, $zone)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['send_at' => 'La fecha y hora no son válidas.']);
        }

        if ($moment->lt(now()->addMinutes((int) config('reminders.limits.min_lead_minutes')))) {
            throw ValidationException::withMessages(['send_at' => 'Elige una fecha y hora futuras.']);
        }

        if ($moment->gt(now()->addDays((int) config('reminders.limits.max_days_ahead')))) {
            throw ValidationException::withMessages(['send_at' => 'No se pueden programar recordatorios con más de '.config('reminders.limits.max_days_ahead').' días de anticipación.']);
        }

        return $moment;
    }

    /** @param  list<mixed>  $changes */
    private function record(string $action, Reminder $reminder, Workspace $workspace, array $changes, string $source): void
    {
        match ($source) {
            'api' => $this->audit->system($action, 'reminder', $reminder->id, $reminder->name, $changes, $workspace, 'Asistente (automático)'),
            'rule' => $this->audit->system($action, 'reminder', $reminder->id, $reminder->name, $changes, $workspace, 'Regla de recordatorio (automático)'),
            default => $this->audit->record($action, 'reminder', $reminder->id, $reminder->name, $changes, $workspace),
        };
    }
}
