<?php

namespace App\Services;

use App\Legal\LegalDocuments;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SignupRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\PaymentConfirmation;
use App\Payments\PaymentGateway;
use App\Support\Nit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The public sign-up of a new customer, from the wizard to an operative Workspace.
 *
 * Nothing definitive exists until a payment is VERIFIED: navigating the steps only edits a `SignupRequest` (the data typed and a snapshot of the price
 * the customer saw). The Organization, the Workspace, the administrator's account, the subscription and the modules are created together by
 * `confirmPayment`, the ONE entry point for the payment provider's verified notification, which checks the reference, the amount and the currency
 * against the snapshot. It is idempotent (a repeated notification changes nothing and creates nothing twice) and all-or-nothing: if provisioning
 * fails after the payment was recorded, the payment stays recorded, an alert is raised and the same notification, repeated, completes it. The plan,
 * its modules and the Workspace's isolation then follow SubscriptionService and ModuleAccess like any other customer.
 */
class SignupService
{
    private const RESERVED_CODES = ['ADMIN', 'API', 'LOGIN', 'REGISTRO', 'SOPORTE', 'WWW', 'AVA', 'CCG'];

    public function __construct(
        private readonly SignupCatalog $catalog,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentGateway $gateway,
        private readonly LegalDocuments $legal,
        private readonly OperationalAlert $alerts,
        private readonly WorkspaceLimits $limits,
    ) {}

    /** The open, unexpired request with this public id, or null. A draft that expired is closed. */
    public function open(?string $publicId): ?SignupRequest
    {
        if (! $publicId) {
            return null;
        }

        $request = SignupRequest::where('public_id', $publicId)->first();

        if ($request && in_array($request->status, [SignupRequest::DRAFT], true) && $request->expires_at->isPast()) {
            $request->update(['status' => SignupRequest::EXPIRED]);

            return null;
        }

        return $request && in_array($request->status, [...SignupRequest::OPEN, SignupRequest::COMPLETED], true) ? $request : null;
    }

    // --- step: company and Workspace ---------------------------------------------------------------------------------

    public function workspaceCodeAvailable(string $code, ?SignupRequest $except = null): bool
    {
        $code = Workspace::normalizeCode($code);

        if ($code === Workspace::normalizeCode((string) config('workspace.admin_code')) || in_array($code, self::RESERVED_CODES, true)) {
            return false;
        }

        return ! Workspace::where('code', $code)->exists()
            && ! SignupRequest::where('workspace_code', $code)->whereIn('status', SignupRequest::OPEN)->where('expires_at', '>', now())
                ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->exists();
    }

    /**
     * @param  array{admin_name: string, password: string, company_name: string, tax_id: string, phone: string, workspace_code: string}  $data
     */
    public function saveCompany(SignupRequest $request, array $data): SignupRequest
    {
        $this->assertEditable($request);
        $nit = Nit::normalize($data['tax_id']);
        $code = Workspace::normalizeCode($data['workspace_code']);

        if ($nit === null) {
            throw ValidationException::withMessages(['tax_id' => 'Ingresa un NIT válido con su dígito de verificación, por ejemplo 900123456-7.']);
        }

        if (! $this->workspaceCodeAvailable($code, $request)) {
            throw ValidationException::withMessages(['workspace_code' => 'Ese nombre de Workspace no está disponible. Prueba con otro.']);
        }

        // One company, one registration: a NIT that already belongs to an Organization or to another open sign-up is not registered again from here.
        // The answer does not say which, nor whose it is.
        $taken = Organization::where('tax_id', $nit)->exists()
            || SignupRequest::where('tax_id', $nit)->where('id', '!=', $request->id)->whereIn('status', SignupRequest::OPEN)->where('expires_at', '>', now())->exists();

        if ($taken) {
            throw ValidationException::withMessages(['tax_id' => 'No pudimos registrar estos datos. Si tu empresa ya tiene cuenta, inicia sesión; si no, contacta con soporte.']);
        }

        $request->fill([
            'admin_name' => trim($data['admin_name']), 'company_name' => trim($data['company_name']), 'tax_id' => $nit,
            'phone' => trim($data['phone']), 'workspace_code' => $code, 'workspace_name' => trim($data['company_name']),
        ]);

        if (filled($data['password'] ?? null)) {
            $request->password = $data['password'];
        }

        $this->reopen($request);
        $request->save();

        return $request;
    }

    // --- steps: solution and plan ---------------------------------------------------------------------------------------

    public function chooseSolution(SignupRequest $request, string $key): SignupRequest
    {
        $this->assertEditable($request);

        if ($this->catalog->solution($key) === null) {
            throw ValidationException::withMessages(['solution' => 'Esa solución no está disponible.']);
        }

        if ($request->solution_key !== $key) {
            $request->fill(['plan_id' => null, 'price_cents' => null, 'currency' => null, 'billing_period' => null]);
        }

        $request->solution_key = $key;
        $this->reopen($request);
        $request->save();

        return $request;
    }

    public function choosePlan(SignupRequest $request, string $solutionKey, int $planId, ?string $period = null): SignupRequest
    {
        $this->assertEditable($request);
        $plan = $this->catalog->offeredPlan($solutionKey, $planId);

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'Ese plan no está disponible.']);
        }

        // The price is read from the plan HERE, on the server, for the period the customer chose; nothing the browser sends is a price. Only a plan sold
        // online carries a price to charge (a quote has none, whatever is stored on the plan), and only a period that has its own price can be chosen.
        $period = $plan->isPurchasable() ? $this->periodFor($plan, $period) : null;
        $request->fill([
            'solution_key' => $solutionKey,
            'plan_id' => $plan->id,
            'price_cents' => $period ? $plan->priceFor($period) : null,
            'currency' => $period ? $plan->currency : null,
            'billing_period' => $period,
        ]);
        $this->reopen($request);
        $request->save();

        return $request;
    }

    /** The chosen period when the plan sells it; the only one when the plan sells just one; an error when it is ambiguous or not sold. */
    private function periodFor(Plan $plan, ?string $period): string
    {
        $available = $plan->availablePeriods();

        if ($period === null || $period === '') {
            if (count($available) === 1) {
                return $available[0];
            }

            throw ValidationException::withMessages(['period' => 'Elige si quieres pagar mensual o anualmente.']);
        }

        if (! in_array($period, $available, true)) {
            throw ValidationException::withMessages(['period' => 'Esa periodicidad no está disponible para este plan.']);
        }

        return $period;
    }

    // --- step: summary -> payment or quote ----------------------------------------------------------------------

    /** The required legal documents (key => current version) that must be accepted to contract. */
    public function requiredDocuments(): array
    {
        return collect($this->legal->all())->filter(fn (array $document) => $document['required'])->map(fn (array $document) => $document['version'])->all();
    }

    /**
     * Confirms the contract and, when there is a payment provider, gives the checkout to go to. With no provider the request stays awaiting payment and the
     * caller says so: no payment is made, none is simulated and nothing is activated.
     *
     * @param  list<string>  $accepted  keys of the documents the customer ticked
     * @return array{checkoutUrl: ?string, paymentAvailable: bool}
     */
    public function submit(SignupRequest $request, array $accepted): array
    {
        $this->assertEditable($request);
        $plan = $request->plan_id ? $this->catalog->offeredPlan((string) $request->solution_key, $request->plan_id) : null;

        if (! $request->admin_name || ! $request->company_name || ! $request->workspace_code || ! $request->password || ! $plan) {
            throw ValidationException::withMessages(['plan' => 'Completa los pasos anteriores antes de contratar.']);
        }

        if ($plan->requires_quote) {
            throw ValidationException::withMessages(['plan' => 'Este plan se contrata con una cotización.']);
        }

        if (! $plan->isPurchasable()) {
            throw ValidationException::withMessages(['plan' => 'Este plan todavía no tiene un precio disponible para contratar en línea.']);
        }

        // The price the customer saw is the one that is charged: if the plan changed in between, stop and show the new summary.
        // The period chosen stays the one chosen; if the plan no longer sells it, the price is cleared and a new choice is asked for.
        $current = $plan->priceFor($request->billing_period);

        if ($current === null || $request->price_cents !== $current || $request->currency !== $plan->currency) {
            $request->fill(['price_cents' => $current, 'currency' => $current === null ? null : $plan->currency, 'billing_period' => $current === null ? null : $request->billing_period]);
            // The acceptance and the checkout were for the old price: back to a draft, so a payment can not be taken against a summary nobody accepted.
            $this->reopen($request);
            $request->save();

            throw ValidationException::withMessages(['plan' => 'El precio del plan cambió. Revisa el resumen antes de continuar.']);
        }

        $required = $this->requiredDocuments();

        if (array_diff(array_keys($required), $accepted) !== []) {
            throw ValidationException::withMessages(['terms' => 'Debes aceptar los términos y el aviso de privacidad para continuar.']);
        }

        $request->fill([
            'status' => SignupRequest::AWAITING_PAYMENT, 'accepted_documents' => $required, 'accepted_at' => now(),
            'modules_snapshot' => $plan->modules()->where('modules.is_active', true)->orderBy('modules.key')->pluck('modules.key')->all(),
            'expires_at' => now()->addDays(7), // an unpaid contract waits for its payment longer than a draft
        ] + (Schema::hasColumn('signup_requests', 'limits_snapshot') ? ['limits_snapshot' => $this->limits->planLimits($plan)] : []))->save();

        $available = $this->gateway->isConfigured();

        return ['checkoutUrl' => $available ? $this->gateway->checkoutUrl($request) : null, 'paymentAvailable' => $available];
    }

    /** A plan sold by quote: the request is recorded and the team is alerted. It buys nothing and creates nothing. */
    public function requestQuote(SignupRequest $request): SignupRequest
    {
        $this->assertEditable($request);
        $plan = $request->plan_id ? $this->catalog->offeredPlan((string) $request->solution_key, $request->plan_id) : null;

        if (! $plan || ! $plan->requires_quote) {
            throw ValidationException::withMessages(['plan' => 'Este plan no se contrata con una cotización.']);
        }

        if (! $request->company_name || ! $request->admin_name) {
            throw ValidationException::withMessages(['plan' => 'Completa los datos de tu empresa antes de solicitar la cotización.']);
        }

        // Asking again for a quote already requested only renews it: the team is alerted once, not on every repeated request.
        $first = $request->status !== SignupRequest::QUOTE_REQUESTED;
        $request->fill(['status' => SignupRequest::QUOTE_REQUESTED, 'expires_at' => now()->addDays(30)])->save();

        if ($first) {
            $this->alerts->raise('signup_quote_requested', ['signup' => $request->id, 'plan' => $plan->id]);
        }

        return $request;
    }

    public function cancel(SignupRequest $request): void
    {
        // A recorded payment is never cancelled from the browser: it stays in the reconciliation list until it is provisioned or a person decides.
        if ($request->paid_at === null && in_array($request->status, [SignupRequest::DRAFT, SignupRequest::AWAITING_PAYMENT, SignupRequest::QUOTE_REQUESTED], true)) {
            $request->update(['status' => SignupRequest::CANCELLED]);
        }
    }

    // --- the verified payment -> the Workspace ------------------------------------------------------------------

    /**
     * ENTRY POINT of the payment provider: call it only from a notification already verified as authentic (signature) by the provider's integration.
     * Compares reference, amount and currency with what the customer was shown and, only then, provisions everything. Idempotent.
     */
    public function confirmPayment(PaymentConfirmation $payment): SignupRequest
    {
        $request = SignupRequest::where('public_id', $payment->reference)->first();

        if (! $request) {
            $this->alerts->raise('signup_payment_unknown_reference', ['transaction' => $payment->transactionId]);

            throw ValidationException::withMessages(['reference' => 'La referencia del pago no corresponde a ninguna contratación.']);
        }

        if ($request->status === SignupRequest::COMPLETED) {
            $this->flagDuplicatePayment($request, $payment);

            return $request; // a repeated notification: everything already exists
        }

        if ($request->status !== SignupRequest::AWAITING_PAYMENT || ! $request->plan_id || $request->price_cents === null) {
            // A verified payment against a cancelled or expired request is real money Ava did not record: the transaction id lets a person find it at the provider.
            $this->alerts->raise('signup_payment_unexpected_state', ['signup' => $request->id, 'status' => $request->status, 'transaction' => $payment->transactionId]);

            throw ValidationException::withMessages(['reference' => 'La contratación no está esperando un pago.']);
        }

        // A second, different transaction for a contract that already has its payment recorded is a possible double charge: the first payment is kept
        // (never overwritten) and a person is told, because refunding is a decision (no refund policy exists).
        if ($request->paid_at !== null && $request->payment_reference !== $payment->transactionId) {
            $this->flagDuplicatePayment($request, $payment);

            return $this->provisionReporting($request);
        }

        if ($payment->amountCents !== $request->price_cents || strtoupper($payment->currency) !== strtoupper((string) $request->currency) || trim($payment->transactionId) === '') {
            $this->alerts->raise('signup_payment_mismatch', ['signup' => $request->id, 'transaction' => $payment->transactionId]);

            throw ValidationException::withMessages(['amount' => 'El importe o la moneda del pago no coinciden con la contratación.']);
        }

        // The payment is recorded first and on its own, so a failure while provisioning never loses it.
        if ($request->payment_reference !== $payment->transactionId) {
            $request->update(['payment_reference' => $payment->transactionId, 'paid_at' => now()]);
        }

        return $this->provisionReporting($request);
    }

    private function flagDuplicatePayment(SignupRequest $request, PaymentConfirmation $payment): void
    {
        if ($request->payment_reference !== null && $request->payment_reference !== $payment->transactionId) {
            $this->alerts->raise('signup_duplicate_payment', ['signup' => $request->id, 'transaction' => $payment->transactionId]);
        }
    }

    /**
     * Payments already recorded whose Workspace was not created (provisioning failed): the ones a person must look at. Read only.
     *
     * @return Collection<int, SignupRequest>
     */
    public function stuck(): Collection
    {
        return SignupRequest::where('status', SignupRequest::AWAITING_PAYMENT)->whereNotNull('paid_at')->orderBy('paid_at')->get();
    }

    /**
     * Retries the provisioning of a request that was already PAID (reconciliation, `signup:reconcile --retry`). It never takes or checks a payment: it only
     * repeats what `confirmPayment` could not finish, and a request without a recorded payment is refused.
     */
    public function retryProvisioning(SignupRequest $request): SignupRequest
    {
        if ($request->status === SignupRequest::COMPLETED) {
            return $request;
        }

        if ($request->status !== SignupRequest::AWAITING_PAYMENT || $request->paid_at === null || blank($request->payment_reference)) {
            throw ValidationException::withMessages(['reference' => 'Solo se reintenta una contratación con un pago ya registrado.']);
        }

        return $this->provisionReporting($request);
    }

    private function provisionReporting(SignupRequest $request): SignupRequest
    {
        try {
            $plan = $request->plan;

            // The customer paid for the conditions they saw. If the modules of the plan changed since they accepted, it is still provisioned (the payment
            // is real) but a person is told, because the terms are not exactly the ones shown: a product decision (see RECOMENDACIONES §28).
            if ($plan && is_array($request->modules_snapshot)) {
                $now = $plan->modules()->where('modules.is_active', true)->orderBy('modules.key')->pluck('modules.key')->all();

                if ($now !== $request->modules_snapshot) {
                    $this->alerts->raise('signup_plan_changed_after_acceptance', ['signup' => $request->id, 'plan' => $plan->id]);
                }
            }

            return $this->provision($request);
        } catch (Throwable $e) {
            $this->alerts->raise('signup_provisioning_failed', ['signup' => $request->id, 'exception' => $e::class]);

            throw $e;
        }
    }

    private function provision(SignupRequest $request): SignupRequest
    {
        return DB::transaction(function () use ($request) {
            $locked = SignupRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SignupRequest::COMPLETED) {
                return $locked;
            }

            $plan = $locked->plan()->firstOrFail();
            $organization = $locked->organization_id ? Organization::findOrFail($locked->organization_id)
                : Organization::create(['name' => $locked->company_name, 'tax_id' => $locked->tax_id, 'phone' => $locked->phone]);
            $workspace = $locked->workspace_id ? Workspace::findOrFail($locked->workspace_id)
                : Workspace::create(['organization_id' => $organization->id, 'code' => $locked->workspace_code, 'name' => $locked->workspace_name]);
            $user = $locked->user_id ? User::findOrFail($locked->user_id) : $this->createAdministrator($locked);

            $workspace->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);

            // The Workspace is created closed by the registration and reopened by the first confirmed payment (SubscriptionService); the plan and its modules
            // are applied by the activation and read from the plan afterwards. Repeating any of this changes nothing.
            // What the customer accepted (price, period, modules and limits of that day) is frozen on the subscription: editing the plan later never changes it.
            $terms = $this->subscriptions->buildTerms(
                $plan, 'signup',
                $locked->price_cents !== null ? ['amount_cents' => $locked->price_cents, 'currency' => (string) $locked->currency, 'period' => (string) $locked->billing_period] : null,
                is_array($locked->modules_snapshot) ? $locked->modules_snapshot : null,
                $locked->limits_snapshot ?? [], // none recorded = none claimed: nothing is attributed from today's plan
            );
            $subscription = Subscription::where('workspace_id', $workspace->id)->first() ?? $this->subscriptions->registerNewCustomer($workspace, $plan, $terms);
            $this->subscriptions->confirmPayment($subscription, (string) $locked->payment_reference);

            if ($this->legal->available() && $locked->accepted_documents) {
                $this->legal->accept($user, array_keys($locked->accepted_documents), $locked->ip_address);
            }

            $locked->update(['status' => SignupRequest::COMPLETED, 'organization_id' => $organization->id, 'workspace_id' => $workspace->id, 'user_id' => $user->id]);

            return $locked;
        });
    }

    private function createAdministrator(SignupRequest $request): User
    {
        $user = new User(['name' => $request->admin_name, 'email' => $request->email]);
        // The password was hashed when the wizard stored it; the address was proven with the one-time code, not merely typed.
        $user->forceFill(['password' => $request->getRawOriginal('password'), 'email_verified_at' => $request->email_verified_at, 'is_active' => true])->save();

        return $user;
    }

    /** Editing anything after the contract was confirmed takes it back to a draft: the earlier summary, acceptance and checkout no longer apply. */
    private function reopen(SignupRequest $request): void
    {
        if (in_array($request->status, [SignupRequest::AWAITING_PAYMENT, SignupRequest::QUOTE_REQUESTED], true)) {
            $request->status = SignupRequest::DRAFT;
            $request->accepted_documents = null;
            $request->accepted_at = null;
            $request->expires_at = now()->addHours((int) config('signup.draft_ttl_hours'));
        }
    }

    private function assertEditable(SignupRequest $request): void
    {
        // Once a payment is recorded the contract is frozen: editing it would reopen it as a draft and drop it from the reconciliation.
        if ($request->paid_at !== null) {
            throw ValidationException::withMessages(['signup' => 'Tu pago ya fue registrado y estamos activando tu cuenta. Esta contratación no se puede modificar.']);
        }

        if (! in_array($request->status, [SignupRequest::DRAFT, SignupRequest::AWAITING_PAYMENT, SignupRequest::QUOTE_REQUESTED], true) || $request->expires_at->isPast() && $request->status === SignupRequest::DRAFT) {
            throw ValidationException::withMessages(['signup' => 'Esta contratación ya no se puede modificar. Empieza de nuevo.']);
        }
    }
}
