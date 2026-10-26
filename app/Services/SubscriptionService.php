<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of a Workspace's subscription and the automatic application of the plan when it becomes active.
 *
 * What it does NOT do: charge, verify or simulate a payment. There is no payment provider in Ava yet, so nothing in the application confirms a payment;
 * `confirmPayment` is the ONE integration point the future provider's verified notification (signature checked, amount and reference matched) must call.
 * Until then a subscription stays `pending` and gives no access. If a person may activate without a provider (an invoice agreed offline), that is a
 * decision to take explicitly, calling `confirmPayment` with an offline reference; it is not done here.
 *
 * Applying a plan means assigning it to the Workspace (ModuleCatalog::assignPlan): the modules are read from the plan on every request, never copied, so
 * the exceptions of the Workspace (workspace_module_overrides) are untouched, and repeating the activation changes nothing. Withdrawing access (grace
 * ended, suspension, cancellation, expiry) only changes the status: no data of the Workspace is deleted.
 */
class SubscriptionService
{
    /** from => allowed next statuses, other than activation (which has its own rules). */
    private const TRANSITIONS = [
        Subscription::PENDING => [Subscription::CANCELLED],
        Subscription::SCHEDULED => [Subscription::TRIALING, Subscription::VOIDED],
        Subscription::VOIDED => [],
        Subscription::TRIALING => [Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::ACTIVE => [Subscription::GRACE, Subscription::SUSPENDED, Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::GRACE => [Subscription::SUSPENDED, Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::SUSPENDED => [Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::CANCELLED => [],
        Subscription::EXPIRED => [Subscription::CANCELLED],
    ];

    public function __construct(private readonly ModuleCatalog $catalog, private readonly AuditLogger $audit, private readonly WorkspaceLimits $limits) {}

    /**
     * Registers a NEW customer: its Workspace is switched off until the first confirmed payment, so a contract that is not paid gives no access at all
     * (not even the default plan). The first activation switches it on again, and only because it was switched off here: a Workspace that an
     * administrator deactivated for any other reason is never reactivated by a payment. For an existing, working Workspace use `register`.
     */
    public function registerNewCustomer(Workspace $workspace, Plan $plan, ?array $terms = null): Subscription
    {
        return DB::transaction(function () use ($workspace, $plan, $terms) {
            $subscription = $this->register($workspace, $plan);
            // The conditions accepted are frozen from the start: what the customer accepted (`$terms`, from the sign-up) or, without a contract, the plan's today.
            $this->freezeTerms($subscription, $terms ?? $this->buildTerms($plan, 'manual'), $terms['source'] ?? 'manual');
            $workspace = Workspace::findOrFail($workspace->id); // the stored state, not a model that may not carry the column yet

            if ($subscription->activated_at === null && $workspace->is_active) {
                $workspace->update(['is_active' => false]);
                $subscription->update(['activates_workspace' => true]);
                $this->audit->recordForActor('updated', 'subscription', $subscription->id, $workspace->auditLabel(), [AuditLogger::change('Workspace', 'Activo', 'Inactivo hasta el primer pago confirmado')], $workspace, 'Suscripción (automático)');
            }

            return $subscription;
        });
    }

    /**
     * Registers the choice of a plan by a client Workspace: pending, unpaid, with no access. Registering again while still pending (or after it ended)
     * only updates the chosen plan; an active, grace or suspended subscription is never replaced from here.
     */
    public function register(Workspace $workspace, Plan $plan): Subscription
    {
        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo no tiene suscripción.']);
        }

        return DB::transaction(function () use ($workspace, $plan) {
            $existing = Subscription::where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($existing && ! in_array($existing->status, [Subscription::PENDING, Subscription::CANCELLED, Subscription::EXPIRED], true)) {
                throw ValidationException::withMessages(['plan_id' => 'El Workspace ya tiene una suscripción vigente.']);
            }

            $subscription = $existing ?? new Subscription(['workspace_id' => $workspace->id]);
            $subscription->fill(['plan_id' => $plan->id, 'status' => Subscription::PENDING, 'payment_status' => Subscription::PAYMENT_PENDING, 'payment_reference' => null]);
            $subscription->save();
            $this->record($subscription, $existing?->status, Subscription::PENDING);

            return $subscription;
        });
    }

    /**
     * INTEGRATION POINT for the payment provider: call it only from a notification already verified as authentic and matching this subscription.
     * Idempotent per `$reference`: a repeated notification changes nothing; a different reference on an already confirmed subscription is a renewal
     * payment and reactivates it (grace, suspended or expired). Returns the subscription after the call.
     */
    public function confirmPayment(Subscription $subscription, string $reference): Subscription
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::withMessages(['payment_reference' => 'La confirmación de pago necesita una referencia.']);
        }

        if ($subscription->status === Subscription::CANCELLED) {
            throw ValidationException::withMessages(['status' => 'Una suscripción cancelada no recibe pagos: se registra una nueva.']);
        }

        if (Subscription::where('payment_reference', $reference)->where('id', '!=', $subscription->id)->exists()) {
            throw ValidationException::withMessages(['payment_reference' => 'Esa referencia de pago ya pertenece a otra suscripción.']);
        }

        // The payment is recorded first and on its own: if applying the plan is then refused (an inactive or incomplete plan), the confirmed payment is
        // not lost and the same notification, repeated, completes the activation.
        $locked = DB::transaction(function () use ($subscription, $reference) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_reference !== $reference || $locked->payment_status !== Subscription::PAYMENT_CONFIRMED) {
                $locked->update(['payment_status' => Subscription::PAYMENT_CONFIRMED, 'payment_reference' => $reference]);
            }

            return $locked;
        });

        return $this->activate($locked); // for a repeated notification this is a no-op
    }

    /** Records that the provider reported a failed payment. It never grants access and never withdraws access already granted. */
    public function failPayment(Subscription $subscription): Subscription
    {
        if ($subscription->payment_status !== Subscription::PAYMENT_CONFIRMED) {
            $subscription->update(['payment_status' => Subscription::PAYMENT_FAILED]);
        }

        return $subscription;
    }

    /**
     * Activates a subscription whose payment is CONFIRMED and applies its plan to the Workspace. Without a confirmed payment it refuses and changes nothing.
     * Idempotent: an already active subscription with its plan applied is left exactly as it is (activation date, exceptions and plan included).
     */
    public function activate(Subscription $subscription): Subscription
    {
        if ($subscription->payment_status !== Subscription::PAYMENT_CONFIRMED) {
            throw ValidationException::withMessages(['payment_status' => 'No se puede activar una suscripción sin un pago confirmado.']);
        }

        if ($subscription->status === Subscription::CANCELLED) {
            throw ValidationException::withMessages(['status' => 'Una suscripción cancelada no se reactiva: se registra una nueva.']);
        }

        return DB::transaction(function () use ($subscription) {
            $workspace = Workspace::findOrFail($subscription->workspace_id);
            $plan = Plan::findOrFail($subscription->plan_id);
            $previous = $subscription->status;

            // assignPlan refuses an inactive or incomplete plan: the subscription then stays as it was and the reason reaches the caller.
            if ($workspace->plan_id !== $plan->id) {
                $this->catalog->assignPlan($workspace, $plan);
            }

            // Only the first activation of a subscription that switched its Workspace off reactivates it (see registerNewCustomer).
            $switchOn = $subscription->activates_workspace && $subscription->activated_at === null && ! $workspace->is_active;

            if ($previous !== Subscription::ACTIVE) {
                // A payment confirmed after the access ended (an expired trial, a lapsed subscription) reopens it: the end date of that period no longer applies.
                $subscription->update(['status' => Subscription::ACTIVE, 'activated_at' => $subscription->activated_at ?? now(), 'grace_ends_at' => null, 'ends_at' => null, 'activates_workspace' => false]);

                if ($switchOn) {
                    $workspace->update(['is_active' => true]);
                }

                $this->record($subscription, $previous, Subscription::ACTIVE, $switchOn ? [AuditLogger::change('Workspace', 'Inactivo', 'Activo')] : []);
            }

            return $subscription;
        });
    }

    // --- frozen conditions ---------------------------------------------------------------------------------------------

    /**
     * The conditions of a contract as they are on this day: plan, solution, price (only when one was really agreed, e.g. in the public sign-up),
     * modules and limits. `$modules` and `$limits` default to the plan's CURRENT ones; the sign-up passes what the customer accepted instead.
     *
     * @param  array{amount_cents: int, currency: string, period: string}|null  $price
     * @param  list<string>|null  $modules
     * @param  array<string, ?int>|null  $limits
     * @param  ?int  $solutionId  the solution the customer chose (the sign-up); null = the only solution of the plan, if it has just one
     * @return array<string, mixed>
     */
    public function buildTerms(Plan $plan, string $source, ?array $price = null, ?array $modules = null, ?array $limits = null, ?int $solutionId = null): array
    {
        // The solution the customer chose when the plan is offered under several; otherwise the only one the plan has (never guessed among several).
        $solutions = Plan::commercialReady() ? $plan->solutionList() : collect();
        $solution = $solutionId !== null ? $solutions->firstWhere('id', $solutionId) : ($solutions->count() === 1 ? $solutions->first() : null);

        return [
            'version' => 1,
            'source' => $source,
            'plan' => ['id' => $plan->id, 'name' => $plan->name],
            'solution' => $solution ? ['id' => $solution->id, 'name' => $solution->name] : null,
            'price' => $price,
            'modules' => $modules ?? $plan->modules()->where('modules.is_active', true)->orderBy('modules.key')->pluck('modules.key')->all(),
            'limits' => $limits ?? $this->limits->planLimits($plan),
        ];
    }

    /** Stores the conditions on the subscription (replacing earlier ones) and audits it. A no-op while the database lacks the columns. */
    public function freezeTerms(Subscription $subscription, array $terms, string $source): void
    {
        if (! Subscription::termsReady()) {
            return;
        }

        $before = $subscription->hasFrozenTerms() ? ($subscription->terms['plan']['name'] ?? '—') : 'Históricas (incompletas)';
        $subscription->update(['terms' => $terms, 'terms_source' => $source, 'terms_captured_at' => now()]);
        $workspace = Workspace::findOrFail($subscription->workspace_id);
        $this->audit->recordForActor('updated', 'subscription', $subscription->id, $workspace->auditLabel(), [
            AuditLogger::change('Condiciones contratadas', $before, "Congeladas ({$terms['plan']['name']})"),
        ], $workspace, 'Suscripción (automático)');
    }

    /**
     * An administrator freezes, EXPLICITLY, the conditions the plan has today for a subscription whose own were never recorded (a historical one). It is
     * a decision of a person (audited), never done silently, and it carries no price: none can be recovered for a historical contract.
     */
    public function freezeCurrentTerms(Subscription $subscription): Subscription
    {
        if (! Subscription::termsReady()) {
            throw ValidationException::withMessages(['terms' => 'Las condiciones congeladas todavía no están disponibles: falta aplicar la migración pendiente.']);
        }

        if ($subscription->hasFrozenTerms()) {
            throw ValidationException::withMessages(['terms' => 'Esta suscripción ya tiene sus condiciones congeladas.']);
        }

        $this->freezeTerms($subscription, $this->buildTerms(Plan::findOrFail($subscription->plan_id), 'frozen_current'), 'frozen_current');

        return $subscription->fresh();
    }

    /** Where an agreed price comes from (D-CON1). The reason (free text) always accompanies it. */
    public const AGREEMENT_ORIGINS = ['contract' => 'Contrato firmado', 'proposal' => 'Propuesta aceptada', 'communication' => 'Correo o comunicación del cliente', 'other' => 'Otro'];

    /**
     * An administrator records, EXPLICITLY, the price agreed with a customer outside the public sign-up. It reuses the frozen conditions of the subscription (no
     * parallel system): only `price` is replaced, modules and limits stay as they were, and the new agreement is appended to `terms.agreements` next to the
     * one it replaces, so the history is never lost. A subscription whose conditions were never recorded (historical) must be frozen first, on purpose; no price
     * is ever invented for it. Audited with the author, origin and reason.
     *
     * @param  array{amount_cents: int, currency: string, period: string}  $price
     */
    public function recordAgreement(Subscription $subscription, array $price, string $origin, string $reason, ?int $userId): Subscription
    {
        if (! Subscription::termsReady()) {
            throw ValidationException::withMessages(['agreement' => 'Las condiciones congeladas todavía no están disponibles: falta aplicar la migración pendiente.']);
        }

        if (! isset(self::AGREEMENT_ORIGINS[$origin]) || ! in_array($price['period'], ['monthly', 'yearly'], true) || $price['amount_cents'] < 1 || ! preg_match('/^[A-Z]{3}$/', $price['currency'])) {
            throw ValidationException::withMessages(['agreement' => 'El acuerdo comercial no es válido.']);
        }

        $workspace = Workspace::findOrFail($subscription->workspace_id);

        if ($workspace->isAdministrative() || in_array($subscription->status, [Subscription::CANCELLED, Subscription::EXPIRED], true)) {
            throw ValidationException::withMessages(['agreement' => 'No se puede registrar un acuerdo en este Workspace o en una suscripción terminada.']);
        }

        return DB::transaction(function () use ($subscription, $price, $origin, $reason, $userId) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if (! $locked->hasFrozenTerms()) {
                throw ValidationException::withMessages(['agreement' => 'Esta suscripción es histórica: congela primero sus condiciones actuales (decisión explícita) y después registra el acuerdo.']);
            }

            $terms = $locked->terms;
            $previous = $terms['price'] ?? null;
            $agreement = ['price' => $price, 'replaces' => $previous, 'origin' => $origin, 'reason' => $reason, 'agreed_by' => $userId, 'agreed_at' => now()->toIso8601String()];
            $terms['price'] = $price;
            $terms['agreements'] = [...($terms['agreements'] ?? []), $agreement];
            $locked->update(['terms' => $terms, 'terms_source' => 'agreement', 'terms_captured_at' => now()]);

            $describe = fn (?array $p) => $p ? sprintf('%s %s %s', number_format($p['amount_cents'] / 100, 2, '.', ''), $p['currency'], $p['period'] === 'yearly' ? 'al año' : 'al mes') : 'Sin precio acordado';
            $workspace = Workspace::findOrFail($locked->workspace_id);
            $this->audit->recordForActor('updated', 'subscription', $locked->id, $workspace->auditLabel(), [
                AuditLogger::change('Precio acordado', $describe($previous), $describe($price)),
                AuditLogger::change('Origen del acuerdo', null, self::AGREEMENT_ORIGINS[$origin]),
                AuditLogger::change('Motivo del acuerdo', null, $reason),
            ], $workspace, 'Suscripción (automático)');

            return $locked;
        });
    }

    /**
     * The plan of a Workspace that already has a subscription was changed by an administrator: the subscription follows it and its conditions are
     * frozen again from the NEW plan (a new agreement; the earlier price is not carried over because it belonged to the earlier plan).
     */
    public function planChanged(Workspace $workspace, Plan $plan): void
    {
        $subscription = Subscription::where('workspace_id', $workspace->id)->first();

        // A scheduled trial keeps its own plan and conditions until it starts; changing the Workspace's plan does not rewrite it.
        if (! $subscription || $subscription->plan_id === $plan->id || $subscription->status === Subscription::SCHEDULED) {
            return;
        }

        $subscription->update(['plan_id' => $plan->id]);
        $this->freezeTerms($subscription, $this->buildTerms($plan, $subscription->status === Subscription::TRIALING ? 'trial' : 'manual'), $subscription->status === Subscription::TRIALING ? 'trial' : 'manual');
    }

    // --- free trial, granted by hand to ONE Workspace ------------------------------------------------------------------

    /** Technical guard (not a commercial rule): a trial longer than this is almost surely a typo in the end date. */
    private const TRIAL_MAX_DAYS = 400;

    /**
     * Grants a free trial to one Workspace: its plan is assigned (and must be assignable), the subscription becomes `trialing` from now until `$endsAt`,
     * and the conditions are frozen from that plan with NO price. Refused for the administrative Workspace, for one that already has a live or paying
     * subscription, and for a duplicate. Nothing global changes and no other Workspace is touched.
     */
    public function grantTrial(Workspace $workspace, Plan $plan, CarbonInterface $endsAt, string $reason, ?int $userId, ?CarbonInterface $startsAt = null): Subscription
    {
        $this->assertTrialsAvailable();

        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo no tiene suscripción ni pruebas.']);
        }

        // A start in the future SCHEDULES the trial: it grants nothing and takes nothing away until that moment. No start (or one that already arrived) = now.
        $scheduled = $startsAt !== null && $startsAt->greaterThan(now());
        $this->assertTrialEnd($endsAt, $scheduled ? $startsAt : now(), $scheduled ? $startsAt : null);

        return DB::transaction(function () use ($workspace, $plan, $endsAt, $reason, $userId, $scheduled, $startsAt) {
            $existing = Subscription::where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($existing && in_array($existing->status, [Subscription::TRIALING, Subscription::SCHEDULED, Subscription::ACTIVE, Subscription::GRACE, Subscription::SUSPENDED], true)) {
                throw ValidationException::withMessages(['trial' => match ($existing->status) {
                    Subscription::TRIALING => 'Este Workspace ya tiene una prueba en curso.',
                    Subscription::SCHEDULED => 'Este Workspace ya tiene una prueba programada.',
                    default => 'Este Workspace ya tiene una suscripción vigente: no se le concede una prueba.',
                }]);
            }

            if ($existing && ($existing->activates_workspace || $existing->payment_status === Subscription::PAYMENT_CONFIRMED)) {
                throw ValidationException::withMessages(['trial' => 'Este Workspace tiene una contratación en curso: no se le concede una prueba.']);
            }

            // Refuses an inactive or incomplete plan and a plan of ANOTHER solution than the one the Workspace already has. A scheduled trial leaves the plan
            // of the Workspace as it is until it starts (its access does not change meanwhile); an immediate one assigns it now.
            $scheduled ? $this->catalog->assertAssignable($workspace, $plan) : $this->catalog->assignPlan($workspace, $plan);

            $subscription = $existing ?? new Subscription(['workspace_id' => $workspace->id]);
            $subscription->fill([
                'plan_id' => $plan->id, 'status' => $scheduled ? Subscription::SCHEDULED : Subscription::TRIALING, 'payment_status' => Subscription::PAYMENT_PENDING, 'payment_reference' => null,
                'trial_starts_at' => $scheduled ? $startsAt : now(), 'trial_ends_at' => $endsAt, 'trial_reason' => $reason, 'trial_granted_by' => $userId, 'ends_at' => null, 'grace_ends_at' => null,
            ]);
            $subscription->save();
            $this->freezeTerms($subscription, $this->buildTerms($plan, 'trial'), 'trial');
            $this->record($subscription, $existing?->status, $scheduled ? Subscription::SCHEDULED : Subscription::TRIALING, [
                ...($scheduled ? [AuditLogger::change('Prueba gratuita desde', null, $startsAt->toDateString())] : []),
                AuditLogger::change('Prueba gratuita hasta', null, $endsAt->toDateString()),
                AuditLogger::change('Motivo', null, $reason),
            ]);

            return $subscription;
        });
    }

    /** Changes the end date of a trial in course (longer or shorter, but still in the future), with a reason. Audited. */
    public function updateTrial(Subscription $subscription, CarbonInterface $endsAt, string $reason): Subscription
    {
        $this->assertTrialsAvailable();

        if (! in_array($subscription->status, [Subscription::TRIALING, Subscription::SCHEDULED], true)) {
            throw ValidationException::withMessages(['trial' => 'Este Workspace no tiene una prueba en curso ni programada.']);
        }

        $this->assertTrialEnd($endsAt, $subscription->trial_starts_at ?? now(), $subscription->status === Subscription::SCHEDULED ? $subscription->trial_starts_at : null);
        $before = $subscription->trial_ends_at?->toDateString();
        $subscription->update(['trial_ends_at' => $endsAt]);
        $this->record($subscription, $subscription->status, $subscription->status, [
            AuditLogger::change('Prueba gratuita hasta', $before, $endsAt->toDateString()),
            AuditLogger::change('Motivo', null, $reason),
        ]);

        return $subscription;
    }

    /** Ends a trial on purpose (an administrator's decision): the subscription is cancelled, which withdraws access; the Workspace keeps all its data. */
    public function cancelTrial(Subscription $subscription, string $reason): Subscription
    {
        $this->assertTrialsAvailable();

        if (! in_array($subscription->status, [Subscription::TRIALING, Subscription::SCHEDULED], true)) {
            throw ValidationException::withMessages(['trial' => 'Este Workspace no tiene una prueba en curso ni programada.']);
        }

        // A trial that never started is voided (the Workspace is left exactly as it was); one in course is cancelled, which withdraws the access.
        $to = $subscription->status === Subscription::SCHEDULED ? Subscription::VOIDED : Subscription::CANCELLED;
        $this->transition($subscription, $to);
        $this->record($subscription, $to, $to, [AuditLogger::change('Prueba cancelada', null, $reason)]);

        return $subscription;
    }

    /**
     * Starts every scheduled trial whose start date arrived. Idempotent (a row already started is not touched) and safe to run at any time: access never
     * waits for it, because `ModuleAccess::subscription` starts a due trial on the first request. Returns how many it started.
     */
    public function startDueTrials(): int
    {
        if (! Subscription::termsReady()) {
            return 0;
        }

        $started = 0;

        Subscription::where('status', Subscription::SCHEDULED)->where('trial_starts_at', '<=', now())->orderBy('trial_starts_at')->pluck('id')->each(function (int $id) use (&$started) {
            if ($this->startScheduledTrial(Subscription::findOrFail($id))->status !== Subscription::SCHEDULED) {
                $started++;
            }
        });

        return $started;
    }

    /**
     * Starts ONE scheduled trial that is due: the plan of the trial is assigned to the Workspace, the subscription becomes `trialing` and the start is audited.
     * Locked and re-checked, so two requests (or a request and the scheduler) start it once. A trial that is not due, or already started, is returned as it is.
     */
    public function startScheduledTrial(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->trialDue()) {
                return $locked ?? $subscription;
            }

            $workspace = Workspace::findOrFail($locked->workspace_id);
            $plan = Plan::findOrFail($locked->plan_id);

            try {
                $this->catalog->assignPlan($workspace, $plan);
            } catch (ValidationException) {
                // The plan became unassignable (deactivated or changed to another solution) after it was scheduled: it is not started and the change is audited.
                $locked->update(['status' => Subscription::VOIDED]);
                $this->record($locked, Subscription::SCHEDULED, Subscription::VOIDED, [AuditLogger::change('Prueba programada cancelada', null, 'El plan ya no se puede asignar')]);

                return $locked;
            }

            $this->transition($locked, Subscription::TRIALING);

            return $locked;
        });
    }

    /**
     * Moves the trials whose end date passed to `expired` (access restricted: no paid modules and no new AI executions; the Workspace, its users, clients,
     * conversations and files are untouched, and a confirmed payment reopens it). Idempotent: a row already expired is not touched again, so a repeated
     * run changes and audits nothing. Never grants, renews or extends anything. Returns how many it moved.
     */
    public function expireTrials(): int
    {
        if (! Subscription::termsReady()) {
            return 0;
        }

        $moved = 0;

        Subscription::where('status', Subscription::TRIALING)->where('trial_ends_at', '<', now())->orderBy('trial_ends_at')->pluck('id')->each(function (int $id) use (&$moved) {
            DB::transaction(function () use ($id, &$moved) {
                $locked = Subscription::whereKey($id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== Subscription::TRIALING || ! $locked->trial_ends_at?->isPast()) {
                    return; // another run (or a person) got there first
                }

                $this->transition($locked, Subscription::EXPIRED);
                $locked->update(['ends_at' => $locked->trial_ends_at]);
                $moved++;
            });
        });

        return $moved;
    }

    private function assertTrialsAvailable(): void
    {
        if (! Subscription::termsReady()) {
            throw ValidationException::withMessages(['trial' => 'Las pruebas gratuitas todavía no están disponibles: falta aplicar la migración pendiente.']);
        }
    }

    private function assertTrialEnd(CarbonInterface $endsAt, CarbonInterface $from, ?CarbonInterface $startsAt = null): void
    {
        if ($endsAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin debe ser posterior a ahora.']);
        }

        if ($startsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin debe ser posterior a la de inicio.']);
        }

        if ($startsAt !== null && $startsAt->greaterThan(now()->addDays(self::TRIAL_MAX_DAYS))) {
            throw ValidationException::withMessages(['starts_at' => 'El inicio no puede estar a más de '.self::TRIAL_MAX_DAYS.' días: revisa la fecha.']);
        }

        if ($endsAt->greaterThan($from->copy()->addDays(self::TRIAL_MAX_DAYS))) {
            throw ValidationException::withMessages(['ends_at' => 'La prueba no puede durar más de '.self::TRIAL_MAX_DAYS.' días: revisa la fecha.']);
        }
    }

    /** Moves an existing subscription to grace, suspended, cancelled or expired. Data is never touched; only what the status allows changes. */
    public function transition(Subscription $subscription, string $to): Subscription
    {
        if ($subscription->status === $to) {
            return $subscription; // repeated order: nothing to do
        }

        if (! in_array($to, self::TRANSITIONS[$subscription->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Una suscripción «{$subscription->status}» no puede pasar a «{$to}»."]);
        }

        $from = $subscription->status;
        $subscription->update([
            'status' => $to,
            'ends_at' => in_array($to, [Subscription::CANCELLED, Subscription::EXPIRED], true) ? ($subscription->ends_at ?? now()) : $subscription->ends_at,
        ]);
        $this->record($subscription, $from, $to);

        return $subscription;
    }

    /** @param  list<array{field: string, before: ?string, after: ?string}>  $extra */
    private function record(Subscription $subscription, ?string $from, string $to, array $extra = []): void
    {
        $workspace = Workspace::findOrFail($subscription->workspace_id);
        $changes = [AuditLogger::change('Estado de la suscripción', $from, $to), ...$extra];

        $this->audit->recordForActor($from === null ? 'created' : 'updated', 'subscription', $subscription->id, $workspace->auditLabel(), $changes, $workspace, 'Suscripción (automático)');
    }
}
