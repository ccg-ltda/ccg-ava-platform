<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePaymentTransaction;
use App\Models\Integration;
use App\Models\Order;
use App\Models\PaymentLink;
use App\Models\PaymentTransaction;
use App\Models\PaymentTransactionEvent;
use App\Models\Workspace;
use App\Models\WorkspacePaymentGatewayOverride;
use App\Orders\OrderException;
use App\Orders\OrderService;
use App\Payments\Gateways\CheckoutResult;
use App\Payments\Gateways\GatewayAvailability;
use App\Payments\Gateways\GatewayOutcome;
use App\Payments\Gateways\GatewayOverrides;
use App\Payments\Gateways\GatewayResult;
use App\Payments\Gateways\Payables;
use App\Payments\Gateways\PaymentException;
use App\Payments\Gateways\PaymentGatewayManager;
use App\Payments\Gateways\PaymentLinks;
use App\Payments\Gateways\PaymentService;
use App\Payments\Gateways\PaymentStatus;
use App\Payments\Gateways\RefundResult;
use App\Services\OperationalAlert;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\Support\FakeGatewayAdapter;
use Tests\Support\FakePayType;
use Tests\TestCase;

/**
 * The provider-neutral payment core, with a provider DOUBLE (FakeGatewayAdapter): it proves Ava's own rules, not any provider. Nothing here reaches a network
 * (`Http::preventStrayRequests`) and nothing here is a Sandbox test. The rules: the server decides the amount, the browser proves nothing, a failed query is never a
 * rejection, an approved payment never goes back, a Workspace never sees another one's payments, and a gateway needs the plan AND a validated connection.
 */
class PaymentCoreTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'integrations', 'product_catalog', 'orders'];

    private Workspace $shop;

    private object $alerts;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:*' => Http::response('', 404)]); // the Inertia SSR probe of the page render; every provider call stays unfaked and would fail the test
        FakeGatewayAdapter::reset();
        config(['payments.gateways' => ['fakepay' => FakeGatewayAdapter::class], 'integrations.types.fakepay' => FakePayType::class, 'payments.allow_production' => false]);
        $this->alerts = new class extends OperationalAlert
        {
            /** @var list<string> */
            public array $raised = [];

            public function raise(string $event, array $context = []): void
            {
                $this->raised[] = $event;
            }
        };
        $this->app->instance(OperationalAlert::class, $this->alerts);

        $this->seedRoleCatalog();
        $this->shop = $this->gatewayWorkspace('TIENDA');
    }

    private function gatewayWorkspace(string $code, array $gateways = ['fakepay'], bool $connect = true): Workspace
    {
        $workspace = $this->bookingWorkspace($code, self::MODULES);
        $workspace->plan->forceFill(['payment_gateways' => $gateways])->save();
        $connect && $this->connect($workspace);

        return $workspace->refresh();
    }

    private function connect(Workspace $workspace, array $attributes = []): Integration
    {
        return $workspace->integrations()->create($attributes + [
            'name' => 'FakePay', 'provider' => 'fakepay', 'type' => 'fakepay', 'is_active' => true,
            'config' => ['merchant' => 'M-'.$workspace->code, 'environment' => 'sandbox'], 'secrets' => ['key' => 'super-secret-'.$workspace->code], 'last_test_ok' => true,
        ]);
    }

    private function order(?Workspace $workspace = null, string $total = '1180.50', string $currency = 'DOP', array $extra = []): Order
    {
        $workspace ??= $this->shop;

        return Order::create($extra + [
            'workspace_id' => $workspace->id, 'number' => (Order::where('workspace_id', $workspace->id)->max('number') ?? 0) + 1, 'customer_name' => 'Laura', 'customer_email' => 'laura@example.com',
            'customer_phone' => '8091112222', 'currency' => $currency, 'total' => $total, 'status' => 'received',
        ]);
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function checkout(Order $order, string $attempt = 'attempt-1', ?Workspace $workspace = null): CheckoutResult
    {
        return $this->payments()->checkout($workspace ?? $this->shop, $order, 'fakepay', $attempt);
    }

    private function answer(PaymentTransaction $transaction, GatewayResult $result): void
    {
        FakeGatewayAdapter::$answers[$transaction->provider_reference] = $result;
    }

    private function refused(callable $call, string $reason): void
    {
        try {
            $call();
            $this->fail("Expected a refusal for [{$reason}].");
        } catch (PaymentException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    // ───────────── availability: plan, administration, connection ─────────────

    public function test_a_plan_that_never_saved_a_gateway_list_includes_none_closed_by_default(): void
    {
        $workspace = $this->gatewayWorkspace('SINLISTA', []);
        $workspace->plan->forceFill(['payment_gateways' => null])->save();

        $state = app(GatewayAvailability::class)->describe($workspace->refresh(), 'fakepay');

        $this->assertFalse($state['available']);
        $this->assertSame('plan_disabled', $state['state']);
        $this->assertNull(app(GatewayAvailability::class)->usable($workspace, 'fakepay'));
    }

    public function test_the_state_of_a_connection_walks_from_missing_to_validated(): void
    {
        $workspace = $this->gatewayWorkspace('PASOS', ['fakepay'], false);
        $availability = app(GatewayAvailability::class);

        $this->assertSame('not_configured', $availability->describe($workspace, 'fakepay')['state']);

        $integration = $this->connect($workspace, ['config' => ['environment' => 'sandbox'], 'last_test_ok' => null]);
        $this->assertSame('incomplete', $availability->describe($workspace, 'fakepay')['state']);

        $integration->update(['config' => ['merchant' => 'M1', 'environment' => 'sandbox']]);
        $this->assertSame('saved', $availability->describe($workspace, 'fakepay')['state']); // saved is NOT validated
        $this->assertFalse($availability->describe($workspace, 'fakepay')['available']);

        $integration->update(['last_test_ok' => false]);
        $this->assertSame('error', $availability->describe($workspace, 'fakepay')['state']);

        $integration->update(['last_test_ok' => true, 'is_active' => false]);
        $this->assertSame('inactive', $availability->describe($workspace, 'fakepay')['state']);

        $integration->update(['is_active' => true]);
        $this->assertTrue($availability->describe($workspace, 'fakepay')['available']);
    }

    public function test_production_stays_blocked_until_the_installation_allows_live_charges(): void
    {
        $workspace = $this->gatewayWorkspace('PROD', ['fakepay'], false);
        $this->connect($workspace, ['config' => ['merchant' => 'M1', 'environment' => 'production']]);

        $this->assertSame('blocked_environment', app(GatewayAvailability::class)->describe($workspace, 'fakepay')['state']);

        config(['payments.allow_production' => true]);
        $this->assertTrue(app(GatewayAvailability::class)->describe($workspace, 'fakepay')['available']);
    }

    public function test_an_unsupported_currency_makes_the_gateway_incompatible(): void
    {
        $this->assertSame('incompatible', app(GatewayAvailability::class)->describe($this->shop, 'fakepay', 'USD')['state']);
    }

    public function test_an_administrative_revoke_beats_the_plan_and_a_grant_never_rescues_an_unvalidated_connection(): void
    {
        $availability = app(GatewayAvailability::class);
        $overrides = app(GatewayOverrides::class);

        $overrides->set($this->shop, 'fakepay', WorkspacePaymentGatewayOverride::REVOKE, 'Contrato suspendido', null);
        $this->assertSame('admin_disabled', $availability->describe($this->shop, 'fakepay')['state']);

        $other = $this->gatewayWorkspace('CONCESION', [], false); // the plan does NOT include it
        $this->assertSame('plan_disabled', $availability->describe($other, 'fakepay')['state']);

        $overrides->set($other, 'fakepay', WorkspacePaymentGatewayOverride::GRANT, 'Piloto', null);
        $this->assertSame('not_configured', $availability->describe($other, 'fakepay')['state']); // permitted, but nothing to charge with

        $integration = $this->connect($other, ['last_test_ok' => null]);
        $this->assertFalse($availability->describe($other, 'fakepay')['available']); // permitted, saved, not validated

        $integration->update(['last_test_ok' => true]);
        $this->assertTrue($availability->describe($other, 'fakepay')['available']);

        $overrides->clear($this->shop, 'fakepay');
        $this->assertTrue($availability->describe($this->shop, 'fakepay')['available']);
    }

    public function test_the_connection_of_another_workspace_never_counts(): void
    {
        $bare = $this->gatewayWorkspace('SINCONEXION', ['fakepay'], false);

        $this->assertSame('not_configured', app(GatewayAvailability::class)->describe($bare, 'fakepay')['state']);
        $this->assertNull(app(GatewayAvailability::class)->usable($bare, 'fakepay'));
    }

    // ───────────── checkout ─────────────

    public function test_the_amount_and_currency_come_from_the_order_never_from_the_caller(): void
    {
        $order = $this->order(null, '1180.50', 'DOP');

        $result = $this->checkout($order);
        $transaction = $result->transaction;

        $this->assertTrue($result->created);
        $this->assertSame(118050, $transaction->amount_minor);
        $this->assertSame('DOP', $transaction->currency);
        $this->assertSame($this->shop->id, $transaction->workspace_id);
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('form_post', $result->checkout->method);
        $this->assertNotSame($transaction->reference, $transaction->callback_token_hash);
    }

    public function test_the_four_identifiers_stay_apart(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction, ['external' => 'PROVIDER-ID-77']));
        $transaction = $this->payments()->verify($transaction);

        $this->assertSame('PROVIDER-ID-77', $transaction->external_id);
        $this->assertNotSame($transaction->reference, $transaction->external_id);
        $this->assertSame(substr($transaction->reference, 0, 20), $transaction->provider_reference);
        $this->assertNotSame($transaction->external_id, $transaction->provider_reference);
    }

    public function test_the_same_attempt_twice_creates_one_transaction_and_one_provider_session(): void
    {
        $order = $this->order();

        $first = $this->checkout($order, 'double-click');
        $second = $this->checkout($order, 'double-click');

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->transaction->id, $second->transaction->id);
        $this->assertSame(1, FakeGatewayAdapter::$sessions);
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_a_new_attempt_while_the_first_is_still_pending_reuses_it(): void
    {
        $order = $this->order();

        $first = $this->checkout($order, 'one');
        $second = $this->checkout($order, 'two');

        $this->assertSame($first->transaction->id, $second->transaction->id);
        $this->assertSame(1, FakeGatewayAdapter::$sessions);
    }

    public function test_the_same_attempt_key_cannot_pay_another_order(): void
    {
        $this->checkout($this->order(), 'shared-key');

        $this->refused(fn () => $this->checkout($this->order(), 'shared-key'), 'idempotency_conflict');
    }

    public function test_a_workspace_cannot_collect_the_order_of_another_workspace(): void
    {
        $other = $this->gatewayWorkspace('OTRA');
        $foreign = $this->order($other);

        $this->refused(fn () => $this->payments()->checkout($this->shop, $foreign, 'fakepay', 'x'), 'not_found');
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertNull(app(Payables::class)->find($this->shop->id, 'order', $foreign->id));
    }

    public function test_a_gateway_the_workspace_may_not_use_is_refused_on_the_server(): void
    {
        $order = $this->order();
        $this->shop->plan->forceFill(['payment_gateways' => []])->save();

        $this->refused(fn () => $this->checkout($order->refresh()), 'gateway_unavailable');
        $this->assertSame(0, FakeGatewayAdapter::$sessions);
    }

    public function test_a_cancelled_order_cannot_be_collected(): void
    {
        $this->refused(fn () => $this->checkout($this->order(null, '10.00', 'DOP', ['status' => 'cancelled'])), 'not_payable');
    }

    public function test_a_session_that_fails_frees_the_order_and_never_leaks_the_provider_text(): void
    {
        FakeGatewayAdapter::$failSession = true;
        $order = $this->order();

        try {
            $this->checkout($order);
            $this->fail('The failure must surface.');
        } catch (PaymentException $exception) {
            $this->assertSame('session_failed', $exception->reason);
            $this->assertStringNotContainsString('secret-token', $exception->getMessage());
        }

        $this->assertNull(PaymentTransaction::first()->active_key);
        $this->assertSame('cancelled', PaymentTransaction::first()->status);

        FakeGatewayAdapter::$failSession = false;
        $this->assertTrue($this->checkout($order, 'retry')->created); // the order can be collected again
    }

    public function test_the_session_secrets_are_encrypted_and_never_serialized(): void
    {
        $transaction = $this->checkout($this->order())->transaction->refresh();

        $raw = DB::table('payment_transactions')->where('id', $transaction->id)->value('secrets');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sess-', $raw);
        $this->assertSame('sess-'.$transaction->provider_reference, $transaction->secrets['session']);
        $this->assertArrayNotHasKey('secrets', $transaction->toArray());
        $this->assertArrayNotHasKey('callback_token_hash', $transaction->toArray());
    }

    // ───────────── verification: the browser proves nothing ─────────────

    public function test_coming_back_from_the_provider_does_not_approve_anything(): void
    {
        $order = $this->order();
        $result = $this->checkout($order);
        $token = $this->callbackToken();
        $this->answer($result->transaction, new GatewayResult(GatewayOutcome::Pending));

        $this->get("/api/payments/return/{$token}?status=APPROVED&ResponseCode=00&Amount=118050")->assertRedirect(route('payments.status', $token));

        $transaction = $result->transaction->refresh();
        $this->assertSame('processing', $transaction->status);
        $this->assertSame('pending', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
        $this->assertSame(1, FakeGatewayAdapter::$queries); // the answer came from asking the provider
    }

    public function test_a_forged_cancel_does_not_cancel_a_payment_the_provider_still_holds(): void
    {
        $result = $this->checkout($this->order());
        $token = $this->callbackToken();
        $this->answer($result->transaction, new GatewayResult(GatewayOutcome::Pending));

        $this->post("/api/payments/cancel/{$token}")->assertRedirect();

        $this->assertSame('processing', $result->transaction->refresh()->status);
    }

    public function test_an_unknown_or_foreign_token_is_a_404_and_touches_nothing(): void
    {
        $result = $this->checkout($this->order());

        $this->get('/api/payments/return/'.str_repeat('a', 40))->assertNotFound();
        $this->post('/api/payments/cancel/'.str_repeat('b', 40))->assertNotFound();
        $this->get('/pay/status/'.str_repeat('c', 40))->assertNotFound();
        $this->assertSame('pending', $result->transaction->refresh()->status);
    }

    public function test_a_verified_approval_pays_the_order_and_the_second_collection_is_refused(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));

        $transaction = $this->payments()->verify($transaction);

        $this->assertSame('approved', $transaction->status);
        $this->assertNotNull($transaction->confirmed_at);
        $this->assertNull($transaction->active_key);
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
        $this->refused(fn () => $this->checkout($order, 'again'), 'already_paid');
    }

    public function test_an_approval_with_another_amount_is_never_believed(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction, ['amount' => 100]));

        $transaction = $this->payments()->verify($transaction);

        $this->assertSame('verification_required', $transaction->status);
        $this->assertSame('amount_mismatch', $transaction->failure_code);
        $this->assertContains('payment_verification_mismatch', $this->alerts->raised);
    }

    public function test_an_approval_in_another_currency_or_for_another_reference_is_never_believed(): void
    {
        $currency = $this->checkout($this->order(), 'c')->transaction;
        $this->answer($currency, FakeGatewayAdapter::approved($currency, ['currency' => 'USD']));
        $this->assertSame('currency_mismatch', $this->payments()->verify($currency)->failure_code);

        $reference = $this->checkout($this->order(), 'r')->transaction;
        $this->answer($reference, FakeGatewayAdapter::approved($reference, ['reference' => 'OTHER-ORDER']));
        $this->assertSame('reference_mismatch', $this->payments()->verify($reference)->failure_code);
        $this->assertSame(0, PaymentTransaction::where('status', 'approved')->count());
    }

    public function test_an_external_id_that_changes_is_a_mismatch(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction, ['external' => 'EXT-A']));
        $this->payments()->verify($transaction); // approved with EXT-A

        $second = $this->checkout($this->order(), 'other')->transaction;
        $second->forceFill(['external_id' => 'EXT-ORIGINAL'])->save();
        $this->answer($second, FakeGatewayAdapter::approved($second, ['external' => 'EXT-DIFFERENT']));

        $this->assertSame('external_mismatch', $this->payments()->verify($second)->failure_code);
    }

    public function test_a_failed_query_is_verification_required_never_a_rejection_and_it_recovers(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $this->answer($transaction, GatewayResult::unknown('query_failed'));

        $transaction = $this->payments()->verify($transaction);
        $this->assertSame('verification_required', $transaction->status);
        $this->assertNotNull($transaction->next_check_at);
        $this->assertSame(1, $transaction->check_attempts);

        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));
        $this->assertSame('approved', $this->payments()->verify($transaction)->status);
    }

    public function test_an_adapter_that_throws_is_treated_as_unknown(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        FakeGatewayAdapter::$answers[$transaction->provider_reference] = fn () => throw new \RuntimeException('boom');

        $this->assertSame('verification_required', $this->payments()->verify($transaction)->status);
    }

    public function test_an_approved_payment_never_goes_back_because_of_a_late_answer(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));
        $transaction = $this->payments()->verify($transaction);

        foreach ([new GatewayResult(GatewayOutcome::Declined), new GatewayResult(GatewayOutcome::Pending), GatewayResult::unknown('x'), new GatewayResult(GatewayOutcome::Cancelled)] as $late) {
            $this->payments()->apply($transaction, $late, 'notification', 'provider');
            $this->assertSame('approved', $transaction->refresh()->status);
        }
    }

    public function test_the_same_provider_event_is_applied_once(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $result = FakeGatewayAdapter::approved($transaction);

        $this->payments()->apply($transaction, $result, 'notification', 'provider', 'evt-1');
        $this->payments()->apply($transaction, $result, 'notification', 'provider', 'evt-1');

        $this->assertSame(1, PaymentTransactionEvent::where('payment_transaction_id', $transaction->id)->where('event_key', 'evt-1')->count());
        $this->assertSame(1, PaymentTransactionEvent::where('payment_transaction_id', $transaction->id)->where('outcome', 'duplicate')->count());
        $this->assertSame(1, PaymentTransactionEvent::where('payment_transaction_id', $transaction->id)->where('to_status', 'approved')->count());
    }

    public function test_a_closed_payment_can_be_reopened_only_by_a_verified_approval(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $this->payments()->apply($transaction, new GatewayResult(GatewayOutcome::Declined, code: '05'), 'query', 'server');
        $this->assertSame('declined', $transaction->refresh()->status);

        $this->payments()->apply($transaction, GatewayResult::unknown('x'), 'query', 'server'); // doubt does not reopen
        $this->assertSame('declined', $transaction->refresh()->status);

        $this->payments()->apply($transaction, FakeGatewayAdapter::approved($transaction), 'query', 'server'); // the customer did pay
        $this->assertSame('approved', $transaction->refresh()->status);
    }

    public function test_the_state_machine_refuses_what_it_does_not_allow(): void
    {
        $this->assertFalse(PaymentStatus::Approved->canMoveTo(PaymentStatus::Pending));
        $this->assertFalse(PaymentStatus::Approved->canMoveTo(PaymentStatus::Declined));
        $this->assertFalse(PaymentStatus::Refunded->canMoveTo(PaymentStatus::Approved));
        $this->assertTrue(PaymentStatus::VerificationRequired->canMoveTo(PaymentStatus::Approved));
        $this->assertTrue(PaymentStatus::Approved->canMoveTo(PaymentStatus::RefundPending));
        $this->assertSame([], PaymentStatus::Refunded->next());
    }

    public function test_an_order_with_a_payment_in_verification_cannot_be_paid_through_another_attempt(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;
        $this->answer($transaction, GatewayResult::unknown('query_failed'));
        $this->payments()->verify($transaction);

        $this->refused(fn () => $this->checkout($order, 'impatient'), 'verification_pending');
        $this->assertSame(1, PaymentTransaction::count());
    }

    public function test_the_database_allows_one_open_collection_per_order(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('payment_transactions')->insert([
            'workspace_id' => $this->shop->id, 'integration_id' => $transaction->integration_id, 'provider' => 'fakepay', 'environment' => 'sandbox', 'payable_type' => 'order',
            'payable_id' => $order->id, 'reference' => 'AVADUPLICATE000', 'callback_token_hash' => str_repeat('d', 64), 'amount_minor' => 1, 'currency' => 'DOP', 'status' => 'pending',
            'idempotency_key' => 'dup', 'active_key' => 'order:'.$order->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_transaction_pointing_at_the_connection_of_another_workspace(): void
    {
        $other = $this->gatewayWorkspace('AJENA');
        $foreign = $other->integrations()->first();
        $order = $this->order();
        $this->checkOnlyIfForeignKeysEnforced();

        $this->expectException(QueryException::class);
        DB::table('payment_transactions')->insert([
            'workspace_id' => $this->shop->id, 'integration_id' => $foreign->id, 'provider' => 'fakepay', 'environment' => 'sandbox', 'payable_type' => 'order', 'payable_id' => $order->id,
            'reference' => 'AVACROSS0000001', 'callback_token_hash' => str_repeat('e', 64), 'amount_minor' => 1, 'currency' => 'DOP', 'status' => 'pending', 'idempotency_key' => 'cross',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function checkOnlyIfForeignKeysEnforced(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    // ───────────── reconciliation ─────────────

    public function test_reconciliation_asks_the_provider_with_a_backoff_and_never_charges(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $transaction->forceFill(['next_check_at' => now()->subMinute(), 'status' => 'processing'])->save();
        $this->answer($transaction, GatewayResult::unknown('query_failed'));

        $this->assertSame([$transaction->id], $this->payments()->dueIds());
        $this->payments()->reconcile($transaction->id);
        $first = $transaction->refresh();
        $this->assertSame('verification_required', $first->status);
        $this->assertTrue($first->next_check_at->isFuture());
        $this->assertSame([], $this->payments()->dueIds()); // not due again until the backoff passes
        $this->assertSame(1, FakeGatewayAdapter::$sessions); // asking never creates a new session
    }

    public function test_reconciliation_confirms_a_payment_the_customer_never_came_back_from(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;
        $transaction->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));

        $this->payments()->reconcile($transaction->id);

        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
    }

    public function test_an_abandoned_attempt_expires_but_an_unreadable_answer_never_expires_it(): void
    {
        $abandoned = $this->checkout($this->order(), 'a')->transaction;
        $abandoned->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->answer($abandoned, new GatewayResult(GatewayOutcome::Pending));
        $this->payments()->reconcile($abandoned->id);
        $this->assertSame('expired', $abandoned->refresh()->status);
        $this->assertNull($abandoned->active_key);

        $unclear = $this->checkout($this->order(), 'b')->transaction;
        $unclear->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->answer($unclear, GatewayResult::unknown('query_failed'));
        $this->payments()->reconcile($unclear->id);
        $this->assertSame('verification_required', $unclear->refresh()->status);
    }

    public function test_after_too_many_failed_checks_it_stops_asking_and_waits_for_a_person(): void
    {
        config(['payments.reconcile.max_attempts' => 2]);
        $transaction = $this->checkout($this->order())->transaction;
        $this->answer($transaction, GatewayResult::unknown('query_failed'));

        $this->payments()->verify($transaction);
        $transaction = $this->payments()->verify($transaction->refresh());

        $this->assertSame('manual_review', $transaction->failure_code);
        $this->assertNull($transaction->next_check_at);
        $this->assertContains('payment_needs_review', $this->alerts->raised);
        $transaction->forceFill(['expires_at' => now()->subHour()])->save();
        $this->assertSame([], $this->payments()->dueIds());
    }

    public function test_the_command_dispatches_one_job_per_due_transaction(): void
    {
        Queue::fake();
        $transaction = $this->checkout($this->order())->transaction;
        $transaction->forceFill(['next_check_at' => now()->subMinute()])->save();

        $this->artisan('payments:reconcile')->assertSuccessful();

        Queue::assertPushed(ReconcilePaymentTransaction::class, fn ($job) => $job->transactionId === $transaction->id);
    }

    // ───────────── orders ─────────────

    public function test_an_order_with_a_pending_collection_can_be_cancelled_and_the_collection_closes(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;

        app(OrderService::class)->transition($order, 'cancelled', null, 'Cliente desistió');

        $this->assertSame('cancelled', $transaction->refresh()->status);
        $this->assertNull($transaction->active_key);
    }

    public function test_an_order_with_an_approved_payment_cannot_be_cancelled_until_it_is_refunded(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));
        $this->payments()->verify($transaction);

        $this->expectException(OrderException::class);
        app(OrderService::class)->transition($order, 'cancelled', null, 'Error');
    }

    public function test_an_order_without_payments_cancels_exactly_as_before(): void
    {
        $order = $this->order();

        $moved = app(OrderService::class)->transition($order, 'cancelled', null, 'Sin stock');

        $this->assertSame('cancelled', $moved->status);
    }

    // ───────────── refunds ─────────────

    public function test_a_provider_that_cannot_refund_is_never_asked(): void
    {
        FakeGatewayAdapter::$canRefund = false;
        $transaction = $this->approvedTransaction();

        $this->refused(fn () => $this->payments()->refund($transaction, $transaction->amount_minor), 'refund_unsupported');
        $this->assertSame('approved', $transaction->refresh()->status);
    }

    public function test_an_accepted_refund_uses_the_provider_id_and_a_rejected_one_leaves_the_payment_approved(): void
    {
        $transaction = $this->approvedTransaction();

        FakeGatewayAdapter::$refundAnswer = new RefundResult(false, null, 'declined_void');
        $this->refused(fn () => $this->payments()->refund($transaction, $transaction->amount_minor), 'refund_rejected');
        $this->assertSame('approved', $transaction->refresh()->status);

        FakeGatewayAdapter::$refundAnswer = null;
        $refunded = $this->payments()->refund($transaction, $transaction->amount_minor);
        $this->assertSame('refunded', $refunded->status);
        $this->assertSame('void-'.$transaction->external_id, $refunded->refund_external_id);
        $this->assertNotSame($refunded->external_id, $refunded->refund_external_id);
    }

    public function test_only_an_approved_payment_with_a_valid_amount_can_be_refunded(): void
    {
        $pending = $this->checkout($this->order())->transaction;
        $this->refused(fn () => $this->payments()->refund($pending, $pending->amount_minor), 'refund_state');

        $approved = $this->approvedTransaction();
        $this->refused(fn () => $this->payments()->refund($approved, $approved->amount_minor + 1), 'refund_invalid');
        $this->refused(fn () => $this->payments()->refund($approved, 1), 'refund_invalid'); // the double declares no partial refunds
    }

    private function approvedTransaction(): PaymentTransaction
    {
        $transaction = $this->checkout($this->order(), 'p'.PaymentTransaction::count())->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));

        return $this->payments()->verify($transaction);
    }

    // ───────────── the customer's link and page ─────────────

    public function test_the_payment_link_is_opaque_temporary_and_revocable(): void
    {
        $order = $this->order();
        $links = app(PaymentLinks::class);

        ['link' => $link, 'token' => $token] = $links->create($this->shop, $order, null);

        $this->assertSame(48, strlen($token));
        $this->assertNotSame($token, $link->token_hash);
        $this->assertStringNotContainsString($token, json_encode($link->toArray()));
        $this->assertNotNull($links->resolve($token));
        $this->assertNull($links->resolve(strtoupper($token))); // not guessable by case games
        $this->assertNull($links->resolve(str_repeat('x', 48)));

        $link->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull($links->resolve($token));

        $link->forceFill(['expires_at' => now()->addHour()])->save();
        $this->assertSame(1, $links->revokeFor($this->shop, $order));
        $this->assertNull($links->resolve($token));
    }

    public function test_a_link_cannot_be_created_for_the_order_of_another_workspace(): void
    {
        $foreign = $this->order($this->gatewayWorkspace('LEJANA'));

        $this->refused(fn () => app(PaymentLinks::class)->create($this->shop, $foreign, null), 'not_payable');
    }

    public function test_the_payment_page_shows_the_server_amount_and_only_the_methods_the_workspace_may_use(): void
    {
        $order = $this->order(null, '250.00', 'DOP');
        ['token' => $token] = app(PaymentLinks::class)->create($this->shop, $order, null);

        $this->get("/pay/{$token}")->assertOk()->assertInertia(fn ($page) => $page->component('Pay/Show')
            ->where('payment.amountMinor', 25000)->where('payment.currency', 'DOP')->where('payment.state', 'unpaid')
            ->has('gateways', 1)->where('gateways.0.key', 'fakepay')->where('business', $this->shop->name));

        $this->shop->plan->forceFill(['payment_gateways' => []])->save(); // the plan stops including it: it disappears from the page
        $this->get("/pay/{$token}")->assertInertia(fn ($page) => $page->has('gateways', 0));
    }

    public function test_an_unknown_expired_or_revoked_link_looks_the_same_a_404(): void
    {
        $order = $this->order();
        ['link' => $link, 'token' => $token] = app(PaymentLinks::class)->create($this->shop, $order, null);

        $this->get('/pay/'.str_repeat('z', 48))->assertNotFound();
        $link->forceFill(['revoked_at' => now()])->save();
        $this->get("/pay/{$token}")->assertNotFound();
        $this->post("/pay/{$token}/start", ['gateway' => 'fakepay', 'attempt' => 'a'])->assertNotFound();
    }

    public function test_starting_from_the_page_sends_the_customer_to_the_provider_and_ignores_any_amount_in_the_request(): void
    {
        $order = $this->order(null, '99.00', 'DOP');
        ['token' => $token] = app(PaymentLinks::class)->create($this->shop, $order, null);

        $this->post("/pay/{$token}/start", ['gateway' => 'fakepay', 'attempt' => 'page-1', 'amount' => 1, 'currency' => 'USD', 'workspace_id' => 999, 'order' => 999])
            ->assertOk()->assertInertia(fn ($page) => $page->component('Pay/Redirect')->where('action', 'https://pay.fake.test/authorize'));

        $transaction = PaymentTransaction::firstOrFail();
        $this->assertSame(9900, $transaction->amount_minor);
        $this->assertSame('DOP', $transaction->currency);
        $this->assertSame($this->shop->id, $transaction->workspace_id);
    }

    public function test_starting_with_a_method_that_is_not_offered_comes_back_with_a_clear_message(): void
    {
        $order = $this->order();
        ['token' => $token] = app(PaymentLinks::class)->create($this->shop, $order, null);

        $this->post("/pay/{$token}/start", ['gateway' => 'wompi', 'attempt' => 'x'])->assertRedirect("/pay/{$token}")->assertSessionHas('payment_error', 'Este método de pago no está disponible.');
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_the_status_page_reports_what_the_server_knows(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $token = $this->callbackToken();

        $this->get("/pay/status/{$token}")->assertInertia(fn ($page) => $page->component('Pay/Status')->where('payment.state', 'pending')->where('payment.open', true));

        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));
        $this->payments()->verify($transaction);
        $this->get("/pay/status/{$token}")->assertInertia(fn ($page) => $page->where('payment.state', 'approved')->where('payment.open', false));
    }

    private function callbackToken(): string
    {
        preg_match('#/api/payments/return/([A-Za-z0-9]{40})#', FakeGatewayAdapter::$context->returnUrl, $match);

        return $match[1];
    }

    // ───────────── staff: permission, isolation, plans ─────────────

    public function test_staff_with_the_permission_generate_a_link_and_the_url_is_shown_once(): void
    {
        $order = $this->order();
        $this->actAsMember($this->shop, 'admin');

        $response = $this->post(route('orders.payment-link.store', $order->id));

        $response->assertRedirect()->assertSessionHas('payment_link');
        $url = session('payment_link');
        $this->assertStringStartsWith(url('/pay/'), $url);
        $this->assertDatabaseMissing('payment_links', ['token_hash' => substr($url, -48)]);
        $this->assertSame(1, PaymentLink::count());
    }

    public function test_a_role_without_the_permission_cannot_generate_links(): void
    {
        $order = $this->order();
        $this->seedRoleCatalog();
        Role::findOrCreate('tienda', 'web')->syncPermissions(['view-orders', 'view-order-details']);
        $this->actAsMember($this->shop, 'tienda');

        $this->post(route('orders.payment-link.store', $order->id))->assertForbidden();
        $this->assertSame(0, PaymentLink::count());
    }

    public function test_staff_cannot_generate_a_link_for_the_order_of_another_workspace(): void
    {
        $foreign = $this->order($this->gatewayWorkspace('VECINA'));
        $this->actAsMember($this->shop, 'admin');

        $this->post(route('orders.payment-link.store', $foreign->id))->assertNotFound();
        $this->delete(route('orders.payment-link.destroy', $foreign->id))->assertNotFound();
    }

    public function test_no_link_is_offered_while_the_workspace_has_no_method_to_collect_with(): void
    {
        $order = $this->order();
        $this->shop->plan->forceFill(['payment_gateways' => []])->save();
        $this->actAsMember($this->shop->refresh(), 'admin');

        $this->post(route('orders.payment-link.store', $order->id))->assertSessionHas('error');
        $this->assertSame(0, PaymentLink::count());
    }

    public function test_the_plan_editor_saves_gateways_audits_them_and_only_the_platform_administration_can(): void
    {
        $dev = $this->bookingWorkspace('DESARROLLO_DEV', ['dashboard']);
        $plan = $this->shop->plan;
        $plan->forceFill(['payment_gateways' => null])->save();

        // A client's admin cannot reach the commercial administration at all.
        $this->actAsMember($this->shop);
        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'payment_gateways' => ['fakepay']])->assertForbidden();
        $this->assertNull($plan->fresh()->payment_gateways);

        $this->app['auth']->forgetGuards();
        $user = $this->actAsMember($dev, 'plataforma');
        $user->forceFill(['is_superuser' => true])->save();

        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'payment_gateways' => ['fakepay']])->assertSessionHasNoErrors();
        $this->assertSame(['fakepay'], $plan->fresh()->payment_gateways);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'plan', 'resource_id' => $plan->id]);

        // An unknown key is refused; a request that does not send the list changes nothing; an empty list removes every gateway.
        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'payment_gateways' => ['paypal']])->assertSessionHasErrors('payment_gateways.0');
        $this->put("/plans/{$plan->id}", ['name' => $plan->name])->assertSessionHasNoErrors();
        $this->assertSame(['fakepay'], $plan->fresh()->payment_gateways);
        $this->put("/plans/{$plan->id}", ['name' => $plan->name, 'payment_gateways' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $plan->fresh()->payment_gateways);
    }

    public function test_the_order_screen_shows_a_payment_state_derived_from_its_transactions(): void
    {
        $order = $this->order();
        $this->actAsMember($this->shop, 'admin');

        $this->get(route('orders.show', $order->id))->assertInertia(fn ($page) => $page->where('payment.state', 'unpaid')->where('payment.canCollect', true)->where('payment.methods.0', 'FakePay')->has('payment.transactions', 0));

        $transaction = $this->checkout($order)->transaction;
        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));
        $this->payments()->verify($transaction);

        $this->get(route('orders.show', $order->id))->assertInertia(fn ($page) => $page->where('payment.state', 'paid')->where('payment.canCollect', false)
            ->has('payment.transactions', 1)->where('payment.transactions.0.status', 'approved')->missing('payment.transactions.0.secrets')->missing('payment.transactions.0.callback_token_hash'));
    }

    // ───────────── the four clocks: a payment that may have been charged is never written off by time ─────────────

    public function test_a_payment_the_customer_returned_to_never_expires_by_time(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $token = $this->callbackToken();
        $this->answer($transaction, new GatewayResult(GatewayOutcome::Pending));
        $this->get("/api/payments/return/{$token}");
        $this->assertSame('processing', $transaction->refresh()->status);

        $this->travel(3)->hours(); // far beyond the 60 minutes the customer has to START
        $this->payments()->reconcile($transaction->id);

        $this->assertSame('processing', $transaction->refresh()->status);
        $this->assertNotNull($transaction->active_key); // still holds the order: it may be paid
    }

    public function test_an_abandoned_attempt_frees_the_order_but_keeps_watching_the_provider_session(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;
        $transaction->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->answer($transaction, new GatewayResult(GatewayOutcome::Pending));

        $this->payments()->reconcile($transaction->id);
        $transaction->refresh();

        $this->assertSame('expired', $transaction->status);
        $this->assertNull($transaction->active_key); // the order can be collected again
        $this->assertTrue($transaction->watch_until->isFuture());
        $this->assertTrue($transaction->watch_until->lt(now()->addHours(73))); // late_watch_hours = 72
        $this->assertNotNull($transaction->next_check_at);
    }

    public function test_a_customer_who_pays_after_the_attempt_expired_is_found_and_the_order_is_paid(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;
        $transaction->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->answer($transaction, new GatewayResult(GatewayOutcome::Pending));
        $this->payments()->reconcile($transaction->id);
        $this->assertSame('expired', $transaction->refresh()->status);

        $this->answer($transaction, FakeGatewayAdapter::approved($transaction)); // the customer paid at the provider after the window
        $transaction->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->assertSame([$transaction->id], $this->payments()->dueIds());
        $this->payments()->reconcile($transaction->id);

        $this->assertSame('approved', $transaction->refresh()->status);
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
    }

    public function test_the_late_watch_asks_less_and_less_and_ends_with_its_window(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $transaction->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->answer($transaction, new GatewayResult(GatewayOutcome::Pending));
        $this->payments()->reconcile($transaction->id);

        $gaps = [];

        for ($i = 0; $i < 4; $i++) {
            $transaction->refresh()->forceFill(['next_check_at' => now()->subMinute()])->save();
            $before = now();
            $this->payments()->reconcile($transaction->id);
            $gaps[] = (int) round($before->diffInMinutes($transaction->refresh()->next_check_at, true));
        }

        $this->assertSame([30, 60, 180, 360], $gaps); // 10 min after closing, then 30, 60, 180, 360
        $this->assertSame('expired', $transaction->status);

        $this->travel(73)->hours(); // past the 72 h window
        $this->assertSame([], $this->payments()->dueIds());
    }

    public function test_the_customer_coming_back_to_an_expired_attempt_triggers_a_check(): void
    {
        $transaction = $this->checkout($this->order())->transaction;
        $token = $this->callbackToken();
        $transaction->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->answer($transaction, new GatewayResult(GatewayOutcome::Pending));
        $this->payments()->reconcile($transaction->id);

        $this->answer($transaction, FakeGatewayAdapter::approved($transaction));
        $this->get("/api/payments/return/{$token}")->assertRedirect();

        $this->assertSame('approved', $transaction->refresh()->status);
    }

    public function test_an_attempt_replaced_by_another_is_still_watched_and_a_late_payment_is_flagged(): void
    {
        $order = $this->order();
        $first = $this->checkout($order, 'one')->transaction;
        $first->forceFill(['expires_at' => now()->subMinute()])->save(); // not reusable any more
        $second = $this->checkout($order, 'two')->transaction;

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('cancelled', $first->refresh()->status);
        $this->assertNotNull($first->watch_until);

        $this->answer($first, FakeGatewayAdapter::approved($first)); // the customer still paid the first one
        $first->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->payments()->reconcile($first->id);

        $this->assertSame('approved', $first->refresh()->status);
        $this->assertSame('cancelled', $second->refresh()->status); // the order is paid: the other attempt must not be paid again
        $this->assertNull($second->active_key);
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
    }

    public function test_a_second_attempt_already_at_the_provider_when_the_first_is_approved_raises_an_alert(): void
    {
        $order = $this->order();
        $first = $this->checkout($order, 'one')->transaction;
        $first->forceFill(['expires_at' => now()->subMinute()])->save();
        $second = $this->checkout($order, 'two')->transaction;
        $this->answer($second, new GatewayResult(GatewayOutcome::Pending));
        $this->payments()->apply($second, new GatewayResult(GatewayOutcome::Pending), 'query', 'server'); // the customer reached the provider with the second one

        $this->answer($first, FakeGatewayAdapter::approved($first));
        $this->payments()->verify($first);

        $this->assertContains('payment_duplicate_risk', $this->alerts->raised);
    }

    public function test_an_order_cancelled_with_a_pending_attempt_keeps_watching_that_attempt(): void
    {
        $order = $this->order();
        $transaction = $this->checkout($order)->transaction;

        app(OrderService::class)->transition($order, 'cancelled', null, 'Cliente desistió');

        $this->assertSame('cancelled', $transaction->refresh()->status);
        $this->assertNotNull($transaction->watch_until);
    }

    // ───────────── the destination the customer is sent to ─────────────

    public function test_the_customer_is_only_sent_to_a_declared_https_host(): void
    {
        foreach (['https://evil.example.com/authorize', 'https://user:pass@pay.fake.test/x', 'javascript:alert(1)', '//pay.fake.test/authorize', 'ftp://pay.fake.test/x'] as $i => $url) {
            FakeGatewayAdapter::$url = $url;
            $order = $this->order();

            $this->refused(fn () => $this->checkout($order, 'bad-'.$i), 'session_failed');
            $this->assertSame(0, PaymentTransaction::where('payable_id', $order->id)->whereNotNull('active_key')->count()); // the order stays free
        }
    }

    public function test_the_providers_hosts_are_added_to_the_form_action_of_the_content_security_policy(): void
    {
        Vite::useHotFile(storage_path('framework/testing/vite-hot-that-does-not-exist')); // no CSP is sent while a Vite dev server is running
        is_file(public_path('build/manifest.json')) || $this->markTestSkipped('The frontend build (npm run build) is needed to render the pages.');
        $this->assertContains('https://pay.fake.test', app(PaymentGatewayManager::class)->formHosts());
        $order = $this->order();
        ['token' => $token] = app(PaymentLinks::class)->create($this->shop, $order, null);

        $csp = $this->get("/pay/{$token}")->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('form-action', (string) $csp);
        $this->assertStringContainsString('https://pay.fake.test', (string) $csp);
    }
}
