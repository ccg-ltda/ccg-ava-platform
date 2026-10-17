<?php

namespace App\Booking;

use App\Integrations\IntegrationRegistry;
use App\Integrations\SendResult;
use App\Integrations\SendsMessages;
use App\Integrations\SendsTemplates;
use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification as Notification;
use App\Models\BookingCustomer;
use App\Models\BookingSetting;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Confirmations and reminders of appointments: ONE capability for every solution that books (a clinic, a salon...). It plans what each Workspace
 * configured in its booking settings, sends through the Workspace's own WhatsApp account and records what really happened.
 *
 * What it guarantees:
 *  - Nothing is sent unless the Workspace turned that kind on, its plan includes Servicios y Reservas and Integraciones, and a WhatsApp account is usable.
 *  - A message is planned once: the database refuses a second row for the same appointment, kind, anticipation and version (`reschedule_count`).
 *  - Every send re-checks the appointment at the moment of sending; a cancelled, moved, closed or already started appointment is never messaged.
 *  - A send that may have left Ava (timeout, server error) is `uncertain` and never repeated; only a failure that PROVABLY sent nothing is retried, a limited number of times.
 *  - `sent` means WhatsApp accepted it; `delivered` and `read` appear only when WhatsApp reports them.
 *  - Free text goes out only inside WhatsApp's window (the contact wrote to the Workspace in the last 24 h); outside it only a template the Workspace declared as approved.
 *    Ava cannot ask Meta whether a template is approved: Meta's refusal is recorded as `failed`, never hidden.
 */
class AppointmentNotifier
{
    private ?bool $ready = null;

    public function __construct(private readonly IntegrationRegistry $types, private readonly ModuleAccess $modules) {}

    /** Whether the database has the structure of notifications (the code can be deployed before its migration: then nothing is planned or sent). */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('appointment_notifications') && Schema::hasColumn('booking_settings', 'reminders_enabled') && Schema::hasColumn('appointments', 'customer_confirmed_at');
    }

    // --- what the Workspace can do right now ----------------------------------------------------------------------------------------

    /**
     * Whether messages can leave this Workspace and, when not, why (a fixed code the screen explains).
     *
     * @return array{ok: bool, code: ?string, integration: ?Integration}
     */
    public function channel(Workspace $workspace, int|string|null|false $chosen = false): array
    {
        if (! $this->modules->allowsAll($workspace, ['bookings', 'integrations'])) {
            return ['ok' => false, 'code' => 'plan', 'integration' => null];
        }

        $accounts = $workspace->integrations()->where('type', 'whatsapp')->where('is_active', true)->orderBy('id')->get();
        // `$chosen`: the account being saved in a form (null = none); false = the one already stored.
        $chosen = $chosen === false ? BookingSetting::for($workspace)->notify_integration_id : ($chosen ? (int) $chosen : null);

        if ($chosen) {
            $account = $accounts->firstWhere('id', $chosen);

            return $account ? ['ok' => true, 'code' => null, 'integration' => $account] : ['ok' => false, 'code' => 'chosen_account_off', 'integration' => null];
        }

        return match ($accounts->count()) {
            0 => ['ok' => false, 'code' => 'no_whatsapp', 'integration' => null],
            1 => ['ok' => true, 'code' => null, 'integration' => $accounts->first()],
            default => ['ok' => false, 'code' => 'several_whatsapp', 'integration' => null],
        };
    }

    // --- planning (called when an appointment changes, and every minute by the scheduler) ---------------------------------------------

    /** An appointment was just created or confirmed: plan the receipt or the confirmation the Workspace asked for. */
    public function planEvent(Appointment $appointment, string $event): void
    {
        if (! $this->ready() || ! $this->planable($appointment)) {
            return;
        }

        $setting = BookingSetting::for($appointment->workspace);
        $kind = match (true) {
            $event === 'created' && $appointment->status === Appointment::REQUESTED && $setting->notify_received => 'received',
            in_array($event, ['created', 'confirmed'], true) && $appointment->status === Appointment::CONFIRMED && $setting->notify_confirmed => 'confirmed',
            default => null,
        };

        if ($kind && $this->plan($appointment, $kind, 0, now()) > 0) {
            $this->dispatchDue();
        }
    }

    /** The appointment was cancelled, closed or moved: whatever was still waiting to leave is retired. Rows already sending finish their own check. */
    public function invalidate(Appointment $appointment, string $reason): void
    {
        if (! $this->ready()) {
            return;
        }

        Notification::where('appointment_id', $appointment->id)->where('workspace_id', $appointment->workspace_id)->whereIn('status', Notification::PENDING)
            ->update(['status' => Notification::CANCELLED, 'reason' => $reason, 'updated_at' => now()]);
    }

    /** Plans one message once (the unique key refuses a repeat). Returns how many rows were created (0 or 1). */
    public function plan(Appointment $appointment, string $kind, int $offset, \DateTimeInterface $at): int
    {
        return DB::table('appointment_notifications')->insertOrIgnore([
            'workspace_id' => $appointment->workspace_id, 'appointment_id' => $appointment->id, 'kind' => $kind, 'offset_minutes' => $offset, 'version' => (int) $appointment->reschedule_count,
            'channel' => 'whatsapp', 'scheduled_for' => $at, 'status' => Notification::SCHEDULED, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Only Ava's own agenda, and only appointments whose phone was validated as international (see config bookings.notifications.phone_policy_since). */
    private function planable(Appointment $appointment): bool
    {
        return $appointment->provider === 'internal'
            && $appointment->created_at >= CarbonImmutable::parse(config('bookings.notifications.phone_policy_since'), 'UTC')
            && $this->modules->allows($appointment->workspace, 'bookings');
    }

    /**
     * Scheduler entry point (every minute): plans the reminders that are due soon, puts right what a crash left half done and hands the due
     * messages to the queue. Safe to run twice at the same time: planning is `insert or ignore` and each hand-over is an atomic claim.
     *
     * @return array{planned: int, queued: int, recovered: int}
     */
    public function run(): array
    {
        if (! $this->ready()) {
            return ['recovered' => 0, 'planned' => 0, 'queued' => 0, 'failed' => 0];
        }

        // Each step runs on its own: a failure in one (or in one Workspace's data, see planReminders) never stops the others, so a bad record of one client
        // cannot keep the due messages of every other client from being queued. What failed is counted and logged with a fixed code; the command then exits
        // with an error so the scheduler's monitor sees it (the rows themselves stay as they were: every step is idempotent and is retried on the next tick).
        $this->stepFailures = 0;
        $result = ['recovered' => 0, 'planned' => 0, 'queued' => 0];

        foreach (['recovered' => 'recoverStuck', 'planned' => 'planReminders', 'queued' => 'dispatchDue'] as $key => $step) {
            try {
                $result[$key] = $this->{$step}();
            } catch (Throwable $e) {
                $this->stepFailures++;
                Log::error('appointments_notify_step_failed', ['step' => $step, 'exception' => $e::class]);
            }
        }

        return $result + ['failed' => $this->stepFailures];
    }

    private int $stepFailures = 0;

    private function planReminders(): int
    {
        $planned = 0;
        $policy = CarbonImmutable::parse(config('bookings.notifications.phone_policy_since'), 'UTC');

        BookingSetting::where('reminders_enabled', true)->with('workspace.organization')->each(function (BookingSetting $setting) use (&$planned, $policy) {
            try {
                $planned += $this->planWorkspace($setting, $policy);
            } catch (Throwable $e) {
                // One Workspace's problem never stops the others.
                $this->stepFailures++;
                Log::error('appointments_notify_workspace_failed', ['workspace' => $setting->workspace_id, 'exception' => $e::class]);
            }
        });

        return $planned;
    }

    /** The reminders of ONE Workspace that must be planned now. */
    private function planWorkspace(BookingSetting $setting, CarbonImmutable $policy): int
    {
        $workspace = $setting->workspace;
        $offsets = $setting->offsets();

        if (! $workspace || $offsets === [] || ! $this->modules->allows($workspace, 'bookings')) {
            return 0;
        }

        $planned = 0;
        $until = now()->addMinutes($offsets[0] + 10);
        $grace = now()->subMinutes(5); // a minute lost by the scheduler is still sent; an offset that passed long ago is not invented

        Appointment::where('workspace_id', $workspace->id)->where('provider', 'internal')->where('status', Appointment::CONFIRMED)
            ->where('created_at', '>=', $policy)->where('starts_at', '>', now())->where('starts_at', '<=', $until)->get()
            ->each(function (Appointment $appointment) use ($offsets, $grace, &$planned) {
                foreach ($offsets as $offset) {
                    $at = $appointment->starts_at->copy()->subMinutes($offset);

                    if ($at >= $grace) {
                        $planned += $this->plan($appointment, 'reminder', $offset, $at);
                    }
                }
            });

        return $planned;
    }

    /** Hands every due message to the queue exactly once. */
    public function dispatchDue(): int
    {
        $queued = 0;

        Notification::where('status', Notification::SCHEDULED)->where('scheduled_for', '<=', now())->orderBy('scheduled_for')->limit(200)->pluck('id')->each(function (int $id) use (&$queued) {
            // Atomic claim: only the process that changes the row from `scheduled` hands it over.
            if (Notification::where('id', $id)->where('status', Notification::SCHEDULED)->update(['status' => Notification::QUEUED, 'updated_at' => now()]) === 1) {
                SendAppointmentNotification::dispatch($id);
                $queued++;
            }
        });

        return $queued;
    }

    /** A lost queue or a killed worker: what was only queued goes back to waiting; what was being sent MAY have left, so it is uncertain (never resent). */
    private function recoverStuck(): int
    {
        $cutoff = now()->subMinutes((int) config('bookings.notifications.stuck_minutes'));
        $queued = Notification::where('status', Notification::QUEUED)->where('updated_at', '<', $cutoff)->update(['status' => Notification::SCHEDULED, 'updated_at' => now()]);
        $sending = Notification::where('status', Notification::SENDING)->where('updated_at', '<', $cutoff)->update(['status' => Notification::UNCERTAIN, 'reason' => 'Se interrumpió mientras se enviaba: pudo haberse enviado. No se reintenta solo; verifica con la persona.', 'updated_at' => now()]);

        return $queued + $sending;
    }

    // --- sending ----------------------------------------------------------------------------------------------------------------------

    /** Sends one planned message (run by the queue). Re-checks everything now; never sends twice. */
    public function send(int $id): void
    {
        // Only one process wins this change; the loser (a repeated job, a second worker) does nothing.
        $claimed = Notification::where('id', $id)->whereIn('status', Notification::PENDING)
            ->update(['status' => Notification::SENDING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $notification = Notification::findOrFail($id);
        $appointment = Appointment::where('workspace_id', $notification->workspace_id)->with(['customer', 'workspace.organization'])->find($notification->appointment_id);

        if ($verdict = $this->obsolete($notification, $appointment)) {
            $this->settle($notification, $verdict[0], $verdict[1]);

            return;
        }

        $workspace = $appointment->workspace;
        $channel = $this->channel($workspace);

        if (! $channel['ok']) {
            $this->settle($notification, Notification::SKIPPED, "channel_{$channel['code']}");

            return;
        }

        $digits = BookingCustomer::normalizePhone($appointment->customer?->phone);

        if ($digits === null || PhoneNumber::e164('+'.$digits) === null) {
            $this->settle($notification, Notification::SKIPPED, 'no_valid_phone');

            return;
        }

        $setting = BookingSetting::for($workspace);
        $configured = (array) ($setting->notify_messages[$notification->kind] ?? []);
        $template = $this->declaredTemplate($configured);
        $composer = new BookingMessageComposer($workspace);

        if ($template === null && ! $this->windowOpen($workspace, $digits)) {
            $this->settle($notification, Notification::SKIPPED, 'needs_template');

            return;
        }

        $integration = $channel['integration'];
        $type = $this->types->get($integration->type);

        try {
            $result = match (true) {
                $template !== null && $type instanceof SendsTemplates => $type->sendTemplate($integration, $digits, $template['name'], $template['language'], $composer->parameters($appointment, $template['params']), "avn:{$notification->id}"),
                $template !== null => SendResult::failed('El canal no puede enviar plantillas.'),
                $type instanceof SendsMessages => $type->send($integration, $digits, $composer->text($appointment, $configured['text'] ?? null, $notification->kind), "avn:{$notification->id}"),
                default => SendResult::failed('El canal no puede enviar mensajes.'),
            };
        } catch (Throwable $e) {
            // Only the class is logged: the text of an exception may carry the message or a credential.
            Log::error('appointment_notification_error', ['exception' => $e::class, 'notification' => $notification->id]);
            $this->settle($notification, Notification::UNCERTAIN, 'Se interrumpió mientras se enviaba: pudo haberse enviado. No se reintenta solo.', ['mode' => $template ? 'template' : 'text']);

            return;
        }

        $this->record($notification, $result, $template ? 'template' : 'text');
    }

    /** The reason this message must not go out any more, or null. @return array{0: string, 1: string}|null [new status, fixed reason] */
    private function obsolete(Notification $notification, ?Appointment $appointment): ?array
    {
        if (! $appointment) {
            return [Notification::CANCELLED, 'appointment_missing'];
        }

        $setting = BookingSetting::for($appointment->workspace);

        return match (true) {
            (int) $appointment->reschedule_count !== (int) $notification->version => [Notification::CANCELLED, 'rescheduled'],
            ! $appointment->isActive() => [Notification::CANCELLED, 'appointment_closed'],
            $appointment->starts_at <= now() => [Notification::CANCELLED, 'already_started'],
            $appointment->provider !== 'internal' => [Notification::SKIPPED, 'external_agenda'],
            ! $this->modules->allows($appointment->workspace, 'bookings') => [Notification::SKIPPED, 'plan'],
            ! $this->enabled($setting, $notification->kind) => [Notification::CANCELLED, 'disabled'],
            $notification->kind === 'received' && $appointment->status !== Appointment::REQUESTED => [Notification::CANCELLED, 'status_changed'],
            $notification->kind === 'confirmed' && $appointment->status !== Appointment::CONFIRMED => [Notification::CANCELLED, 'status_changed'],
            $notification->kind === 'reminder' && $appointment->status !== Appointment::CONFIRMED => [Notification::SKIPPED, 'not_confirmed'],
            $notification->kind === 'reminder' && ! in_array((int) $notification->offset_minutes, $setting->offsets(), true) => [Notification::CANCELLED, 'disabled'],
            default => null,
        };
    }

    private function enabled(BookingSetting $setting, string $kind): bool
    {
        return match ($kind) {
            'received' => $setting->notify_received,
            'confirmed' => $setting->notify_confirmed,
            'reminder' => $setting->reminders_enabled,
            default => false,
        };
    }

    /** @param  array<string, mixed>  $configured @return array{name: string, language: string, params: list<string>}|null */
    private function declaredTemplate(array $configured): ?array
    {
        $template = $configured['template'] ?? null;

        return is_array($template) && filled($template['name'] ?? null) && filled($template['language'] ?? null)
            ? ['name' => (string) $template['name'], 'language' => (string) $template['language'], 'params' => array_values(array_map('strval', (array) ($template['params'] ?? [])))]
            : null;
    }

    /** WhatsApp lets a free text go out only if the contact wrote to this Workspace within the window. */
    private function windowOpen(Workspace $workspace, string $digits): bool
    {
        return Message::where('workspace_id', $workspace->id)->where('direction', 'in')->where('created_at', '>=', now()->subHours((int) config('bookings.notifications.window_hours')))
            ->whereHas('conversation', fn ($query) => $query->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->where('contact_id', $digits))->exists();
    }

    /** Writes what the channel answered. */
    private function record(Notification $notification, SendResult $result, string $mode): void
    {
        $max = (int) config('bookings.notifications.retries.max_attempts');

        match (true) {
            $result->ok => $notification->update(['status' => Notification::SENT, 'mode' => $mode, 'external_id' => $result->externalId, 'sent_at' => now(), 'reason' => null]),
            $result->uncertain => $this->settle($notification, Notification::UNCERTAIN, (string) $result->reason, ['mode' => $mode]),
            $result->retryable && $notification->attempts < $max => $notification->update([
                'status' => Notification::SCHEDULED, 'mode' => $mode, 'reason' => (string) $result->reason,
                'scheduled_for' => now()->addSeconds((int) (config('bookings.notifications.retries.backoff')[$notification->attempts - 1] ?? 300)),
            ]),
            default => $this->settle($notification, Notification::FAILED, (string) $result->reason, ['mode' => $mode]),
        };
    }

    /** @param  array<string, mixed>  $extra */
    private function settle(Notification $notification, string $status, string $reason, array $extra = []): void
    {
        $notification->update(['status' => $status, 'reason' => mb_substr($reason, 0, 160)] + $extra);
    }

    /** The queue gave up on a job that was sending: it may have left. */
    public function interrupted(int $id): void
    {
        Notification::where('id', $id)->where('status', Notification::SENDING)->update(['status' => Notification::UNCERTAIN, 'reason' => 'Se interrumpió mientras se enviaba: pudo haberse enviado. No se reintenta solo.', 'updated_at' => now()]);
    }

    // --- what the channel reports later -----------------------------------------------------------------------------------------------

    /**
     * A delivery state from WhatsApp for a message of THIS Workspace. States only move forward (sent < delivered < read) and `failed` never undoes a delivery.
     * A message whose send ended uncertain is recognized by the tag `avn:{id}` Meta echoes back and takes the id and state Meta reports.
     *
     * @param  array<string, mixed>  $status
     */
    public function applyStatus(int $workspaceId, array $status): bool
    {
        if (! $this->ready()) {
            return false;
        }

        $state = $status['status'] ?? null;
        $id = $status['id'] ?? null;

        if (! in_array($state, ['sent', 'delivered', 'read', 'failed'], true) || ! is_string($id) || $id === '') {
            return false;
        }

        $tag = $status['biz_opaque_callback_data'] ?? null;

        if (is_string($tag) && preg_match('/^avn:(\d{1,18})$/', $tag, $match)) {
            Notification::where('workspace_id', $workspaceId)->whereKey((int) $match[1])->whereNull('external_id')->whereIn('status', [Notification::UNCERTAIN, Notification::SENDING])
                ->update(['external_id' => $id, 'status' => $state === 'failed' ? Notification::FAILED : Notification::SENT, 'reason' => $state === 'failed' ? 'Meta informó que no pudo entregarlo.' : null, 'sent_at' => now()]);
        }

        $notification = Notification::where('workspace_id', $workspaceId)->where('external_id', $id)->first();

        if (! $notification) {
            return false;
        }

        $query = Notification::whereKey($notification->id);
        $now = now();

        match ($state) {
            'sent' => null,
            'delivered' => $query->whereIn('status', [Notification::SENT, Notification::UNCERTAIN, Notification::FAILED])->update(['status' => Notification::DELIVERED, 'delivered_at' => $now, 'reason' => null]),
            'read' => $query->whereIn('status', [Notification::SENT, Notification::DELIVERED, Notification::UNCERTAIN, Notification::FAILED])->update(['status' => Notification::READ, 'read_at' => $now, 'delivered_at' => DB::raw('coalesce(delivered_at, \''.$now->toDateTimeString().'\')'), 'reason' => null]),
            'failed' => $query->whereIn('status', [Notification::SENT, Notification::UNCERTAIN])->update(['status' => Notification::FAILED, 'reason' => 'Meta informó que no pudo entregarlo.']),
        };

        return true;
    }

    // --- what the Agenda and the assistant see ----------------------------------------------------------------------------------------

    /** The messages of the CURRENT version of an appointment, as the screens and the assistant need them (no text, no phone). @return list<array<string, mixed>> */
    public function summary(Appointment $appointment, bool $staff): array
    {
        return $appointment->notifications->where('version', $appointment->reschedule_count)->sortBy('scheduled_for')->map(fn (Notification $n) => [
            'kind' => $n->kind, 'offset' => $n->offset_minutes, 'status' => $n->status, 'statusLabel' => Notification::LABELS[$n->status] ?? $n->status,
            'scheduledFor' => $n->scheduled_for->utc()->toIso8601ZuluString(),
        ] + ($staff ? ['reason' => $n->reason, 'mode' => $n->mode] : []))->values()->all();
    }
}
