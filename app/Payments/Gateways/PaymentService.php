<?php

namespace App\Payments\Gateways;

use App\Audit\AuditLogger;
use App\Models\Integration;
use App\Models\PaymentTransaction;
use App\Models\PaymentTransactionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Services\OperationalAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The ONE place that creates and moves payment transactions. It owns what no provider may decide:
 *  - Workspace isolation: the payable, the connection and the transaction must all belong to the same Workspace, checked here on the server.
 *  - The amount and currency come from the payable; the browser sends neither.
 *  - One open collection per payable (`active_key` UNIQUE) and idempotent creation (`idempotency_key` UNIQUE per Workspace).
 *  - Approval needs a VERIFIED provider answer whose amount, currency and reference match the transaction; the browser coming back proves nothing.
 *  - The state machine (PaymentStatus): an approved payment never regresses, a failed query is «verification required», never a rejection.
 *  - Every move and every signal leaves an event; the same provider event (event_key) is processed once.
 * Providers only translate their protocol (GatewayAdapter).
 */
class PaymentService
{
    /** Seconds a browser return waits before asking the provider again (a refresh storm costs one query, not one per request). */
    private const BROWSER_RECHECK_SECONDS = 5;

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly GatewayAvailability $availability,
        private readonly ModuleAccess $modules,
        private readonly AuditLogger $audit,
        private readonly OperationalAlert $alerts,
    ) {}

    // ───────────────────────────── checkout ─────────────────────────────

    /** @throws PaymentException */
    public function checkout(Workspace $workspace, Payable $payable, string $gateway, string $idempotencyKey, ?int $linkId = null): CheckoutResult
    {
        if ($payable->paymentWorkspaceId() !== $workspace->id) {
            throw new PaymentException('Este pedido no existe.', 'not_found');
        }

        if (! $this->modules->allows($workspace, $payable->paymentModule()) || ! $payable->paymentOpen() || $payable->paymentAmountMinor() <= 0) {
            throw new PaymentException('Este pedido no se puede cobrar.', 'not_payable');
        }

        $integration = $this->availability->usable($workspace, $gateway, $payable->paymentCurrency());

        if (! $integration) {
            throw new PaymentException('Este método de pago no está disponible.', 'gateway_unavailable');
        }

        $adapter = $this->gateways->adapter($gateway);
        [$transaction, $created, $callbackToken] = $this->open($workspace, $payable, $integration, $adapter, hash('sha256', $idempotencyKey), $linkId);

        if (! $created) {
            if ($transaction->secrets === null && $transaction->external_id === null) {
                throw new PaymentException('El pago se está preparando. Intenta de nuevo en unos segundos.', 'in_progress');
            }

            $resumed = $adapter->resume($transaction, $integration);

            if (! $this->safeDestination($resumed, $adapter)) {
                throw new PaymentException('No se pudo reanudar el pago. Intenta de nuevo.', 'session_failed');
            }

            return new CheckoutResult($transaction, $resumed, false);
        }

        $customer = $payable->paymentCustomer();
        $context = new CheckoutContext(
            route('payments.return', ['token' => $callbackToken]), route('payments.cancel', ['token' => $callbackToken]), null,
            $payable->paymentDescription(), $customer['name'], $customer['email'], $customer['phone'],
        );

        try {
            $checkout = $adapter->createSession($transaction, $integration, $context);
        } catch (PaymentException $exception) {
            $this->closeUnstarted($transaction, 'session_rejected');

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('payment_session_failed', ['transaction' => $transaction->id, 'provider' => $transaction->provider, 'exception' => $exception::class]);
            $this->closeUnstarted($transaction, 'session_failed');

            throw new PaymentException('No se pudo iniciar el pago con el proveedor. Intenta de nuevo o elige otro método.', 'session_failed');
        }

        if (! $this->safeDestination($checkout, $adapter)) {
            Log::warning('payment_session_unsafe_destination', ['transaction' => $transaction->id, 'provider' => $transaction->provider]);
            $this->closeUnstarted($transaction, 'unsafe_destination');

            throw new PaymentException('No se pudo iniciar el pago con el proveedor. Intenta de nuevo o elige otro método.', 'session_failed');
        }

        $transaction->forceFill([
            'secrets' => $checkout->secrets ?: null, 'external_id' => $checkout->externalId ?: $transaction->external_id,
            'expires_at' => $checkout->expiresAt ?? $transaction->expires_at,
        ])->save();
        $this->event($transaction, 'created', 'server', null, PaymentStatus::Pending, 'applied');

        return new CheckoutResult($transaction, $checkout, true);
    }

    /** @return array{0: PaymentTransaction, 1: bool, 2: ?string} transaction, whether it is new, and the plain callback token (only when new) */
    private function open(Workspace $workspace, Payable $payable, Integration $integration, GatewayAdapter $adapter, string $key, ?int $linkId): array
    {
        try {
            return DB::transaction(function () use ($workspace, $payable, $integration, $adapter, $key, $linkId) {
                $same = PaymentTransaction::where('workspace_id', $workspace->id)->where('idempotency_key', $key)->lockForUpdate()->first();

                if ($same) {
                    if ($same->payable_type !== $payable->paymentType() || $same->payable_id !== $payable->paymentId()) {
                        throw new PaymentException('Esta solicitud ya se usó para otro pago.', 'idempotency_conflict');
                    }

                    if ($same->state() !== PaymentStatus::Pending) {
                        throw new PaymentException('Este intento de pago ya terminó o está en verificación. Recarga la página.', 'attempt_closed');
                    }

                    return [$same, false, null];
                }

                $base = PaymentTransaction::where('workspace_id', $workspace->id)->where('payable_type', $payable->paymentType())->where('payable_id', $payable->paymentId());

                if ((clone $base)->whereIn('status', [PaymentStatus::Approved->value, PaymentStatus::RefundPending->value])->lockForUpdate()->exists()) {
                    throw new PaymentException('Este pedido ya está pagado.', 'already_paid');
                }

                $current = (clone $base)->whereNotNull('active_key')->lockForUpdate()->first();

                if ($current) {
                    $reusable = $current->state() === PaymentStatus::Pending && $current->integration_id === $integration->id && $current->expires_at?->isFuture();

                    if ($reusable) {
                        return [$current, false, null];
                    }

                    if ($current->state() !== PaymentStatus::Pending) {
                        throw new PaymentException('Hay un pago de este pedido en verificación. Espera unos minutos antes de intentar de nuevo.', 'verification_pending');
                    }

                    $this->move($current, PaymentStatus::Cancelled, 'transition', 'server', 'superseded', $this->lateWatch($current));
                }

                $reference = 'AVA'.Str::upper(Str::random(13));
                $callbackToken = Str::random(40);
                $transaction = PaymentTransaction::create([
                    'workspace_id' => $workspace->id, 'integration_id' => $integration->id, 'payment_link_id' => $linkId,
                    'provider' => $adapter->key(), 'environment' => $adapter->environment($integration),
                    'payable_type' => $payable->paymentType(), 'payable_id' => $payable->paymentId(),
                    'reference' => $reference, 'provider_reference' => $adapter->providerReference($reference),
                    'callback_token_hash' => hash('sha256', $callbackToken),
                    'amount_minor' => $payable->paymentAmountMinor(), 'currency' => $payable->paymentCurrency(),
                    'status' => PaymentStatus::Pending->value, 'idempotency_key' => $key,
                    'active_key' => $payable->paymentType().':'.$payable->paymentId(),
                    'expires_at' => now()->addMinutes((int) config('payments.transaction_ttl_minutes')),
                    'next_check_at' => now()->addMinutes((int) config('payments.reconcile.first_check_minutes')),
                ]);

                return [$transaction, true, $callbackToken];
            });
        } catch (UniqueConstraintViolationException) {
            throw new PaymentException('Ya hay un pago en curso para este pedido. Intenta de nuevo en unos segundos.', 'in_progress');
        }
    }

    /** The customer is only ever sent to https (http only while testing) and to a host the adapter declared: nothing a provider answer says can redirect elsewhere. */
    private function safeDestination(GatewayCheckout $checkout, GatewayAdapter $adapter): bool
    {
        $parts = parse_url($checkout->url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return isset($parts['host']) && ! isset($parts['user']) && ($scheme === 'https' || ($scheme === 'http' && app()->environment('testing', 'local')))
            && in_array(strtolower($parts['host']), array_map('strtolower', $adapter->hosts()), true)
            && in_array($checkout->method, ['redirect', 'form_post'], true);
    }

    /**
     * A transaction Ava closes ITSELF (expired, replaced, its order cancelled) is not proof that nothing was charged: while the provider's session may still be open it keeps
     * being asked about (`watch_until`). A session that never existed has nothing to watch.
     *
     * @return array<string, mixed>
     */
    private function lateWatch(PaymentTransaction $transaction): array
    {
        if ($transaction->secrets === null && $transaction->external_id === null) {
            return ['next_check_at' => null];
        }

        return ['watch_until' => now()->addHours((int) config('payments.late_watch_hours')), 'next_check_at' => now()->addMinutes((int) config('payments.reconcile.late_backoff_minutes')[0]), 'check_attempts' => 0];
    }

    /** A session that never started leaves nothing to wait for: the payable is free again. */
    private function closeUnstarted(PaymentTransaction $transaction, string $code): void
    {
        DB::transaction(function () use ($transaction, $code) {
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first();

            if ($locked && $locked->state() === PaymentStatus::Pending) {
                $this->move($locked, PaymentStatus::Cancelled, 'transition', 'server', $code, ['failure_code' => $code, 'next_check_at' => null]);
            }
        });
    }

    // ───────────────────────────── the customer's browser ─────────────────────────────

    /**
     * The customer came back (or cancelled). This is a HINT, never proof: it is related to the transaction by its own opaque token, recorded, and then the provider is
     * asked from the server. Whatever the browser said, the answer comes from the provider.
     */
    public function browserSignal(string $callbackToken, string $signal, ?string $hint = null): ?PaymentTransaction
    {
        if (strlen($callbackToken) !== 40) {
            return null;
        }

        $transaction = PaymentTransaction::where('callback_token_hash', hash('sha256', $callbackToken))->first();

        if (! $transaction) {
            return null;
        }

        $this->event($transaction, 'browser_return', 'browser', $transaction->state(), null, 'received', $signal === 'cancel' ? 'cancel_signal' : 'return_signal');

        // A provider may put ITS transaction id in the return URL. The browser can write anything there, so it is only a candidate to look up: the adapter accepts the answer
        // only if the reference, amount and currency the provider reports are this transaction's. Never used as proof, never overwrites a known id.
        if ($hint !== null && preg_match('/^[A-Za-z0-9._-]{1,80}$/', $hint) === 1 && $transaction->external_id === null && ($transaction->metadata['candidate_id'] ?? null) !== $hint) {
            $transaction->forceFill(['metadata' => array_merge($transaction->metadata ?? [], ['candidate_id' => $hint])])->save();
        }

        if (! $transaction->state()->isOpen()) {
            // The customer came back to an attempt Ava had closed itself: they may have paid in the meantime.
            return $this->watching($transaction) ? $this->verify($transaction) : $transaction;
        }

        DB::transaction(function () use ($transaction) {
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first();

            if ($locked->state() === PaymentStatus::Pending) {
                $this->move($locked, PaymentStatus::Processing, 'transition', 'browser', 'customer_returned');
            }
        });

        $transaction->refresh();

        if ($transaction->last_checked_at === null || $transaction->last_checked_at->diffInSeconds(now()) >= self::BROWSER_RECHECK_SECONDS) {
            return $this->verify($transaction);
        }

        return $transaction;
    }

    private function watching(PaymentTransaction $transaction): bool
    {
        return in_array($transaction->state(), [PaymentStatus::Expired, PaymentStatus::Cancelled], true) && $transaction->watch_until !== null && $transaction->watch_until->isFuture();
    }

    // ───────────────────────────── verifying with the provider ─────────────────────────────

    /** Asks the provider, from the server, and applies the answer. Never creates a charge. */
    public function verify(PaymentTransaction $transaction, string $kind = 'query', string $source = 'server'): PaymentTransaction
    {
        return $this->apply($transaction, $this->ask($transaction), $kind, $source);
    }

    private function ask(PaymentTransaction $transaction): GatewayResult
    {
        $started = microtime(true);

        try {
            $adapter = $this->gateways->adapter($transaction->provider);
            $integration = Integration::where('workspace_id', $transaction->workspace_id)->find($transaction->integration_id);

            if (! $integration || ! $adapter->capabilities()->query) {
                return GatewayResult::unknown($integration ? 'query_unsupported' : 'connection_missing');
            }

            $result = $adapter->query($transaction, $integration);
        } catch (Throwable $exception) {
            Log::warning('payment_query_failed', ['transaction' => $transaction->id, 'provider' => $transaction->provider, 'exception' => $exception::class]);

            return GatewayResult::unknown('query_failed', null, (int) round((microtime(true) - $started) * 1000));
        }

        return $result->durationMs === null
            ? new GatewayResult($result->outcome, $result->amountMinor, $result->currency, $result->providerReference, $result->externalId, $result->authorizationCode, $result->code, $result->httpStatus, (int) round((microtime(true) - $started) * 1000))
            : $result;
    }

    /**
     * Applies a provider answer that is ALREADY authenticated (a server query, or a signed notification the adapter re-read through the API). `$eventKey` identifies a
     * provider event: delivering it again changes nothing.
     */
    public function apply(PaymentTransaction $transaction, GatewayResult $result, string $kind, string $source, ?string $eventKey = null): PaymentTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $result, $kind, $source, $eventKey) {
                $locked = PaymentTransaction::whereKey($transaction->id)->firstOrFail();
                // Every attempt of the payable is locked in the same (id) order by everyone, so two attempts of one order approved at the same moment queue instead of deadlocking.
                PaymentTransaction::where('workspace_id', $locked->workspace_id)->where('payable_type', $locked->payable_type)->where('payable_id', $locked->payable_id)->orderBy('id')->lockForUpdate()->get();
                $locked->refresh();

                if ($eventKey !== null) {
                    $previous = PaymentTransactionEvent::where('payment_transaction_id', $locked->id)->where('event_key', $eventKey)->first();

                    if ($previous && $previous->outcome !== 'lookup_failed') {
                        $this->event($locked, $kind, $source, $locked->state(), null, 'duplicate', 'replayed_event');

                        return $locked;
                    }
                }

                $this->settle($locked, $result, $kind, $source, $eventKey);

                return $locked->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return $transaction->refresh(); // the same event was applied by a parallel delivery
        }
    }

    private function settle(PaymentTransaction $transaction, GatewayResult $result, string $kind, string $source, ?string $eventKey): void
    {
        $from = $transaction->state();
        $mismatch = $result->outcome === GatewayOutcome::Approved ? $this->mismatch($transaction, $result) : null;
        $target = match ($result->outcome) {
            GatewayOutcome::Approved => $mismatch ? PaymentStatus::VerificationRequired : PaymentStatus::Approved,
            GatewayOutcome::Declined => PaymentStatus::Declined,
            GatewayOutcome::Cancelled => PaymentStatus::Cancelled,
            GatewayOutcome::Expired => PaymentStatus::Expired,
            // "Nothing started at the provider yet" is not progress: it must not promote a transaction the customer has not reached to «processing».
            GatewayOutcome::Pending => $result->code === 'not_started' ? $from : PaymentStatus::Processing,
            GatewayOutcome::Unknown => PaymentStatus::VerificationRequired,
            GatewayOutcome::Refunded => PaymentStatus::Refunded,
        };
        $code = $mismatch ?? $result->code;
        $detail = array_filter(['provider_outcome' => $result->outcome->value, 'amount_minor' => $result->amountMinor, 'currency' => $result->currency], fn ($value) => $value !== null);
        $attributes = ['last_checked_at' => now()];

        if ($result->outcome === GatewayOutcome::Refunded) {
            $this->settleRefunded($transaction, $result, $kind, $source, $eventKey, $detail);

            return;
        }

        // A closed or approved transaction does not go back because of a doubtful, pending or failed answer.
        if (! $from->isOpen() && ($result->outcome !== GatewayOutcome::Approved || in_array($from, [PaymentStatus::RefundPending, PaymentStatus::Refunded], true))) {
            $this->event($transaction, $kind, $source, $from, null, 'ignored', 'already_'.$from->value, $result, $detail, $eventKey);
            $transaction->forceFill($attributes)->save();

            return;
        }

        if ($mismatch) {
            $this->alerts->raise('payment_verification_mismatch', ['transaction' => $transaction->id, 'workspace' => $transaction->workspace_id, 'provider' => $transaction->provider, 'reason' => $mismatch]);
            $attributes['failure_code'] = $mismatch;
        }

        if ($target->isOpen()) {
            $attributes += $this->schedule($transaction, $result->outcome === GatewayOutcome::Pending || $result->outcome === GatewayOutcome::Unknown || $mismatch !== null);
        } else {
            // Ava's own decision to stop waiting (the customer never came back) is not the provider's answer: the session is still watched for a while.
            $attributes += $target === PaymentStatus::Expired && $result->code === 'abandoned' ? $this->lateWatch($transaction) : [];
            $attributes += ['next_check_at' => null, 'check_attempts' => 0];

            if (in_array($target, [PaymentStatus::Declined, PaymentStatus::Cancelled, PaymentStatus::Expired], true)) {
                $attributes['failure_code'] = $result->code;
            }

            if ($target === PaymentStatus::Approved) {
                // Two approved payments for one order: BOTH stay recorded (nothing is lost or merged); the later one is flagged so the extra charge is found and returned.
                $attributes += ['authorization_code' => $result->authorizationCode ?: $transaction->authorization_code, 'external_id' => $transaction->external_id ?: $result->externalId, 'failure_code' => $this->otherPaid($transaction) ? 'duplicate_payment' : null];
            }
        }

        if ($target === $from) {
            $transaction->forceFill($attributes)->save();
            $this->event($transaction, $kind, $source, $from, null, 'unchanged', $code, $result, $detail, $eventKey);

            return;
        }

        if ($target === PaymentStatus::Approved) {
            $this->warnIfAlreadyPaid($transaction);
            $this->closeOthersOfPaid($transaction);
        }

        $this->move($transaction, $target, $kind, $source, $code, $attributes, $detail, $result, $eventKey);
    }

    /**
     * The provider says the payment was returned outside Ava. From approved (or a refund in progress) it simply becomes refunded. From any other state the money DID move:
     * it is recorded as approved first (when amount and currency match) and then refunded, so the history is complete. A doubtful answer changes nothing.
     */
    private function settleRefunded(PaymentTransaction $transaction, GatewayResult $result, string $kind, string $source, ?string $eventKey, array $detail): void
    {
        $from = $transaction->state();
        $attributes = ['last_checked_at' => now(), 'next_check_at' => null, 'check_attempts' => 0];

        if ($from === PaymentStatus::Refunded) {
            $transaction->forceFill($attributes)->save();
            $this->event($transaction, $kind, $source, $from, null, 'unchanged', $result->code, $result, $detail, $eventKey);

            return;
        }

        if (! in_array($from, [PaymentStatus::Approved, PaymentStatus::RefundPending], true)) {
            if ($mismatch = $this->mismatch($transaction, $result)) {
                $this->alerts->raise('payment_verification_mismatch', ['transaction' => $transaction->id, 'workspace' => $transaction->workspace_id, 'provider' => $transaction->provider, 'reason' => $mismatch]);
                $this->event($transaction, $kind, $source, $from, null, 'ignored', $mismatch, $result, $detail, $eventKey);

                return;
            }

            $this->move($transaction, PaymentStatus::Approved, $kind, $source, 'approved_then_returned', ['external_id' => $transaction->external_id ?: $result->externalId, 'confirmed_at' => now()], $detail, $result);
        }

        $this->move($transaction, PaymentStatus::Refunded, $kind, $source, $result->code ?? 'returned_by_provider', $attributes, $detail, $result, $eventKey);
        $this->alerts->raise('payment_returned_by_provider', ['transaction' => $transaction->id, 'workspace' => $transaction->workspace_id, 'provider' => $transaction->provider]);
    }

    private function otherPaid(PaymentTransaction $transaction): bool
    {
        return PaymentTransaction::where('workspace_id', $transaction->workspace_id)->where('payable_type', $transaction->payable_type)->where('payable_id', $transaction->payable_id)
            ->where('id', '!=', $transaction->id)->whereIn('status', [PaymentStatus::Approved->value, PaymentStatus::RefundPending->value])->exists();
    }

    /** @return ?string a normalized reason when what the provider reports is not what Ava asked for */
    private function mismatch(PaymentTransaction $transaction, GatewayResult $result): ?string
    {
        return match (true) {
            $result->amountMinor === null || $result->amountMinor !== $transaction->amount_minor => 'amount_mismatch',
            $result->currency === null || strtoupper($result->currency) !== $transaction->currency => 'currency_mismatch',
            $result->providerReference !== null && $result->providerReference !== $transaction->provider_reference => 'reference_mismatch',
            $transaction->external_id !== null && $result->externalId !== null && $result->externalId !== $transaction->external_id => 'external_mismatch',
            default => null,
        };
    }

    /** @return array{check_attempts: int, next_check_at: ?Carbon, failure_code?: string} */
    private function schedule(PaymentTransaction $transaction, bool $failed): array
    {
        $attempts = $failed ? $transaction->check_attempts + 1 : $transaction->check_attempts;
        $config = config('payments.reconcile');

        if ($attempts >= $config['max_attempts'] || $transaction->created_at->lt(now()->subHours($config['give_up_hours']))) {
            $this->alerts->raise('payment_needs_review', ['transaction' => $transaction->id, 'workspace' => $transaction->workspace_id, 'provider' => $transaction->provider]);

            return ['check_attempts' => $attempts, 'next_check_at' => null, 'failure_code' => 'manual_review'];
        }

        $minutes = $config['backoff_minutes'][min(max($attempts - 1, 0), count($config['backoff_minutes']) - 1)];

        return ['check_attempts' => $attempts, 'next_check_at' => now()->addMinutes($minutes)];
    }

    /** The payable is paid: any other attempt still open is closed (it must not be paid a second time) and, if it had already reached the provider, someone is told. */
    private function closeOthersOfPaid(PaymentTransaction $paid): void
    {
        $others = PaymentTransaction::where('workspace_id', $paid->workspace_id)->where('payable_type', $paid->payable_type)->where('payable_id', $paid->payable_id)
            ->where('id', '!=', $paid->id)->whereNotNull('active_key')->lockForUpdate()->get();

        foreach ($others as $other) {
            if ($other->state() !== PaymentStatus::Pending) {
                $this->alerts->raise('payment_duplicate_risk', ['transaction' => $other->id, 'paid' => $paid->id, 'workspace' => $paid->workspace_id]);
            }

            $this->move($other, PaymentStatus::Cancelled, 'transition', 'server', 'payable_paid', $this->lateWatch($other));
        }
    }

    private function warnIfAlreadyPaid(PaymentTransaction $transaction): void
    {
        if ($this->otherPaid($transaction)) {
            $this->alerts->raise('payment_duplicate_approved', ['transaction' => $transaction->id, 'workspace' => $transaction->workspace_id, 'payable' => $transaction->payable_type.':'.$transaction->payable_id]);
        }
    }

    // ───────────────────────────── reconciliation ─────────────────────────────

    /** @return list<int> ids of the open transactions that are due for a check (batch-limited) */
    public function dueIds(): array
    {
        $open = array_map(fn (PaymentStatus $status) => $status->value, array_filter(PaymentStatus::cases(), fn (PaymentStatus $status) => $status->isOpen()));

        $watched = [PaymentStatus::Expired->value, PaymentStatus::Cancelled->value];

        return PaymentTransaction::where(fn ($q) => $q
            // Open and uncertain: asked until the reconciliation gives up (manual review).
            ->where(fn ($uncertain) => $uncertain->whereIn('status', $open)
                ->where(fn ($w) => $w->whereNull('failure_code')->orWhere('failure_code', '!=', 'manual_review'))
                ->where(fn ($w) => $w->where('next_check_at', '<=', now())->orWhere('expires_at', '<=', now())))
            // Closed by Ava itself while the provider's session may still be open.
            ->orWhere(fn ($closed) => $closed->whereIn('status', $watched)->where('watch_until', '>', now())->where('next_check_at', '<=', now())))
            ->orderBy('id')->limit((int) config('payments.reconcile.batch'))->pluck('id')->all();
    }

    /** One reconciliation step: ask the provider (never charge); expire what the customer abandoned. Safe to repeat. */
    public function reconcile(int $transactionId): ?PaymentTransaction
    {
        $transaction = PaymentTransaction::find($transactionId);

        if (! $transaction) {
            return null;
        }

        if ($this->watching($transaction)) {
            return $this->watchClosed($transaction);
        }

        if (! $transaction->state()->isOpen()) {
            return $transaction;
        }

        $expired = $transaction->expires_at !== null && $transaction->expires_at->isPast();

        if ($expired && $transaction->secrets === null && $transaction->external_id === null) {
            return $this->apply($transaction, new GatewayResult(GatewayOutcome::Expired, code: 'never_started'), 'reconcile', 'job');
        }

        $result = $this->ask($transaction);

        // The customer's time to START ran out (they never came back) and the provider still waits: Ava frees the order, but keeps watching the session
        // (`late_watch_hours`). A transaction the customer DID return to, or whose answer was unreadable, never expires by time.
        if ($expired && $transaction->state() === PaymentStatus::Pending && $result->outcome === GatewayOutcome::Pending) {
            return $this->apply($transaction, new GatewayResult(GatewayOutcome::Expired, code: 'abandoned'), 'reconcile', 'job');
        }

        $fresh = $this->apply($transaction, $result, 'reconcile', 'job');

        return $fresh;
    }

    /** One late look at a transaction Ava closed itself: a verified approval re-opens it; otherwise the next look is later, and the watch ends with its window. */
    private function watchClosed(PaymentTransaction $transaction): PaymentTransaction
    {
        $fresh = $this->apply($transaction, $this->ask($transaction), 'reconcile', 'job');

        if ($fresh->state() !== $transaction->state()) {
            return $fresh; // it moved (approved): nothing left to watch
        }

        $steps = (array) config('payments.reconcile.late_backoff_minutes');
        $attempts = $fresh->check_attempts + 1;
        $next = now()->addMinutes($steps[min($attempts, count($steps) - 1)]);

        $fresh->forceFill(['check_attempts' => $attempts, 'next_check_at' => $next->lt($fresh->watch_until) ? $next : null])->save();

        return $fresh;
    }

    /**
     * Staff asks the provider again about ONE attempt, whatever its state and however long ago it closed (the late watch ends, a person's decision does not). It never creates
     * a charge. A verified approval re-opens a closed attempt; an uncertain one that had been left for manual review is looked at from scratch.
     */
    public function reverify(PaymentTransaction $transaction): PaymentTransaction
    {
        if ($transaction->state() === PaymentStatus::Pending || $transaction->state() === PaymentStatus::Processing || $transaction->state() === PaymentStatus::VerificationRequired) {
            $transaction->forceFill(['check_attempts' => 0, 'failure_code' => $transaction->failure_code === 'manual_review' ? null : $transaction->failure_code])->save();
        }

        $fresh = $this->verify($transaction, 'query', 'admin');
        $workspace = Workspace::find($transaction->workspace_id);

        if ($workspace) {
            $this->audit->recordForActor('updated', 'payment', $transaction->id, 'Pago '.$transaction->reference, [AuditLogger::change('Verificación con el proveedor', null, $fresh->state()->label())], $workspace, 'Pagos (automático)');
        }

        return $fresh;
    }

    // ───────────────────────────── refunds ─────────────────────────────

    /** @throws PaymentException */
    public function refund(PaymentTransaction $transaction, int $amountMinor, ?User $by = null): PaymentTransaction
    {
        $adapter = $this->gateways->adapter($transaction->provider);

        if (! $adapter->capabilities()->refund || ! $adapter instanceof RefundsPayments) {
            throw new PaymentException('Este proveedor no permite reembolsos desde Ava. Hazlo en su portal.', 'refund_unsupported');
        }

        $integration = Integration::where('workspace_id', $transaction->workspace_id)->find($transaction->integration_id);

        if (! $integration || $amountMinor <= 0 || $amountMinor > $transaction->amount_minor || ($amountMinor < $transaction->amount_minor && ! $adapter->capabilities()->partialRefund)) {
            throw new PaymentException('El importe del reembolso no es válido para este proveedor.', 'refund_invalid');
        }

        DB::transaction(function () use ($transaction) {
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->state() !== PaymentStatus::Approved) {
                throw new PaymentException('Solo se puede reembolsar un pago aprobado.', 'refund_state');
            }

            $this->move($locked, PaymentStatus::RefundPending, 'refund', 'admin', 'refund_requested');
        });

        try {
            $outcome = $adapter->refund($transaction->refresh(), $integration, $amountMinor);
        } catch (Throwable $exception) {
            Log::warning('payment_refund_failed', ['transaction' => $transaction->id, 'exception' => $exception::class]);
            $this->event($transaction, 'refund', 'admin', PaymentStatus::RefundPending, null, 'lookup_failed', 'refund_unreadable');

            throw new PaymentException('No se pudo confirmar el reembolso con el proveedor. Queda pendiente de revisión.', 'refund_unknown');
        }

        DB::transaction(function () use ($transaction, $outcome) {
            $locked = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($outcome->accepted) {
                $this->move($locked, PaymentStatus::Refunded, 'refund', 'admin', $outcome->code ?? 'refunded', ['refund_external_id' => $outcome->externalId]);
            } else {
                $this->move($locked, PaymentStatus::Approved, 'refund', 'admin', $outcome->code ?? 'refund_rejected');
            }
        });

        $transaction->refresh();
        $workspace = Workspace::find($transaction->workspace_id);

        if ($workspace) {
            $this->audit->recordForActor('updated', 'payment', $transaction->id, 'Pago '.$transaction->reference, [AuditLogger::change('Reembolso', null, $transaction->status === PaymentStatus::Refunded->value ? 'Reembolsado' : 'Rechazado por el proveedor')], $workspace, 'Pagos (automático)');
        }

        if (! $outcome->accepted) {
            throw new PaymentException('El proveedor rechazó el reembolso. El pago sigue aprobado.', 'refund_rejected');
        }

        return $transaction;
    }

    /**
     * Before a payable is cancelled: a collection still waiting for the customer (never left Ava) is closed with it; a payment that is approved, being refunded, in
     * verification or already in the provider's hands blocks the cancellation (it must be refunded or resolved first), so an order is never cancelled while money is moving.
     *
     * @throws PaymentException
     */
    public function releaseForCancellation(Payable $payable): void
    {
        DB::transaction(function () use ($payable) {
            $rows = PaymentTransaction::where('workspace_id', $payable->paymentWorkspaceId())->where('payable_type', $payable->paymentType())->where('payable_id', $payable->paymentId())
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value, PaymentStatus::VerificationRequired->value, PaymentStatus::Approved->value, PaymentStatus::RefundPending->value])
                ->lockForUpdate()->get();

            if ($rows->contains(fn (PaymentTransaction $row) => $row->state() !== PaymentStatus::Pending)) {
                throw new PaymentException('Este pedido tiene un pago aprobado o en verificación. Resuélvelo o reembólsalo antes de cancelarlo.', 'payment_blocks_cancellation');
            }

            foreach ($rows as $row) {
                $this->move($row, PaymentStatus::Cancelled, 'transition', 'server', 'payable_cancelled', $this->lateWatch($row));
            }
        });
    }

    // ───────────────────────────── the order's view ─────────────────────────────

    /** @return array{state: 'paid'|'pending'|'verification'|'refund_pending'|'refunded'|'unpaid', label: string, transaction: ?PaymentTransaction} the payment state of a payable, DERIVED from its transactions */
    public function summary(int $workspaceId, string $type, int $id): array
    {
        $transactions = PaymentTransaction::where('workspace_id', $workspaceId)->where('payable_type', $type)->where('payable_id', $id)->orderByDesc('id')->get();
        $pick = fn (PaymentStatus ...$statuses) => $transactions->first(fn (PaymentTransaction $transaction) => in_array($transaction->state(), $statuses, true));

        if ($transaction = $pick(PaymentStatus::Approved)) {
            if ($transactions->filter(fn (PaymentTransaction $row) => in_array($row->state(), [PaymentStatus::Approved, PaymentStatus::RefundPending], true))->count() > 1) {
                return ['state' => 'duplicate', 'label' => 'Pago duplicado', 'transaction' => $transaction];
            }

            return ['state' => 'paid', 'label' => 'Pagado', 'transaction' => $transaction];
        }

        if ($transaction = $pick(PaymentStatus::RefundPending)) {
            return ['state' => 'refund_pending', 'label' => 'Reembolso en curso', 'transaction' => $transaction];
        }

        if ($transaction = $pick(PaymentStatus::VerificationRequired)) {
            return ['state' => 'verification', 'label' => 'Pago en verificación', 'transaction' => $transaction];
        }

        if ($transaction = $pick(PaymentStatus::Pending, PaymentStatus::Processing)) {
            return ['state' => 'pending', 'label' => 'Pago pendiente', 'transaction' => $transaction];
        }

        if ($transaction = $pick(PaymentStatus::Refunded)) {
            return ['state' => 'refunded', 'label' => 'Reembolsado', 'transaction' => $transaction];
        }

        return ['state' => 'unpaid', 'label' => 'Sin pagar', 'transaction' => $transactions->first()];
    }

    // ───────────────────────────── the state machine and its history ─────────────────────────────

    /**
     * Moves a transaction (caller holds the row lock). Refuses a move the machine does not allow and records it as «ignored». Closing a transaction frees the payable
     * (`active_key`). Returns whether it moved.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $detail
     */
    private function move(PaymentTransaction $transaction, PaymentStatus $to, string $kind, string $source, ?string $code = null, array $attributes = [], array $detail = [], ?GatewayResult $result = null, ?string $eventKey = null): bool
    {
        $from = $transaction->state();

        if (! $from->canMoveTo($to)) {
            $this->event($transaction, $kind, $source, $from, null, 'ignored', 'invalid_transition', $result, $detail, $eventKey);

            return false;
        }

        $attributes['status'] = $to->value;

        if (! $to->isOpen()) {
            $attributes['active_key'] = null;
        }

        if ($to === PaymentStatus::Approved) {
            $attributes['confirmed_at'] = now();
        }

        $transaction->forceFill($attributes)->save();
        $this->event($transaction, $kind, $source, $from, $to, 'applied', $code, $result, $detail, $eventKey);

        if ($to === PaymentStatus::Approved) {
            $workspace = Workspace::find($transaction->workspace_id);
            $workspace && $this->audit->system('updated', 'payment', $transaction->id, 'Pago '.$transaction->reference, [AuditLogger::change('Estado del pago', $from->label(), $to->label())], $workspace, 'Pasarela de pago (automático)');
        }

        return true;
    }

    /** @param  array<string, mixed>  $detail */
    private function event(PaymentTransaction $transaction, string $kind, string $source, ?PaymentStatus $from, ?PaymentStatus $to, string $outcome, ?string $code = null, ?GatewayResult $result = null, array $detail = [], ?string $eventKey = null): PaymentTransactionEvent
    {
        return PaymentTransactionEvent::create([
            'workspace_id' => $transaction->workspace_id, 'payment_transaction_id' => $transaction->id, 'kind' => $kind, 'source' => $source,
            'event_key' => $eventKey, 'from_status' => $from?->value, 'to_status' => $to?->value, 'outcome' => $outcome,
            'code' => $code === null ? null : substr($code, 0, 40), 'http_status' => $result?->httpStatus, 'duration_ms' => $result?->durationMs, 'detail' => $detail ?: null,
        ]);
    }
}
