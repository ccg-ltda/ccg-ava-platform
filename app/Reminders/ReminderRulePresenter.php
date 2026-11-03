<?php

namespace App\Reminders;

use App\Models\Customer;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\ReminderRuleEvent;
use App\Models\Workspace;

/** What the Recordatorios page shows about the rules of a Workspace: the rule, how many reminders it produced in each state, and the history of what it decided. Read only. */
class ReminderRulePresenter
{
    /** @return list<array<string, mixed>> */
    public function list(Workspace $workspace): array
    {
        $rules = $workspace->reminderRules()->with('services:id,name')->orderByDesc('is_active')->orderBy('name')->get();
        $ids = $rules->pluck('id');

        $reminders = Reminder::query()->where('workspace_id', $workspace->id)->whereIn('reminder_rule_id', $ids)->selectRaw('reminder_rule_id, status, count(*) as total')->groupBy('reminder_rule_id', 'status')->get()->groupBy('reminder_rule_id');
        $skipped = ReminderRuleEvent::query()->where('workspace_id', $workspace->id)->whereIn('reminder_rule_id', $ids)->where('status', ReminderRuleEvent::SKIPPED)->selectRaw('reminder_rule_id, count(*) as total')->groupBy('reminder_rule_id')->pluck('total', 'reminder_rule_id');

        return $rules->map(function (ReminderRule $rule) use ($reminders, $skipped) {
            $counts = $reminders->get($rule->id, collect())->pluck('total', 'status');

            return [
                'id' => $rule->id,
                'name' => $rule->name,
                'isActive' => $rule->is_active,
                'services' => $rule->services->map(fn ($service) => ['id' => $service->id, 'name' => $service->name])->values()->all(),
                'delayAmount' => $rule->delay_amount,
                'delayUnit' => $rule->delay_unit,
                'sendTime' => $rule->send_time,
                'channel' => $rule->channel,
                'subject' => $rule->subject,
                'body' => $rule->body,
                'templateName' => $rule->template_name,
                'templateLanguage' => $rule->template_language,
                'templateParams' => $rule->template_params ?? [],
                'stats' => [
                    'scheduled' => (int) ($counts[Reminder::SCHEDULED] ?? 0) + (int) ($counts[Reminder::PROCESSING] ?? 0),
                    'sent' => (int) ($counts[Reminder::SENT] ?? 0),
                    'failed' => (int) ($counts[Reminder::FAILED] ?? 0) + (int) ($counts[Reminder::UNCERTAIN] ?? 0),
                    'skipped' => (int) ($skipped[$rule->id] ?? 0) + (int) ($counts[Reminder::SKIPPED] ?? 0),
                    'cancelled' => (int) ($counts[Reminder::CANCELLED] ?? 0),
                ],
            ];
        })->all();
    }

    /**
     * The services a rule can cover: the active ones of the Workspace.
     *
     * @return list<array{id: int, name: string}>
     */
    public function services(Workspace $workspace): array
    {
        return $workspace->services()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($service) => ['id' => $service->id, 'name' => $service->name])->all();
    }

    /**
     * The last decisions of one rule: which appointment, which customer, what it did and why.
     *
     * @return list<array<string, mixed>>
     */
    public function events(ReminderRule $rule): array
    {
        $settings = $rule->workspace->settingsOrDefault();
        $reasons = config('reminders.reasons');
        $customers = Customer::query()->where('workspace_id', $rule->workspace_id)->whereIn('id', ReminderRuleEvent::query()->where('reminder_rule_id', $rule->id)->whereNotNull('customer_id')->select('customer_id'))->get()->keyBy('id');

        return ReminderRuleEvent::query()->where('reminder_rule_id', $rule->id)->where('workspace_id', $rule->workspace_id)->with('reminder:id,workspace_id,status,send_at,reason,sent_at')->orderByDesc('id')->limit(50)->get()
            ->map(function (ReminderRuleEvent $event) use ($customers, $reasons, $settings) {
                $reminder = $event->reminder;
                $live = $reminder && $event->status === ReminderRuleEvent::SCHEDULED;
                $reason = $live ? $reminder->reason : $event->reason;

                return [
                    'id' => $event->id,
                    'appointmentId' => $event->appointment_id,
                    'customer' => $event->customer_id ? ($customers->get($event->customer_id)?->fullName() ?? '—') : null,
                    'status' => $live ? $reminder->status : $event->status,
                    'reason' => $reason ? ($reasons[$reason] ?? $reason) : null,
                    'sendAt' => $reminder ? $settings->formatDate($reminder->send_at).' '.$settings->formatTime($reminder->send_at) : null,
                    'at' => $settings->formatDate($event->evaluated_at).' '.$settings->formatTime($event->evaluated_at),
                ];
            })->all();
    }
}
