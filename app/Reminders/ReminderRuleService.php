<?php

namespace App\Reminders;

use App\Audit\AuditLogger;
use App\Models\ReminderRule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change a person makes to an automatic reminder rule, validated in the SERVER against the Workspace: the services must belong to it and the channel and the message obey
 * the same checks as any reminder (ReminderService::message). It is the only writer of `reminder_rules`; turning a rule into reminders is ReminderRuleScheduler's, and sending them
 * is ReminderDispatcher's. A rule is never deleted: it is switched off (and what it scheduled and has not gone out is cancelled). Every change is audited.
 */
class ReminderRuleService
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly ReminderRuleScheduler $scheduler,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $data  validated by SaveReminderRuleRequest */
    public function create(Workspace $workspace, array $data, ?User $by): ReminderRule
    {
        if ($workspace->reminderRules()->count() >= (int) config('reminders.rules.max_per_workspace')) {
            throw ValidationException::withMessages(['name' => 'Este Workspace alcanzó el máximo de reglas. Desactiva o edita una existente.']);
        }

        $attributes = $this->attributes($workspace, $data);
        $serviceIds = $this->services($workspace, $data);

        $rule = DB::transaction(function () use ($workspace, $attributes, $serviceIds, $by) {
            $rule = $workspace->reminderRules()->make($attributes + ['created_by_user_id' => $by?->id]);
            $rule->forceFill(['is_active' => true, 'activated_at' => now()])->save();
            $this->link($rule, $serviceIds);

            return $rule;
        });

        $this->audit->record('created', 'reminder_rule', $rule->id, $rule->name, [AuditLogger::change('Espera', null, $this->waitLabel($rule)), AuditLogger::change('Canal', null, $rule->channel), AuditLogger::change('Servicios', null, (string) count($serviceIds))], $workspace);

        return $rule;
    }

    /** @param  array<string, mixed>  $data */
    public function update(ReminderRule $rule, array $data): ReminderRule
    {
        $workspace = $rule->workspace;
        $attributes = $this->attributes($workspace, $data);
        $serviceIds = $this->services($workspace, $data);
        $before = $this->waitLabel($rule).' · '.$rule->channel.' · '.$rule->services()->count().' servicios';

        DB::transaction(function () use ($rule, $attributes, $serviceIds) {
            $rule->forceFill($attributes)->save();
            $this->link($rule, $serviceIds);
        });

        // What the rule already scheduled follows the new message, wait and services (a claimed one is left alone).
        if ($rule->is_active) {
            $this->scheduler->refresh($rule);
        }

        $this->audit->record('updated', 'reminder_rule', $rule->id, $rule->name, [AuditLogger::change('Configuración', $before, $this->waitLabel($rule).' · '.$rule->channel.' · '.count($serviceIds).' servicios')], $workspace);

        return $rule;
    }

    /**
     * Switches a rule on or off. Off cancels what it scheduled and has not gone out; on starts counting services done FROM NOW (the history is never messaged retroactively).
     */
    public function setActive(ReminderRule $rule, bool $active): ReminderRule
    {
        if ($rule->is_active === $active) {
            return $rule;
        }

        $cancelled = 0;

        $rule->forceFill($active ? ['is_active' => true, 'activated_at' => now()] : ['is_active' => false])->save();

        if (! $active) {
            $cancelled = $this->scheduler->cancelPending($rule, 'rule_inactive');
        }

        $this->audit->record('updated', 'reminder_rule', $rule->id, $rule->name, [AuditLogger::change('Estado', $active ? 'Desactivada' : 'Activa', $active ? 'Activa' : 'Desactivada'.($cancelled > 0 ? " ({$cancelled} recordatorios cancelados)" : ''))], $rule->workspace);

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Workspace $workspace, array $data): array
    {
        $unit = (string) ($data['delay_unit'] ?? '');
        $amount = (int) ($data['delay_amount'] ?? 0);
        $maxDays = (int) config('reminders.rules.max_delay_days');
        $limit = match ($unit) {
            'hours' => $maxDays * 24,
            'weeks' => intdiv($maxDays, 7),
            'days' => $maxDays,
            default => throw ValidationException::withMessages(['delay_unit' => 'Elige horas, días o semanas.']),
        };

        if ($amount < 1 || $amount > $limit) {
            throw ValidationException::withMessages(['delay_amount' => "La espera debe estar entre 1 y {$limit}."]);
        }

        $message = $this->reminders->message($workspace, $data, true);

        return [
            'name' => trim((string) $data['name']),
            'delay_amount' => $amount,
            'delay_unit' => $unit,
            'send_time' => $unit === 'hours' ? null : (filled($data['send_time'] ?? null) ? (string) $data['send_time'] : null),
            ...$message,
        ];
    }

    /**
     * The services of THIS Workspace the rule covers, whatever ids the client sends (another Workspace's id looks exactly like a missing one).
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function services(Workspace $workspace, array $data): array
    {
        $ids = collect((array) ($data['service_ids'] ?? []))->map(fn ($id) => (int) $id)->unique()->values();
        $found = $workspace->services()->whereIn('id', $ids)->pluck('id');

        if ($ids->isEmpty() || $found->count() !== $ids->count()) {
            throw ValidationException::withMessages(['service_ids' => 'Elige al menos un servicio de este Workspace.']);
        }

        return $found->all();
    }

    /** @param  list<int>  $serviceIds */
    private function link(ReminderRule $rule, array $serviceIds): void
    {
        $rule->services()->syncWithPivotValues($serviceIds, ['workspace_id' => $rule->workspace_id]);
    }

    private function waitLabel(ReminderRule $rule): string
    {
        return $rule->delay_amount.' '.mb_strtolower(config("reminders.rules.delay_units.{$rule->delay_unit}"));
    }
}
