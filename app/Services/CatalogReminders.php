<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Jobs\SendCatalogReminder;
use App\Mail\CatalogReminderMail;
use App\Models\CatalogReminder;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MailAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reminders of the Catálogo: a member writes a subject and a text, picks a date and time, and the platform emails it to THAT MEMBER when the time comes.
 *
 * The recipient is never an input: it is the creator's own account, read at the moment of sending (so an address changed or a member removed since is honored).
 * Writes go through conditional updates of the row, so the scheduler, a worker and a person editing or cancelling at the same moment can not all win: exactly one
 * change applies. A reminder is handed to the queue once (an atomic claim), sent by a single worker, and marked `sent` only after the mailer accepted it. A failure
 * that happened before the message could have left is retried a bounded number of times; a worker that died mid-send leaves it `uncertain` and it is never
 * resent by itself. Limit: SMTP has no idempotency, so when the server accepts a message but the confirmation is lost, an automatic retry could repeat it; the
 * stable Message-ID and the small attempt limit reduce that, they do not remove it.
 */
class CatalogReminders
{
    private const SOURCE = 'Recordatorios del catálogo (automático)';

    public function __construct(private readonly AuditLogger $audit, private readonly ModuleAccess $modules) {}

    public function ready(): bool
    {
        return Schema::hasTable('catalog_reminders');
    }

    // --- what the member does ----------------------------------------------------------------------------------------------------

    /**
     * Validates the form. `scheduled_at` is a local date and time (`Y-m-d\TH:i`) in the Workspace's time zone.
     *
     * @param  array<string, mixed>  $input
     * @return array{subject: string, body: string, scheduled_at: CarbonImmutable}
     */
    public function validated(Workspace $workspace, array $input): array
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($input['body'] ?? '')));
        $errors = [];

        if ($subject === '' || mb_strlen($subject) > (int) config('catalog_reminders.subject_max') || preg_match('/[\r\n]/', $subject)) {
            $errors['subject'] = 'Escribe un asunto de una sola línea, de hasta '.config('catalog_reminders.subject_max').' caracteres.';
        }

        if ($body === '' || mb_strlen($body) > (int) config('catalog_reminders.body_max')) {
            $errors['body'] = 'Escribe el mensaje (hasta '.config('catalog_reminders.body_max').' caracteres).';
        }

        $at = null;
        $timezone = $workspace->settingsOrDefault()->timezone;

        if (! is_string($input['scheduled_at'] ?? null) || ! CarbonImmutable::canBeCreatedFromFormat($input['scheduled_at'], 'Y-m-d\TH:i')) {
            $errors['scheduled_at'] = 'Elige la fecha y la hora del envío.';
        } else {
            $at = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $input['scheduled_at'], $timezone)->utc();

            if ($at->lessThanOrEqualTo(now()->addMinute())) {
                $errors['scheduled_at'] = 'La fecha y la hora deben ser futuras.';
            } elseif ($at->greaterThan(now()->addDays((int) config('catalog_reminders.max_days_ahead')))) {
                $errors['scheduled_at'] = 'No se puede programar a más de '.config('catalog_reminders.max_days_ahead').' días.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['subject' => $subject, 'body' => $body, 'scheduled_at' => $at];
    }

    /** The reminder is the CREATOR's: `created_by` comes from the signed-in member, the Workspace from the session, never from the form. */
    public function create(Workspace $workspace, User $creator, array $input): CatalogReminder
    {
        $data = $this->validated($workspace, $input);
        $reminder = new CatalogReminder($data);
        $reminder->workspace_id = $workspace->id;
        $reminder->created_by = $creator->id;
        $reminder->status = CatalogReminder::SCHEDULED;
        $reminder->save();

        return $reminder;
    }

    /**
     * Edits a reminder that has not gone out: only its creator, and only while it waits (scheduled) or after it failed. It goes back to `scheduled` with its
     * attempts cleared. One that is queued or being sent can not be edited (it is already on its way), nor one that was sent, cancelled, skipped or is uncertain
     * (its history is not rewritten): for an uncertain one there is an explicit retry.
     */
    public function update(CatalogReminder $reminder, User $actor, array $input): CatalogReminder
    {
        $this->assertCreator($reminder, $actor, 'Solo quien lo creó puede editar este recordatorio.');
        $data = $this->validated($reminder->workspace, $input);

        $changed = CatalogReminder::whereKey($reminder->id)->where('workspace_id', $reminder->workspace_id)->whereIn('status', [CatalogReminder::SCHEDULED, CatalogReminder::FAILED])
            ->update($data + ['status' => CatalogReminder::SCHEDULED, 'attempts' => 0, 'next_attempt_at' => null, 'reason' => null, 'updated_at' => now()]);

        if ($changed !== 1) {
            throw ValidationException::withMessages(['status' => 'Este recordatorio ya no se puede editar: '.mb_strtolower(CatalogReminder::LABELS[$reminder->fresh()->status]).'.']);
        }

        $reminder = $reminder->fresh();
        $this->audit->recordForActor('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Fecha de envío', null, $reminder->scheduled_at->toDateTimeString().' UTC'), AuditLogger::change('Estado', null, CatalogReminder::LABELS[$reminder->status])], $reminder->workspace, self::SOURCE);

        return $reminder;
    }

    /** Cancels one that has not gone out (scheduled, queued, failed, uncertain): its creator or any member who may manage reminders. A queued one is stopped by the worker's own claim. */
    public function cancel(CatalogReminder $reminder, User $actor): CatalogReminder
    {
        $changed = CatalogReminder::whereKey($reminder->id)->where('workspace_id', $reminder->workspace_id)->whereIn('status', [CatalogReminder::SCHEDULED, CatalogReminder::QUEUED, CatalogReminder::FAILED, CatalogReminder::UNCERTAIN])
            ->update(['status' => CatalogReminder::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'next_attempt_at' => null, 'updated_at' => now()]);

        if ($changed !== 1) {
            throw ValidationException::withMessages(['status' => 'Este recordatorio ya no se puede cancelar: '.mb_strtolower(CatalogReminder::LABELS[$reminder->fresh()->status]).'.']);
        }

        $reminder = $reminder->fresh();
        $this->audit->recordForActor('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Estado', null, 'Cancelado')], $reminder->workspace, self::SOURCE);

        return $reminder;
    }

    /** Explicit retry of one that failed or is uncertain (the creator accepts that an uncertain one may be repeated): back to waiting, due now. */
    public function retry(CatalogReminder $reminder, User $actor): CatalogReminder
    {
        $this->assertCreator($reminder, $actor, 'Solo quien lo creó puede reintentarlo.');

        $changed = CatalogReminder::whereKey($reminder->id)->where('workspace_id', $reminder->workspace_id)->whereIn('status', [CatalogReminder::FAILED, CatalogReminder::UNCERTAIN])
            ->update(['status' => CatalogReminder::SCHEDULED, 'attempts' => 0, 'next_attempt_at' => null, 'reason' => null, 'scheduled_at' => max($reminder->scheduled_at, now()), 'updated_at' => now()]);

        if ($changed !== 1) {
            throw ValidationException::withMessages(['status' => 'Solo se reintenta un recordatorio que falló o quedó incierto.']);
        }

        $reminder = $reminder->fresh();
        $this->audit->recordForActor('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Estado', null, 'Reintento pedido por su creador')], $reminder->workspace, self::SOURCE);

        return $reminder;
    }

    private function assertCreator(CatalogReminder $reminder, User $actor, string $message): void
    {
        abort_unless((int) $reminder->created_by === (int) $actor->id, 403, $message);
    }

    // --- the scheduler -----------------------------------------------------------------------------------------------------------

    /** Hands every due reminder to the queue exactly once. An atomic claim: only the process that moves the row from `scheduled` to `queued` dispatches it. */
    public function dispatchDue(): int
    {
        $queued = 0;

        CatalogReminder::where('status', CatalogReminder::SCHEDULED)->where('scheduled_at', '<=', now())->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('scheduled_at')->limit(100)->pluck('id')->each(function (int $id) use (&$queued) {
                if (CatalogReminder::where('id', $id)->where('status', CatalogReminder::SCHEDULED)->update(['status' => CatalogReminder::QUEUED, 'updated_at' => now()]) === 1) {
                    SendCatalogReminder::dispatch($id);
                    $queued++;
                }
            });

        return $queued;
    }

    /** A lost queue or a killed worker: what was only queued goes back to waiting; what was being sent MAY have left, so it is uncertain (never resent by itself). */
    public function recoverStuck(): int
    {
        $cutoff = now()->subMinutes((int) config('catalog_reminders.stuck_minutes'));
        $queued = CatalogReminder::where('status', CatalogReminder::QUEUED)->where('updated_at', '<', $cutoff)->update(['status' => CatalogReminder::SCHEDULED, 'updated_at' => now()]);
        $stuck = CatalogReminder::where('status', CatalogReminder::SENDING)->where('updated_at', '<', $cutoff)->get();

        foreach ($stuck as $reminder) {
            $this->settle($reminder, CatalogReminder::UNCERTAIN, 'Se interrumpió mientras se enviaba: pudo haberse enviado. No se reintenta solo.');
        }

        return $queued + $stuck->count();
    }

    /** The queue gave up on the job (a crash): the same rule as a worker that died mid-send. */
    public function interrupted(int $id): void
    {
        $reminder = CatalogReminder::where('id', $id)->where('status', CatalogReminder::SENDING)->first();

        $reminder && $this->settle($reminder, CatalogReminder::UNCERTAIN, 'Se interrumpió mientras se enviaba: pudo haberse enviado. No se reintenta solo.');
    }

    // --- sending ----------------------------------------------------------------------------------------------------------------

    /** Sends one reminder (run by the queue). Re-checks everything NOW; only one worker can own it; never marks it sent unless the mailer accepted it. */
    public function send(int $id): void
    {
        // Only the process that moves the row from `queued` to `sending` goes on: a repeated job, a second worker or a reminder cancelled meanwhile does nothing.
        $claimed = CatalogReminder::where('id', $id)->where('status', CatalogReminder::QUEUED)
            ->update(['status' => CatalogReminder::SENDING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $reminder = CatalogReminder::with(['workspace', 'creator'])->findOrFail($id);

        if ($verdict = $this->notDeliverable($reminder)) {
            $this->settle($reminder, $verdict[0], $verdict[1]);

            return;
        }

        try {
            Mail::to($reminder->creator->email)->send(new CatalogReminderMail($reminder));
        } catch (Throwable $e) {
            // Only the class and a masked, short text are kept: an exception message may carry an address or a server's reply.
            $reason = class_basename($e).': '.Str::limit((string) preg_replace('/[\w.+-]+@[\w.-]+/', '[correo]', $e->getMessage()), 140, '');
            Log::error('catalog_reminder_failed', ['reminder' => $reminder->id, 'exception' => $e::class, 'attempt' => $reminder->attempts]);
            $this->failure($reminder, $reason);

            return;
        }

        CatalogReminder::whereKey($reminder->id)->where('status', CatalogReminder::SENDING)->update(['status' => CatalogReminder::SENT, 'sent_at' => now(), 'sent_to' => $reminder->creator->email, 'reason' => null, 'next_attempt_at' => null, 'updated_at' => now()]);
        $this->audit->system('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Estado', null, 'Enviado (intento '.$reminder->attempts.')')], $reminder->workspace, self::SOURCE);
    }

    /** The reason this reminder must not go out as written, or null. @return array{0: string, 1: string}|null [new status, fixed reason] */
    private function notDeliverable(CatalogReminder $reminder): ?array
    {
        $creator = $reminder->creator;
        $workspace = $reminder->workspace;

        return match (true) {
            $creator === null || ! $creator->is_active || blank($creator->email) => [CatalogReminder::SKIPPED, 'creator_inactive'],
            ! $workspace || ! $workspace->users()->whereKey($creator->id)->exists() => [CatalogReminder::SKIPPED, 'creator_not_member'],
            ! $this->modules->allows($workspace, 'product_catalog') => [CatalogReminder::SKIPPED, 'module_unavailable'],
            $reminder->scheduled_at->lessThan(now()->subHours((int) config('catalog_reminders.grace_hours'))) => [CatalogReminder::SKIPPED, 'expired'],
            ! MailAvailability::usable() => [CatalogReminder::FAILED, 'mail_not_configured'],
            default => null,
        };
    }

    /** A failure that happened while sending: another attempt later (bounded), or failed for good. */
    private function failure(CatalogReminder $reminder, string $reason): void
    {
        $max = (int) config('catalog_reminders.max_attempts');

        if ($reminder->attempts >= $max) {
            $this->settle($reminder, CatalogReminder::FAILED, $reason);

            return;
        }

        $backoff = (array) config('catalog_reminders.backoff_minutes');
        $wait = (int) ($backoff[min($reminder->attempts - 1, count($backoff) - 1)] ?? 30);
        CatalogReminder::whereKey($reminder->id)->where('status', CatalogReminder::SENDING)->update(['status' => CatalogReminder::SCHEDULED, 'next_attempt_at' => now()->addMinutes($wait), 'reason' => $reason, 'updated_at' => now()]);
        $this->audit->system('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Estado', null, "Fallo (intento {$reminder->attempts} de {$max}): se reintenta en {$wait} min")], $reminder->workspace, self::SOURCE, AuditLogger::FAILED);
    }

    private function settle(CatalogReminder $reminder, string $status, string $reason): void
    {
        $changed = CatalogReminder::whereKey($reminder->id)->whereIn('status', [CatalogReminder::SENDING, CatalogReminder::QUEUED])->update(['status' => $status, 'reason' => Str::limit($reason, 250, ''), 'next_attempt_at' => null, 'updated_at' => now()]);

        if ($changed === 1) {
            $this->audit->system('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Estado', null, CatalogReminder::LABELS[$status].' ('.Str::limit($reason, 120, '').')')], $reminder->workspace, self::SOURCE, $status === CatalogReminder::FAILED || $status === CatalogReminder::UNCERTAIN ? AuditLogger::FAILED : AuditLogger::SUCCESS);
        }
    }
}
