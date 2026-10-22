<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceLimitOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Usage limits of a Workspace: the STANDARD limit its plan sells and the PARTICULAR one CCG may authorize for that Workspace alone.
 *
 * Precedence (one rule): a particular limit of the Workspace (even "unlimited") > the limit FROZEN in its subscription (the conditions accepted when it
 * contracted) > the standard limit of its plan, read live, only for a subscription whose conditions were never recorded (historical) or a Workspace without
 * one > none. "None" means no limit is set, so nothing is enforced; an absent value is NEVER read as a number. Editing a plan therefore changes only new
 * contracts and the historical ones, never a frozen subscription or a Workspace with a particular limit. The administrative Workspace is never limited.
 *
 * Only metrics that the backend really enforces are configurable (`METRICS`): `users` = members of the Workspace, enforced where a member is added
 * (UserController, WorkspaceMemberController; an existing excess is not removed, new members are refused), and `ai_executions` = runs of the assistant's
 * workflow in the calendar month, enforced in ChatbotExecutions::schedule. A limit grants nothing: modules and permissions are separate controls.
 */
class WorkspaceLimits
{
    /** @var array<string, array{label: string, unit: string, help: string}> metrics that are enforced and may carry a limit */
    public const METRICS = [
        'users' => ['label' => 'Usuarios', 'unit' => 'usuarios', 'help' => 'Cuentas que pueden ser miembros del Workspace a la vez. Al llegar al máximo no se pueden añadir más; los miembros existentes no se tocan.'],
        'ai_executions' => ['label' => 'Ejecuciones de IA', 'unit' => 'ejecuciones al mes', 'help' => 'Respuestas automáticas del asistente por mes calendario (una por mensaje entrante atendido). Al llegar al máximo el asistente deja de responder: la conversación pasa a una persona hasta el mes siguiente o hasta que se amplíe el límite. El historial y el resto del panel siguen funcionando.'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Whether the tables exist (the migration may not be applied yet): without them nothing is limited and nothing breaks. */
    public function available(): bool
    {
        return Schema::hasTable('plan_limits') && Schema::hasTable('workspace_limit_overrides');
    }

    /** @return array{value: ?int, source: string, standard: ?int, override: ?array{value: ?int, reason: string, author: ?string, at: ?string}} source: workspace | plan | none | administrative */
    public function effective(Workspace $workspace, string $metric): array
    {
        $none = ['value' => null, 'source' => 'none', 'standard' => null, 'planNow' => null, 'frozen' => false, 'override' => null];

        if ($workspace->isAdministrative()) {
            return ['source' => 'administrative'] + $none;
        }

        if (! $this->available() || ! isset(self::METRICS[$metric])) {
            return $none;
        }

        $plan = $workspace->plan_id ? $workspace->plan : app(ModuleAccess::class)->defaultPlan();
        $planNow = $plan ? PlanLimit::where('plan_id', $plan->id)->where('metric', $metric)->value('value') : null;
        $subscription = app(ModuleAccess::class)->subscription($workspace); // `terms` is null on a database without the column
        $frozen = $subscription?->hasFrozenTerms() ?? false;
        // What the contract says when its conditions are on file; otherwise (historical or no subscription) the plan's own, read live.
        $standard = $frozen ? ($subscription->terms['limits'][$metric] ?? null) : $planNow;
        $override = WorkspaceLimitOverride::with('author:id,name')->where('workspace_id', $workspace->id)->where('metric', $metric)->first();

        if ($override) {
            return [
                'value' => $override->value, 'source' => 'workspace', 'standard' => $standard, 'planNow' => $planNow, 'frozen' => $frozen,
                'override' => ['value' => $override->value, 'reason' => $override->reason, 'author' => $override->author?->name, 'at' => $override->updated_at?->toIso8601String()],
            ];
        }

        return [
            'value' => $standard, 'source' => $standard === null ? 'none' : ($frozen ? 'contract' : 'plan'), 'standard' => $standard, 'planNow' => $planNow, 'frozen' => $frozen, 'override' => null,
        ];
    }

    public function usage(Workspace $workspace, string $metric): int
    {
        return match ($metric) {
            'users' => DB::table('workspace_user')->where('workspace_id', $workspace->id)->count(),
            'ai_executions' => $this->aiExecutionsInPeriod($workspace),
            default => 0,
        };
    }

    /**
     * The consumption period of AI executions: the CALENDAR MONTH in the application's time zone (`config('app.timezone')`), the same one the dashboard
     * counts by. It is independent of the billing cycle, which does not exist yet. The counter restarts logically on the 1st; nothing is deleted.
     *
     * @return array{start: CarbonImmutable, renews: CarbonImmutable}
     */
    public function period(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return ['start' => $start, 'renews' => $start->addMonth()];
    }

    /** Executions of the assistant's workflow started this period: one row of `chatbot_executions` per inbound message attended (a discarded one never ran). */
    public function aiExecutionsInPeriod(Workspace $workspace): int
    {
        return Schema::hasTable('chatbot_executions')
            ? DB::table('chatbot_executions')->where('workspace_id', $workspace->id)->where('created_at', '>=', $this->period()['start'])->where('status', '!=', 'discarded')->count()
            : 0;
    }

    /**
     * The AI execution limit when it is ALREADY reached (so a new execution must not start), else null. Call it inside the transaction that creates the
     * execution, after locking the Workspace row, so two simultaneous messages can not both take the last slot.
     */
    public function aiLimitReached(Workspace $workspace): ?int
    {
        $limit = $this->effective($workspace, 'ai_executions')['value'];

        return $limit !== null && $this->aiExecutionsInPeriod($workspace) >= $limit ? $limit : null;
    }

    /** Refuses to add members past the limit. Called BEFORE anything is created, from every path that adds a member; `$adding` is how many would join. */
    public function assertCanAddMembers(Workspace $workspace, int $adding = 1, string $field = 'email'): void
    {
        $limit = $this->effective($workspace, 'users')['value'];

        if ($limit === null || $this->usage($workspace, 'users') + $adding <= $limit) {
            return;
        }

        $this->audit->record('created', 'membership', null, $workspace->name, [AuditLogger::change('Límite de usuarios', null, "Rechazado: el máximo es {$limit}")], $workspace, AuditLogger::FAILED);

        throw ValidationException::withMessages([$field => "Este Workspace alcanzó el máximo de {$limit} usuario(s) que permite su suscripción. Contacta con CCG para ampliarlo."]);
    }

    // --- standard limits of a plan -------------------------------------------------------------------------------

    /** @return array<string, ?int> metric => standard value (null = none) */
    public function planLimits(Plan $plan): array
    {
        $rows = $this->available() ? PlanLimit::where('plan_id', $plan->id)->pluck('value', 'metric') : collect();

        return collect(array_keys(self::METRICS))->mapWithKeys(fn (string $metric) => [$metric => $rows[$metric] ?? null])->all();
    }

    /** @param  array<string, ?int>  $limits  metric => value; null removes the plan's limit for that metric, an absent metric is left as it is */
    public function setPlanLimits(Plan $plan, array $limits): void
    {
        $before = $this->planLimits($plan);
        $changes = [];

        DB::transaction(function () use ($plan, $limits, $before, &$changes) {
            foreach (self::METRICS as $metric => $meta) {
                if (! array_key_exists($metric, $limits) || ($limits[$metric] ?? null) === $before[$metric]) {
                    continue;
                }

                $value = $limits[$metric];
                $value === null ? PlanLimit::where('plan_id', $plan->id)->where('metric', $metric)->delete() : PlanLimit::updateOrCreate(['plan_id' => $plan->id, 'metric' => $metric], ['value' => $value]);
                $changes[] = AuditLogger::change('Límite: '.$meta['label'], $before[$metric] === null ? null : (string) $before[$metric], $value === null ? null : (string) $value);
            }
        });

        if ($changes !== []) {
            $this->audit->record('updated', 'plan', $plan->id, $plan->name, $changes);
        }
    }

    // --- particular limit of ONE Workspace --------------------------------------------------------------------------

    /** Sets the particular limit of a Workspace (`$value` null = unlimited for it). A reason is required and the author is kept. */
    public function setOverride(Workspace $workspace, string $metric, ?int $value, string $reason, ?int $userId): void
    {
        $this->guard($workspace, $metric);

        if ($value !== null && $value < 1) {
            throw ValidationException::withMessages(['value' => 'El límite debe ser al menos 1; para no limitar elige «Sin límite».']);
        }

        $before = WorkspaceLimitOverride::where('workspace_id', $workspace->id)->where('metric', $metric)->first();
        WorkspaceLimitOverride::updateOrCreate(['workspace_id' => $workspace->id, 'metric' => $metric], ['value' => $value, 'reason' => $reason, 'created_by' => $userId]);

        $this->audit->record('updated', 'workspace_limits', $workspace->id, $workspace->auditLabel(), [
            AuditLogger::change('Límite particular: '.self::METRICS[$metric]['label'], $before ? $this->text($before->value) : 'Estándar del plan', $this->text($value)),
            AuditLogger::change('Motivo', null, $reason),
        ], $workspace);
    }

    /** Removes the particular limit: the Workspace inherits its plan's standard again. */
    public function clearOverride(Workspace $workspace, string $metric): void
    {
        $this->guard($workspace, $metric);
        $before = WorkspaceLimitOverride::where('workspace_id', $workspace->id)->where('metric', $metric)->first();

        if (! $before) {
            return;
        }

        $before->delete();
        $this->audit->record('updated', 'workspace_limits', $workspace->id, $workspace->auditLabel(), [
            AuditLogger::change('Límite particular: '.self::METRICS[$metric]['label'], $this->text($before->value), 'Estándar del plan'),
        ], $workspace);
    }

    private function guard(Workspace $workspace, string $metric): void
    {
        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo no tiene límites comerciales.']);
        }

        if (! isset(self::METRICS[$metric])) {
            throw ValidationException::withMessages(['metric' => 'Ese límite no existe o todavía no se aplica.']);
        }

        if (! $this->available()) {
            throw ValidationException::withMessages(['value' => 'Los límites todavía no están disponibles: falta aplicar la migración pendiente.']);
        }
    }

    private function text(?int $value): string
    {
        return $value === null ? 'Sin límite' : (string) $value;
    }
}
