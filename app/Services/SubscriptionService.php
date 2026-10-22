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
     * @return array<string, mixed>
     */
    public function buildTerms(Plan $plan, string $source, ?array $price = null, ?array $modules = null, ?array $limits = null): array
    {
        $solution = Plan::commercialReady() ? $plan->solution : null;

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

    /**
     * The plan of a Workspace that already has a subscription was changed by an administrator: the subscription follows it and its conditions are
     * frozen again from the NEW plan (a new agreement; the earlier price is not carried over because it belonged to the earlier plan).
     */
    public function planChanged(Workspace $workspace, Plan $plan): void
    {
        $subscription = Subscription::where('workspace_id', $workspace->id)->first();

        if (! $subscription || $subscription->plan_id === $plan->id) {
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
    public function grantTrial(Workspace $workspace, Plan $plan, CarbonInterface $endsAt, string $reason, ?int $userId): Subscription
    {
        $this->assertTrialsAvailable();

        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo no tiene suscripción ni pruebas.']);
        }

        $this->assertTrialEnd($endsAt, now());

        return DB::transaction(function () use ($workspace, $plan, $endsAt, $reason, $userId) {
            $existing = Subscription::where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($existing && in_array($existing->status, [Subscription::TRIALING, Subscription::ACTIVE, Subscription::GRACE, Subscription::SUSPENDED], true)) {
                throw ValidationException::withMessages(['trial' => $existing->status === Subscription::TRIALING ? 'Este Workspace ya tiene una prueba en curso.' : 'Este Workspace ya tiene una suscripción vigente: no se le concede una prueba.']);
            }

            if ($existing && ($existing->activates_workspace || $existing->payment_status === Subscription::PAYMENT_CONFIRMED)) {
                throw ValidationException::withMessages(['trial' => 'Este Workspace tiene una contratación en curso: no se le concede una prueba.']);
            }

            $this->catalog->assignPlan($workspace, $plan); // refuses an inactive or incomplete plan

            $subscription = $existing ?? new Subscription(['workspace_id' => $workspace->id]);
            $subscription->fill([
                'plan_id' => $plan->id, 'status' => Subscription::TRIALING, 'payment_status' => Subscription::PAYMENT_PENDING, 'payment_reference' => null,
                'trial_starts_at' => now(), 'trial_ends_at' => $endsAt, 'trial_reason' => $reason, 'trial_granted_by' => $userId, 'ends_at' => null, 'grace_ends_at' => null,
            ]);
            $subscription->save();
            $this->freezeTerms($subscription, $this->buildTerms($plan, 'trial'), 'trial');
            $this->record($subscription, $existing?->status, Subscription::TRIALING, [
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

        if ($subscription->status !== Subscription::TRIALING) {
            throw ValidationException::withMessages(['trial' => 'Este Workspace no tiene una prueba en curso.']);
        }

        $this->assertTrialEnd($endsAt, $subscription->trial_starts_at ?? now());
        $before = $subscription->trial_ends_at?->toDateString();
        $subscription->update(['trial_ends_at' => $endsAt]);
        $this->record($subscription, Subscription::TRIALING, Subscription::TRIALING, [
            AuditLogger::change('Prueba gratuita hasta', $before, $endsAt->toDateString()),
            AuditLogger::change('Motivo', null, $reason),
        ]);

        return $subscription;
    }

    /** Ends a trial on purpose (an administrator's decision): the subscription is cancelled, which withdraws access; the Workspace keeps all its data. */
    public function cancelTrial(Subscription $subscription, string $reason): Subscription
    {
        $this->assertTrialsAvailable();

        if ($subscription->status !== Subscription::TRIALING) {
            throw ValidationException::withMessages(['trial' => 'Este Workspace no tiene una prueba en curso.']);
        }

        $this->transition($subscription, Subscription::CANCELLED);
        $this->record($subscription, Subscription::CANCELLED, Subscription::CANCELLED, [AuditLogger::change('Prueba cancelada', null, $reason)]);

        return $subscription;
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

    private function assertTrialEnd(CarbonInterface $endsAt, CarbonInterface $from): void
    {
        if ($endsAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin debe ser posterior a ahora.']);
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
