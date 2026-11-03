<?php

namespace App\Reminders;

use App\Models\Appointment;
use App\Models\BookingCustomer;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\ReminderRuleEvent;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns an automatic rule («30 days after a customer had service X») into ONE reminder per customer and appointment, without anyone choosing customers.
 *
 * The event that counts is an appointment of Agenda whose status is `completed` (the only status that says the service was really done: never requested, confirmed, cancelled,
 * no-show or failed) and whose service is one of the rule's. The moment is the start of that appointment plus the wait of the rule, in the Workspace's time zone, so two
 * customers served on different days get different dates. Only appointments from the rule's `activated_at` on count (switching a rule on never messages the whole history).
 *
 * It reads Agenda and writes only Recordatorios: no Agenda row, setting or notification is touched. Eligibility is judged here (customer card, consent, available channel,
 * contact data) and again by ReminderDispatcher right before sending. Every decision is a row of `reminder_rule_events` (one per rule and appointment): the history AND the
 * guard against duplicates, backed by the unique index of `reminders` and the deterministic idempotency key, so repeated or concurrent runs never schedule twice.
 */
class ReminderRuleScheduler
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly ReminderChannels $channels,
        private readonly ModuleAccess $modules,
    ) {}

    /**
     * One pass over the active rules (of one Workspace, or all). Idempotent: running it again changes nothing that is already decided.
     *
     * @return array{scheduled: int, skipped: int, cancelled: int}
     */
    public function scan(?Workspace $only = null): array
    {
        $totals = ['scheduled' => 0, 'skipped' => 0, 'cancelled' => 0];

        ReminderRule::query()->where('is_active', true)->when($only, fn ($q) => $q->where('workspace_id', $only->id))->with('workspace.organization')->orderBy('id')
            ->each(function (ReminderRule $rule) use (&$totals) {
                try {
                    foreach ($this->scanRule($rule) as $key => $count) {
                        $totals[$key] += $count;
                    }
                } catch (Throwable $e) {
                    // One rule that fails never stops the others. Only the class is logged: the text of an exception may carry customer data.
                    Log::error('reminder_rule_scan_error', ['exception' => $e::class, 'rule' => $rule->id]);
                }
            });

        return $totals;
    }

    /** @return array{scheduled: int, skipped: int, cancelled: int} */
    public function scanRule(ReminderRule $rule): array
    {
        $result = ['scheduled' => 0, 'skipped' => 0, 'cancelled' => 0];
        $workspace = $rule->workspace;

        if (! $workspace->is_active || ! $this->modules->allowsAll($workspace, ['reminders', 'customers', 'bookings'])) {
            return $result;
        }

        $result['cancelled'] += $this->reconcile($rule);

        $serviceIds = $rule->services()->pluck('services.id')->all();

        if ($serviceIds === []) {
            return $result;
        }

        $decided = ReminderRuleEvent::query()->where('reminder_rule_id', $rule->id)
            ->where(fn ($q) => $q->where('status', '!=', ReminderRuleEvent::SKIPPED)->orWhereNotIn('reason', (array) config('reminders.rules.retry_reasons')))
            ->select('appointment_id');

        $workspace->appointments()->with('customer')->where('status', Appointment::COMPLETED)->whereIn('service_id', $serviceIds)->where('starts_at', '>=', $rule->activated_at)
            ->whereNotIn('id', $decided)->orderBy('id')->limit((int) config('reminders.rules.scan_batch'))->get()
            ->each(function (Appointment $appointment) use ($rule, &$result) {
                $outcome = $this->evaluate($rule, $appointment);
                $result[$outcome === ReminderRuleEvent::SCHEDULED ? 'scheduled' : 'skipped']++;
            });

        return $result;
    }

    /**
     * Brings the reminders a rule already scheduled in line with the rule NOW (after an edit): the message, channel and moment are rewritten in place (the unique key keeps one
     * per appointment), and one that no longer applies is cancelled with its reason. A reminder a worker already claimed is left alone.
     */
    public function refresh(ReminderRule $rule): int
    {
        $changed = 0;
        $zone = $this->zone($rule->workspace);

        $this->pending($rule)->each(function (ReminderRuleEvent $event) use ($rule, $zone, &$changed) {
            $appointment = $rule->workspace->appointments()->find($event->appointment_id);
            $reason = $this->invalid($rule, $appointment);

            if ($reason === null) {
                try {
                    $this->reminders->update($event->reminder, $this->data($rule, $appointment, $event->customer_id, $this->sendAt($rule, $appointment, $zone, false), $zone), 'rule');
                    $changed++;

                    return;
                } catch (ValidationException) {
                    $reason = 'rule_changed';
                }
            }

            $this->cancelEvent($event, $reason);
            $changed++;
        });

        return $changed;
    }

    /** Cancels what a rule scheduled and has not been sent (the rule was switched off). Returns how many. */
    public function cancelPending(ReminderRule $rule, string $reason): int
    {
        $count = 0;

        $this->pending($rule)->each(function (ReminderRuleEvent $event) use ($reason, &$count) {
            $this->cancelEvent($event, $reason);
            $count++;
        });

        return $count;
    }

    /** Cancels the scheduled reminders whose appointment stopped being a completed service of the rule (changed by hand in Agenda). */
    private function reconcile(ReminderRule $rule): int
    {
        $cancelled = 0;

        $this->pending($rule)->each(function (ReminderRuleEvent $event) use ($rule, &$cancelled) {
            $reason = $this->invalid($rule, $rule->workspace->appointments()->find($event->appointment_id));

            if ($reason !== null) {
                $this->cancelEvent($event, $reason);
                $cancelled++;
            }
        });

        return $cancelled;
    }

    /** @return LazyCollection<int, ReminderRuleEvent> events whose reminder has not started to be sent */
    private function pending(ReminderRule $rule)
    {
        return ReminderRuleEvent::query()->where('reminder_rule_id', $rule->id)->where('status', ReminderRuleEvent::SCHEDULED)
            ->whereHas('reminder', fn ($q) => $q->where('status', Reminder::SCHEDULED))->with('reminder.workspace')->orderBy('id')->lazyById(200);
    }

    private function cancelEvent(ReminderRuleEvent $event, string $reason): void
    {
        try {
            $this->reminders->cancel($event->reminder, 'rule', $reason);
        } catch (ValidationException) {
            return; // a worker claimed it in the meantime: the sending lifecycle owns it now
        }

        $event->forceFill(['status' => ReminderRuleEvent::CANCELLED, 'reason' => $reason, 'evaluated_at' => now()])->save();
    }

    /** Why this appointment no longer counts for the rule, or null. */
    private function invalid(ReminderRule $rule, ?Appointment $appointment): ?string
    {
        return match (true) {
            ! $rule->is_active => 'rule_inactive',
            $appointment === null || $appointment->status !== Appointment::COMPLETED => 'appointment_changed',
            ! $rule->services()->whereKey($appointment->service_id)->exists() => 'rule_changed',
            default => null,
        };
    }

    /** Decides one appointment: schedules the reminder or records why not. Returns the event status. */
    private function evaluate(ReminderRule $rule, Appointment $appointment): string
    {
        $workspace = $rule->workspace;
        $zone = $this->zone($workspace);
        $customer = $this->customerOf($workspace, $appointment->customer);

        if (is_string($customer)) {
            return $this->record($rule, $appointment, null, ReminderRuleEvent::SKIPPED, $customer);
        }

        if ($reason = $this->blocked($workspace, $rule, $customer)) {
            return $this->record($rule, $appointment, $customer, ReminderRuleEvent::SKIPPED, $reason);
        }

        $sendAt = $this->sendAt($rule, $appointment, $zone, true);

        if ($sendAt === null) {
            return $this->record($rule, $appointment, $customer, ReminderRuleEvent::SKIPPED, 'too_late');
        }

        try {
            $reminder = $this->reminders->create($workspace, $this->data($rule, $appointment, $customer->id, $sendAt, $zone) + ['idempotency_key' => "rule-{$rule->id}-appointment-{$appointment->id}"], null, 'rule', ['reminder_rule_id' => $rule->id])['reminder'];
        } catch (ValidationException) {
            return $this->record($rule, $appointment, $customer, ReminderRuleEvent::SKIPPED, 'invalid');
        }

        return $this->record($rule, $appointment, $customer, ReminderRuleEvent::SCHEDULED, null, $reminder->id);
    }

    /** One row per rule and appointment; a race between two runs ends in the same row. */
    private function record(ReminderRule $rule, Appointment $appointment, ?Customer $customer, string $status, ?string $reason, ?int $reminderId = null): string
    {
        $values = ['workspace_id' => $rule->workspace_id, 'customer_id' => $customer?->id, 'reminder_id' => $reminderId, 'status' => $status, 'reason' => $reason, 'evaluated_at' => now()];

        try {
            ReminderRuleEvent::query()->updateOrCreate(['reminder_rule_id' => $rule->id, 'appointment_id' => $appointment->id], $values);
        } catch (UniqueConstraintViolationException) {
            // The other run decided it first: nothing to add.
        }

        return $status;
    }

    /**
     * The customer card of the person who booked, found by the same normalized phone or e-mail Agenda keeps (a booking has no card of its own). A string is the reason it can not
     * be used: no card, or the phone and the e-mail point at DIFFERENT cards (Ava never guesses which one).
     */
    private function customerOf(Workspace $workspace, ?BookingCustomer $booked): Customer|string
    {
        $phone = BookingCustomer::normalizePhone($booked?->phone);
        $email = Customer::normalizeEmail($booked?->email);

        if ($phone === null && $email === null) {
            return 'no_customer';
        }

        $matches = $workspace->customers()->whereNull('anonymized_at')->where(function ($q) use ($phone, $email) {
            if ($phone !== null) {
                $q->orWhere('phone_normalized', $phone)->orWhere('phone_e164', '+'.$phone);
            }

            if ($email !== null) {
                $q->orWhere('email_normalized', $email);
            }
        })->limit(2)->get();

        return match ($matches->count()) {
            0 => 'no_customer',
            1 => $matches->first(),
            default => 'ambiguous_customer',
        };
    }

    /** Whether this customer can receive this rule's message now (judged again when sending). */
    private function blocked(Workspace $workspace, ReminderRule $rule, Customer $customer): ?string
    {
        return match (true) {
            ! $customer->is_active => 'customer_unavailable',
            config('reminders.require_consent') && $customer->marketing_consent !== Customer::CONSENT_GRANTED => 'no_consent',
            ! $this->channels->check($workspace, $rule->channel)['ok'] => 'channel_unavailable',
            $rule->channel === 'email' && blank($customer->email) => 'no_email',
            $rule->channel === 'whatsapp' && blank($customer->phone_e164) && blank($customer->phone_normalized) => 'no_valid_phone',
            default => null,
        };
    }

    /**
     * When the reminder goes out: the appointment's start plus the wait, in the Workspace's zone (days and weeks go out at the rule's hour of the day). A moment already past is
     * brought to «now» if it is within the grace period (the scan found it late) and `null` beyond it; with `$strict` false (a refresh of a pending reminder) a past moment is
     * simply brought to «now».
     */
    private function sendAt(ReminderRule $rule, Appointment $appointment, string $zone, bool $strict): ?Carbon
    {
        $moment = $appointment->starts_at->copy()->setTimezone($zone);

        if ($rule->delay_unit === 'hours') {
            $moment->addHours($rule->delay_amount);
        } else {
            [$hour, $minute] = array_map('intval', explode(':', $rule->send_time ?: (string) config('reminders.rules.default_send_time')));
            $moment->addDays($rule->delay_amount * ($rule->delay_unit === 'weeks' ? 7 : 1))->setTime($hour, $minute);
        }

        $earliest = now()->addMinutes((int) config('reminders.limits.min_lead_minutes') + 1);

        if ($moment->greaterThanOrEqualTo($earliest)) {
            return $moment;
        }

        if ($strict && $moment->lessThan(now()->subHours((int) config('reminders.rules.grace_hours')))) {
            return null;
        }

        return $earliest->setTimezone($zone);
    }

    /**
     * What ReminderService needs to create or rewrite the reminder: the rule's message, the customer and the appointment as the related record.
     *
     * @return array<string, mixed>
     */
    private function data(ReminderRule $rule, Appointment $appointment, int $customerId, Carbon $sendAt, string $zone): array
    {
        return [
            'customer_id' => $customerId,
            'name' => $rule->name,
            'purpose' => null,
            'related_type' => 'appointment',
            'related_id' => $appointment->id,
            'channel' => $rule->channel,
            'subject' => $rule->subject,
            'body' => $rule->body,
            'template_name' => $rule->template_name,
            'template_language' => $rule->template_language,
            'template_params' => $rule->template_params ?? [],
            'send_at' => $sendAt->copy()->setTimezone($zone)->format('Y-m-d\TH:i'),
            'timezone' => $zone,
        ];
    }

    private function zone(Workspace $workspace): string
    {
        return $workspace->settingsOrDefault()->timezone ?: 'UTC';
    }
}
