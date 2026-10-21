<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
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
        Subscription::ACTIVE => [Subscription::GRACE, Subscription::SUSPENDED, Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::GRACE => [Subscription::SUSPENDED, Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::SUSPENDED => [Subscription::CANCELLED, Subscription::EXPIRED],
        Subscription::CANCELLED => [],
        Subscription::EXPIRED => [Subscription::CANCELLED],
    ];

    public function __construct(private readonly ModuleCatalog $catalog, private readonly AuditLogger $audit) {}

    /**
     * Registers a NEW customer: its Workspace is switched off until the first confirmed payment, so a contract that is not paid gives no access at all
     * (not even the default plan). The first activation switches it on again, and only because it was switched off here: a Workspace that an
     * administrator deactivated for any other reason is never reactivated by a payment. For an existing, working Workspace use `register`.
     */
    public function registerNewCustomer(Workspace $workspace, Plan $plan): Subscription
    {
        return DB::transaction(function () use ($workspace, $plan) {
            $subscription = $this->register($workspace, $plan);
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
                $subscription->update(['status' => Subscription::ACTIVE, 'activated_at' => $subscription->activated_at ?? now(), 'grace_ends_at' => null, 'activates_workspace' => false]);

                if ($switchOn) {
                    $workspace->update(['is_active' => true]);
                }

                $this->record($subscription, $previous, Subscription::ACTIVE, $switchOn ? [AuditLogger::change('Workspace', 'Inactivo', 'Activo')] : []);
            }

            return $subscription;
        });
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
