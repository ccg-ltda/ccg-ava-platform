<?php

namespace App\Reminders;

use App\Campaigns\MessageTemplates;
use App\Integrations\IntegrationRegistry;
use App\Integrations\SendResult;
use App\Integrations\SendsTemplates;
use App\Jobs\SendReminder;
use App\Mail\ReminderMail;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\ReminderAttempt;
use App\Services\ModuleAccess;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The lifecycle of SENDING a reminder, separate from the person's changes (ReminderService) and from Agenda's own notifications (App\Booking\AppointmentNotifier, untouched).
 *  - `dispatchDue()` (every minute, `reminders:dispatch`) queues the reminders whose time has come; a request never waits for a date.
 *  - `send()` claims ONE reminder with a conditional UPDATE (`scheduled` -> `processing`), so two workers, a repeated job or a recovery run never send the same attempt twice;
 *    then it re-checks EVERYTHING now (plan, customer, consent, channel, contact data, the related appointment or order) and only then sends.
 *  - A failure where nothing went out and trying again can help is retried with backoff (`config/reminders.php`); an outcome that may have left (a timeout after sending, a crash
 *    between `processing` and the result) becomes `uncertain` and is never re-sent by itself: delivery of e-mail and WhatsApp has no idempotency, so Ava prefers a missing
 *    reminder a person can see to a duplicate one a customer sees.
 */
class ReminderDispatcher
{
    public function __construct(
        private readonly ReminderChannels $channels,
        private readonly MessageTemplates $templates,
        private readonly IntegrationRegistry $types,
        private readonly ModuleAccess $modules,
    ) {}

    /** @return array{queued: int, interrupted: int} */
    public function dispatchDue(): array
    {
        // A reminder stuck in `processing` (the worker died): closed as uncertain, never re-sent.
        $stale = Reminder::query()->where('status', Reminder::PROCESSING)->where('updated_at', '<', now()->subMinutes((int) config('reminders.retries.stale_processing_minutes')))->get();

        foreach ($stale as $reminder) {
            $this->settle($reminder, Reminder::UNCERTAIN, 'interrupted', 'uncertain');
        }

        $queued = 0;

        Reminder::query()->where('status', Reminder::SCHEDULED)->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit((int) config('reminders.dispatch_batch'))->pluck('id')
            ->each(function (int $id) use (&$queued) {
                try {
                    SendReminder::dispatch($id);
                    $queued++;
                } catch (Throwable $e) {
                    report($e); // the row stays `scheduled`: the next minute queues it again
                }
            });

        return ['queued' => $queued, 'interrupted' => $stale->count()];
    }

    public function send(int $id): void
    {
        $claimed = Reminder::query()->whereKey($id)->where('status', Reminder::SCHEDULED)->where('next_attempt_at', '<=', now())
            ->update(['status' => Reminder::PROCESSING, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $reminder = Reminder::query()->with(['customer', 'workspace.organization'])->findOrFail($id);

        if ($verdict = $this->obsolete($reminder)) {
            $this->settle($reminder, $verdict[0], $verdict[1], $verdict[0]);

            return;
        }

        $customer = $reminder->customer;
        $integration = $this->channels->check($reminder->workspace, $reminder->channel)['integration'];

        try {
            $result = match ($reminder->channel) {
                'email' => $this->email($reminder, $customer),
                'whatsapp' => $this->whatsapp($reminder, $customer, $integration),
            };
        } catch (Throwable $e) {
            // Only the class is logged: the text of an exception may carry an address or a credential.
            Log::error('reminder_send_error', ['exception' => $e::class, 'reminder' => $reminder->id]);
            $result = $reminder->channel === 'email' ? SendResult::failed('send_failed', true) : SendResult::uncertain('interrupted');
        }

        $this->record($reminder, $result);
    }

    /** The reason this reminder must not go out any more, or null. @return array{0: string, 1: string}|null [new status, fixed reason] */
    private function obsolete(Reminder $reminder): ?array
    {
        $workspace = $reminder->workspace;
        $customer = $reminder->customer;
        $channel = $this->channels->check($workspace, $reminder->channel);

        return match (true) {
            ! $this->modules->allowsAll($workspace, ['reminders', 'customers']) => [Reminder::SKIPPED, 'plan'],
            $customer === null || ! $customer->is_active || $customer->anonymized_at !== null => [Reminder::SKIPPED, 'customer_unavailable'],
            config('reminders.require_consent') && $customer->marketing_consent !== Customer::CONSENT_GRANTED => [Reminder::SKIPPED, 'no_consent'],
            ! $channel['ok'] => [Reminder::SKIPPED, 'channel_unavailable'],
            $reminder->channel === 'email' && blank($customer->email) => [Reminder::SKIPPED, 'no_email'],
            $reminder->channel === 'whatsapp' && $this->digits($customer) === null => [Reminder::SKIPPED, 'no_valid_phone'],
            $reminder->channel === 'whatsapp' && blank($reminder->template_name) => [Reminder::SKIPPED, 'template_missing'],
            $this->relatedClosed($reminder, 'appointment') => [Reminder::CANCELLED, 'appointment_closed'],
            $this->relatedClosed($reminder, 'order') => [Reminder::CANCELLED, 'order_closed'],
            default => null,
        };
    }

    /** A follow-up tied to an appointment or an order is pointless once that record is gone, cancelled or (for an appointment) already over. */
    private function relatedClosed(Reminder $reminder, string $type): bool
    {
        if ($reminder->related_type !== $type) {
            return false;
        }

        $workspace = $reminder->workspace;

        if ($type === 'appointment') {
            $appointment = $workspace->appointments()->find($reminder->related_id);

            return $appointment === null || ! $appointment->isActive();
        }

        $order = $workspace->orders()->find($reminder->related_id);

        return $order === null || in_array($order->status, ['cancelled', 'rejected'], true);
    }

    private function email(Reminder $reminder, Customer $customer): SendResult
    {
        Mail::to($customer->email)->send(new ReminderMail($reminder, $this->templates->render($reminder->body, $customer)));

        return SendResult::accepted(null);
    }

    private function whatsapp(Reminder $reminder, Customer $customer, $integration): SendResult
    {
        $type = $this->types->get($integration->type);

        if (! $type instanceof SendsTemplates) {
            return SendResult::failed('channel_unavailable');
        }

        $params = array_map(fn (string $variable) => $this->templates->valueFor($customer, $variable) ?? '', (array) $reminder->template_params);

        return $type->sendTemplate($integration, (string) $this->digits($customer), (string) $reminder->template_name, (string) $reminder->template_language, $params, "rmd:{$reminder->id}");
    }

    private function digits(Customer $customer): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($customer->phone_e164 ?: $customer->phone_normalized));

        return $digits !== '' && PhoneNumber::e164('+'.$digits) !== null ? $digits : null;
    }

    private function record(Reminder $reminder, SendResult $result): void
    {
        $max = (int) config('reminders.retries.max_attempts');
        $backoff = (array) config('reminders.retries.backoff_seconds');

        match (true) {
            $result->ok => $this->finish($reminder, ['status' => Reminder::SENT, 'sent_at' => now(), 'external_id' => $result->externalId, 'reason' => null, 'next_attempt_at' => null], 'sent', null),
            $result->uncertain => $this->settle($reminder, Reminder::UNCERTAIN, 'interrupted', 'uncertain'),
            $result->retryable && $reminder->attempts < $max => $this->finish($reminder, ['status' => Reminder::SCHEDULED, 'reason' => 'send_failed', 'next_attempt_at' => now()->addSeconds((int) ($backoff[$reminder->attempts - 1] ?? 300))], 'retry', 'send_failed'),
            default => $this->settle($reminder, Reminder::FAILED, 'send_failed', 'failed'),
        };
    }

    private function settle(Reminder $reminder, string $status, string $reason, string $outcome): void
    {
        $this->finish($reminder, ['status' => $status, 'reason' => $reason, 'next_attempt_at' => null], $outcome, $reason);
    }

    /** @param  array<string, mixed>  $attributes */
    private function finish(Reminder $reminder, array $attributes, string $outcome, ?string $reason): void
    {
        $reminder->forceFill($attributes)->save();

        ReminderAttempt::create(['reminder_id' => $reminder->id, 'workspace_id' => $reminder->workspace_id, 'attempt' => max(1, $reminder->attempts), 'outcome' => $outcome, 'reason' => $reason]);
    }
}
