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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
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

    /** Whether the recipients table exists (the code can be deployed before the migration: reminders then keep going to their creator, as before). */
    public function recipientsReady(): bool
    {
        return Schema::hasTable('catalog_reminder_recipients');
    }

    // --- who can receive ---------------------------------------------------------------------------------------------------------

    /**
     * The people who can be chosen as recipients of a reminder of this Workspace: ENABLED members of the Workspace, with an email address, whose role may read the
     * reminders (`view-catalog-reminders`), so the notification only reaches people the Workspace already allows to see them. Nobody from another Workspace and
     * no account without a membership can appear. This is the single rule: the form lists these people, the server validates against them when it saves and
     * the worker checks it again when it sends.
     *
     * @return Collection<int, User> keyed by id, each with a `role` attribute
     */
    public function eligibleRecipients(Workspace $workspace): Collection
    {
        $allowed = Role::query()->where('guard_name', 'web')->whereHas('permissions', fn ($q) => $q->where('name', 'view-catalog-reminders'))->pluck('name')->all();

        return $workspace->users()->where('users.is_active', true)->whereNotNull('users.email')->wherePivotIn('role', $allowed)->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
            ->each(fn (User $user) => $user->setAttribute('role', $user->pivot->role))->keyBy('id');
    }

    /** @return list<int> the ids chosen for a reminder; `null` when the form did not name anyone (the creator is then the only recipient) */
    private function requestedRecipients(array $input): ?array
    {
        if (! array_key_exists('recipient_ids', $input)) {
            return null;
        }

        return collect(is_array($input['recipient_ids']) ? $input['recipient_ids'] : [])->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Writes the recipients of a reminder (ids already validated as eligible). The rows of people who stay keep what was already delivered to them. */
    private function syncRecipients(CatalogReminder $reminder, array $ids): void
    {
        if ($this->recipientsReady()) {
            $reminder->recipients()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $reminder->workspace_id]])->all());
        }
    }

    /**
     * The people a reminder goes to, as the form and the sender see them: the chosen recipients, or the creator for a reminder with none (every reminder made
     * before recipients existed). @return Collection<int, User>
     */
    public function recipientsOf(CatalogReminder $reminder): Collection
    {
        $chosen = $this->recipientsReady() ? $reminder->recipients()->get() : collect();

        return $chosen->isNotEmpty() ? $chosen : collect($reminder->creator ? [$reminder->creator] : []);
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

    /**
     * The people the reminder goes to, validated: a list that names nobody is refused, and every person must be an eligible member of THIS Workspace (an id of
     * another Workspace, a disabled account or a member whose role may not read the reminders is a validation error, never silently dropped). A form that does not
     * name anyone at all (`recipient_ids` absent) keeps the previous behavior: the creator is the only recipient. The creator is never added behind their back.
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function validatedRecipients(Workspace $workspace, User $creator, array $input): array
    {
        $ids = $this->requestedRecipients($input) ?? [$creator->id];

        if ($ids === []) {
            throw ValidationException::withMessages(['recipient_ids' => 'Elige al menos una persona que reciba el recordatorio.']);
        }

        if (count($ids) > (int) config('catalog_reminders.max_recipients')) {
            throw ValidationException::withMessages(['recipient_ids' => 'Un recordatorio puede tener hasta '.config('catalog_reminders.max_recipients').' destinatarios.']);
        }

        if ($this->recipientsReady() && ! empty(array_diff($ids, $this->eligibleRecipients($workspace)->keys()->all()))) {
            throw ValidationException::withMessages(['recipient_ids' => 'Una de las personas elegidas no puede recibir este recordatorio: debe ser un miembro activo de este Workspace con acceso a los recordatorios.']);
        }

        return $ids;
    }

    /** The reminder is the CREATOR's: `created_by` comes from the signed-in member, the Workspace from the session, never from the form. */
    public function create(Workspace $workspace, User $creator, array $input): CatalogReminder
    {
        $data = $this->validated($workspace, $input);
        $recipients = $this->validatedRecipients($workspace, $creator, $input);

        return DB::transaction(function () use ($workspace, $creator, $data, $recipients) {
            $reminder = new CatalogReminder($data);
            $reminder->workspace_id = $workspace->id;
            $reminder->created_by = $creator->id;
            $reminder->status = CatalogReminder::SCHEDULED;
            $reminder->save();
            $this->syncRecipients($reminder, $recipients);

            return $reminder;
        });
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
        // An edit that does not name recipients keeps the ones it has (or the creator, for a reminder from before recipients existed).
        $recipients = $this->requestedRecipients($input) === null ? $this->recipientsOf($reminder)->pluck('id')->map(fn ($id) => (int) $id)->all() : $this->validatedRecipients($reminder->workspace, $actor, $input);

        $changed = DB::transaction(function () use ($reminder, $data, $recipients) {
            $changed = CatalogReminder::whereKey($reminder->id)->where('workspace_id', $reminder->workspace_id)->whereIn('status', [CatalogReminder::SCHEDULED, CatalogReminder::FAILED])
                ->update($data + ['status' => CatalogReminder::SCHEDULED, 'attempts' => 0, 'next_attempt_at' => null, 'reason' => null, 'updated_at' => now()]);

            $changed === 1 && $this->syncRecipients($reminder, $recipients);

            return $changed;
        });

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

        // The people it goes to are the ones saved with the reminder (re-checked NOW: the address, the membership and the access are read at the moment of sending),
        // or its creator for a reminder from before recipients existed. Whoever already received it is skipped, so a retry never repeats it to them.
        $saved = $this->recipientsReady() ? $reminder->recipients()->get() : collect();
        $legacy = $saved->isEmpty();
        $targets = $legacy ? collect($reminder->creator ? [$reminder->creator] : []) : $saved;
        $eligible = $legacy ? null : $this->eligibleRecipients($reminder->workspace);
        $delivered = [];
        $skipped = [];

        foreach ($targets as $user) {
            if (! $legacy && $user->pivot->sent_at !== null) {
                $delivered[] = $user->pivot->sent_to ?? $user->email;

                continue;
            }

            $problem = $legacy ? $this->creatorProblem($reminder, $user) : ($eligible->has($user->id) ? null : $this->recipientProblem($reminder->workspace, $user));

            if ($problem !== null) {
                $skipped[] = $problem;
                $legacy || $reminder->recipients()->updateExistingPivot($user->id, ['skip_reason' => $problem]);

                continue;
            }

            try {
                Mail::to($user->email)->send(new CatalogReminderMail($reminder, $user));
            } catch (Throwable $e) {
                // Only the class and a masked, short text are kept: an exception message may carry an address or a server's reply.
                $reason = class_basename($e).': '.Str::limit((string) preg_replace('/[\w.+-]+@[\w.-]+/', '[correo]', $e->getMessage()), 140, '');
                Log::error('catalog_reminder_failed', ['reminder' => $reminder->id, 'exception' => $e::class, 'attempt' => $reminder->attempts]);
                $this->failure($reminder, $reason);

                return;
            }

            $delivered[] = $user->email;
            $legacy || $reminder->recipients()->updateExistingPivot($user->id, ['sent_at' => now(), 'sent_to' => $user->email, 'skip_reason' => null]);
        }

        if ($delivered === []) {
            $this->settle($reminder, CatalogReminder::SKIPPED, $legacy ? ($skipped[0] ?? 'creator_inactive') : 'no_valid_recipient');

            return;
        }

        CatalogReminder::whereKey($reminder->id)->where('status', CatalogReminder::SENDING)->update([
            'status' => CatalogReminder::SENT, 'sent_at' => now(), 'sent_to' => count($delivered) === 1 ? $delivered[0] : count($delivered).' destinatarios',
            'reason' => $skipped === [] ? null : 'partial_recipients', 'next_attempt_at' => null, 'updated_at' => now(),
        ]);
        $this->audit->system('updated', 'catalog_reminder', $reminder->id, $reminder->subject, [AuditLogger::change('Estado', null, 'Enviado a '.count($delivered).' persona(s) (intento '.$reminder->attempts.')'.($skipped === [] ? '' : ', '.count($skipped).' sin avisar'))], $reminder->workspace, self::SOURCE);
    }

    /** The reason this reminder must not go out as written, whoever it goes to, or null. @return array{0: string, 1: string}|null [new status, fixed reason] */
    private function notDeliverable(CatalogReminder $reminder): ?array
    {
        $workspace = $reminder->workspace;

        return match (true) {
            ! $workspace => [CatalogReminder::SKIPPED, 'creator_not_member'],
            ! $this->modules->allows($workspace, 'product_catalog') => [CatalogReminder::SKIPPED, 'module_unavailable'],
            $reminder->scheduled_at->lessThan(now()->subHours((int) config('catalog_reminders.grace_hours'))) => [CatalogReminder::SKIPPED, 'expired'],
            ! MailAvailability::usable() => [CatalogReminder::FAILED, 'mail_not_configured'],
            default => null,
        };
    }

    /** Why the creator of a reminder without saved recipients can not be notified, or null (a fixed code). */
    private function creatorProblem(CatalogReminder $reminder, ?User $creator): ?string
    {
        return match (true) {
            $creator === null || ! $creator->is_active || blank($creator->email) => 'creator_inactive',
            ! $reminder->workspace->users()->whereKey($creator->id)->exists() => 'creator_not_member',
            default => null,
        };
    }

    /** Why a chosen recipient is not eligible any more (a fixed code). */
    private function recipientProblem(Workspace $workspace, User $user): string
    {
        return match (true) {
            ! $user->is_active || blank($user->email) => 'recipient_inactive',
            ! $workspace->users()->whereKey($user->id)->exists() => 'recipient_not_member',
            default => 'recipient_no_access',
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
