<?php

namespace Tests\Feature;

use App\Integrations\IntegrationRegistry;
use App\Models\ApiCallLog;
use App\Models\Integration;
use App\Models\Order;
use App\Models\PaymentProviderSetting;
use App\Models\PaymentTransaction;
use App\Models\Workspace;
use App\Models\WorkspacePaymentGatewayOverride;
use App\Orders\OrderService;
use App\Payments\Gateways\GatewayAvailability;
use App\Payments\Gateways\GatewayOverrides;
use App\Payments\Gateways\PaymentException;
use App\Payments\Gateways\PaymentLinks;
use App\Payments\Gateways\PaymentService;
use App\Payments\Gateways\WompiEventToken;
use App\Payments\WompiConfig;
use App\Services\OperationalAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Wompi PER WORKSPACE, with Wompi's API and events SIMULATED (`Http::fake`, events signed in the test with a made-up secret): these prove Ava's own rules and the documented
 * formats, not a Sandbox run. They never touch the platform's own Wompi, and none of the keys or secrets below is real.
 */
class WompiWorkspaceTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const PUBLIC = 'pub_test_abcdefghij1234';

    private const INTEGRITY = 'test_integrity_Aa1Bb2Cc3Dd4';

    private const EVENTS = 'test_events_Zz9Yy8Xx7Ww6';

    private const FORM = ['environment' => 'sandbox', 'public_key' => self::PUBLIC, 'integrity_secret' => self::INTEGRITY, 'events_secret' => self::EVENTS];

    private Workspace $shop;

    private object $alerts;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:*' => Http::response('', 404)]);
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
        $this->shop = $this->colombianShop('TIENDA');
    }

    private function colombianShop(string $code, array $gateways = ['wompi']): Workspace
    {
        $workspace = $this->bookingWorkspace($code, ['dashboard', 'integrations', 'product_catalog', 'orders']);
        $workspace->plan->forceFill(['payment_gateways' => $gateways])->save();
        $workspace->settingsOrDefault()->update(['currency' => 'COP', 'tax_country' => 'CO']);

        return $workspace->refresh();
    }

    private function connect(Workspace $workspace, array $config = [], array $secrets = [], array $attributes = []): Integration
    {
        return $workspace->integrations()->create($attributes + [
            'name' => 'Wompi', 'provider' => 'wompi', 'type' => 'wompi', 'is_active' => true, 'last_test_ok' => true,
            'config' => $config + ['environment' => 'sandbox', 'public_key' => self::PUBLIC, 'events_salt' => 'salt-'.$workspace->code],
            'secrets' => $secrets + ['integrity_secret' => self::INTEGRITY, 'events_secret' => self::EVENTS],
        ]);
    }

    private function stored(?Workspace $workspace = null): ?Integration
    {
        return ($workspace ?? $this->shop)->integrations()->where('type', 'wompi')->first();
    }

    private function order(?Workspace $workspace = null, string $total = '50000.00', string $currency = 'COP'): Order
    {
        $workspace ??= $this->shop;

        return Order::create(['workspace_id' => $workspace->id, 'number' => (Order::where('workspace_id', $workspace->id)->max('number') ?? 0) + 1, 'customer_name' => 'Laura', 'customer_email' => 'laura@example.com',
            'currency' => $currency, 'total' => $total, 'status' => 'received']);
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function state(?Workspace $workspace = null): array
    {
        return app(GatewayAvailability::class)->describe(($workspace ?? $this->shop)->refresh(), 'wompi');
    }

    /** Starts a collection and returns the transaction and the plain return token Wompi will send the browser back to. */
    private function started(?Order $order = null, ?Workspace $workspace = null, string $attempt = 'a1'): array
    {
        $workspace ??= $this->shop;
        $result = $this->payments()->checkout($workspace, $order ?? $this->order($workspace), 'wompi', $attempt);
        parse_str((string) parse_url($result->checkout->url, PHP_URL_QUERY), $query);
        preg_match('#/api/payments/return/([A-Za-z0-9]{40})#', $query['redirect-url'], $match);

        return [$result->transaction, $match[1], $result->checkout->url, $query];
    }

    /** What Wompi's API answers for a transaction (`data` of `GET /v1/transactions/{id}`). */
    private function wompiTransaction(PaymentTransaction $transaction, string $status = 'APPROVED', array $override = []): array
    {
        return ['data' => $override + ['id' => '01-1700000000-12345', 'status' => $status, 'amount_in_cents' => $transaction->amount_minor, 'currency' => $transaction->currency, 'reference' => $transaction->provider_reference]];
    }

    /** Replaces what Wompi «answers»: a fresh fake each time, because the first matching stub of a factory always wins. */
    private function fakeApi(array $routes): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($routes + ['127.0.0.1:*' => Http::response('', 404)]);
    }

    /** A signed `transaction.updated` event exactly as Wompi documents it (checksum of the listed properties + timestamp + events secret). */
    private function event(array $transaction, string $secret = self::EVENTS, array $over = []): array
    {
        $timestamp = $over['timestamp'] ?? 1700000000;
        $payload = ['event' => 'transaction.updated', 'data' => ['transaction' => $transaction], 'environment' => 'test', 'timestamp' => $timestamp, 'sent_at' => '2026-11-06T12:00:00.000Z',
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => hash('sha256', $transaction['id'].$transaction['status'].$transaction['amount_in_cents'].$timestamp.$secret)]];

        return array_replace_recursive($payload, array_diff_key($over, ['timestamp' => 1]));
    }

    private function deliver(Integration $integration, array $payload, ?string $checksum = null)
    {
        return $this->postJson('/api/webhooks/payments/wompi/'.WompiEventToken::for($integration), $payload, ['X-Event-Checksum' => $checksum ?? $payload['signature']['checksum'] ?? '']);
    }

    private function eventOf(PaymentTransaction $transaction, string $status = 'APPROVED', string $id = '01-1700000000-12345'): array
    {
        return ['id' => $id, 'status' => $status, 'amount_in_cents' => $transaction->amount_minor, 'reference' => $transaction->provider_reference, 'currency' => 'COP'];
    }

    private function save(array $data = self::FORM)
    {
        return $this->put(route('integrations.payment-gateways.update', 'wompi'), $data);
    }

    // ───────────── registration and configuration ─────────────

    public function test_wompi_is_a_gateway_with_its_own_card_independent_of_the_platform_wompi(): void
    {
        $this->assertContains('wompi', array_keys(config('payments.gateways')));
        $this->assertNotContains('wompi', app(IntegrationRegistry::class)->keys());
        $this->actAsMember($this->shop, 'admin');

        $this->get(route('integrations.index'))->assertInertia(fn ($page) => $page->where('paymentGateways', fn ($gateways) => collect($gateways)->pluck('key')->contains('wompi'))
            ->where('paymentGateways.1.testable', true)->where('paymentGateways.1.state', 'not_configured'));
    }

    public function test_a_complete_configuration_is_saved_inactive_and_unvalidated_with_a_events_address(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save()->assertSessionHasNoErrors();

        $integration = $this->stored();
        $this->assertFalse($integration->is_active);
        $this->assertNull($integration->last_test_ok);
        $this->assertSame(self::PUBLIC, $integration->config['public_key']);
        $this->assertSame('saved', $this->state()['state']);
        $this->assertNotNull(WompiEventToken::for($integration));
        $this->assertSame($integration->id, WompiEventToken::resolve(WompiEventToken::for($integration))->id);

        $this->get(route('integrations.index'))->assertInertia(fn ($page) => $page->where('paymentGateways.1.integration.form.events_url', url('/api/webhooks/payments/wompi/'.WompiEventToken::for($integration))));
    }

    public function test_an_incomplete_configuration_can_be_saved_and_says_what_is_missing(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save(['environment' => 'sandbox', 'public_key' => self::PUBLIC])->assertSessionHasNoErrors();

        $this->assertSame('incomplete', $this->state()['state']);
        $this->assertEqualsCanonicalizing(['Falta el secreto de integridad.', 'Falta el secreto de eventos.'], $this->state()['reasons']);
    }

    public function test_invalid_values_and_keys_of_the_other_environment_are_rejected(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save([...self::FORM, 'public_key' => 'pk_123'])->assertSessionHasErrors('public_key');
        $this->save([...self::FORM, 'public_key' => 'pub_prod_abcdefghij1234'])->assertSessionHasErrors('public_key'); // production key in sandbox
        $this->save([...self::FORM, 'integrity_secret' => 'prod_integrity_Aa1Bb2Cc3Dd4'])->assertSessionHasErrors('integrity_secret');
        $this->save([...self::FORM, 'events_secret' => 'test_integrity_Aa1Bb2Cc3Dd4'])->assertSessionHasErrors('events_secret');
        $this->save([...self::FORM, 'environment' => 'staging'])->assertSessionHasErrors('environment');
        $this->save([...self::FORM, 'environment' => 'production'])->assertSessionHasErrors(['public_key', 'integrity_secret', 'events_secret']);
        $this->assertNull($this->stored());
    }

    public function test_changing_the_environment_never_mixes_the_stored_keys_of_the_other_one(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();

        $this->save(['environment' => 'production', 'public_key' => 'pub_prod_abcdefghij1234'])->assertSessionHasNoErrors();

        $state = $this->state();
        $this->assertSame('incomplete', $state['state']);
        $this->assertContains('El secreto de integridad no corresponde al ambiente elegido: vuelve a escribirlo.', $state['reasons']);
        $this->assertNull($this->stored()->last_test_ok);
    }

    public function test_the_secrets_are_encrypted_masked_and_absent_from_pages_and_audit(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();

        $raw = (string) DB::table('integrations')->where('id', $this->stored()->id)->value('secrets');
        $this->assertStringNotContainsString(self::INTEGRITY, $raw);
        $this->assertStringNotContainsString(self::EVENTS, $raw);
        $this->assertSame(self::INTEGRITY, $this->stored()->secrets['integrity_secret']);

        $page = $this->get(route('integrations.index'));
        $this->assertStringNotContainsString(self::INTEGRITY, $page->getContent());
        $this->assertStringNotContainsString(self::EVENTS, $page->getContent());
        $page->assertInertia(fn ($inertia) => $inertia->where('paymentGateways.1.integration.form.integrity_secret', '')->where('paymentGateways.1.integration.form.integrity_secret_set', true)
            ->where('paymentGateways.1.integration.form.integrity_secret_mask', '••••3Dd4'));
    }

    public function test_nothing_secret_reaches_the_audit_log(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();

        $audited = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode(' ');

        foreach ([self::INTEGRITY, self::EVENTS, 'Aa1Bb2Cc3Dd4', 'Zz9Yy8Xx7Ww6'] as $secret) {
            $this->assertStringNotContainsString($secret, $audited);
        }

        $this->assertStringContainsString('Secreto de eventos de Wompi (Workspace)', $audited);
    }

    public function test_a_blank_secret_keeps_the_stored_one_and_rotating_the_address_revokes_the_old_one(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $before = WompiEventToken::for($this->stored());

        $this->save(['environment' => 'sandbox', 'public_key' => '', 'integrity_secret' => '', 'events_secret' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::INTEGRITY, $this->stored()->secrets['integrity_secret']);
        $this->assertSame(self::PUBLIC, $this->stored()->config['public_key']);
        $this->assertSame($before, WompiEventToken::for($this->stored())); // saving does not move the address

        $this->save([...self::FORM, 'rotate_events_url' => 'yes'])->assertSessionHasNoErrors();
        $this->assertNotSame($before, WompiEventToken::for($this->stored()));
        $this->assertNull(WompiEventToken::resolve($before));
    }

    public function test_two_workspaces_keep_their_wompi_accounts_and_addresses_apart(): void
    {
        $other = $this->colombianShop('OTRA');
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->actAsMember($other, 'admin');
        $this->save([...self::FORM, 'public_key' => 'pub_test_zzzzzzzzzz9999'])->assertSessionHasNoErrors();

        $this->assertSame(self::PUBLIC, $this->stored($this->shop)->config['public_key']);
        $this->assertSame('pub_test_zzzzzzzzzz9999', $this->stored($other)->config['public_key']);
        $this->assertNotSame(WompiEventToken::for($this->stored($this->shop)), WompiEventToken::for($this->stored($other)));
        $this->assertSame($this->stored($this->shop)->id, WompiEventToken::resolve(WompiEventToken::for($this->stored($this->shop)))->id);
    }

    public function test_the_plan_and_the_administrative_exception_control_wompi(): void
    {
        $closed = $this->colombianShop('SINPLAN', []);
        $this->actAsMember($closed, 'admin');
        $this->save()->assertSessionHasErrors('gateway');
        $this->assertNull($this->stored($closed));
        $this->assertSame('plan_disabled', $this->state($closed)['state']);

        app(GatewayOverrides::class)->set($closed, 'wompi', WorkspacePaymentGatewayOverride::GRANT, 'Piloto Colombia', null);
        $this->save()->assertSessionHasNoErrors();
        $this->assertSame('saved', $this->state($closed)['state']); // permitted, but saved is not validated

        app(GatewayOverrides::class)->set($closed, 'wompi', WorkspacePaymentGatewayOverride::REVOKE, 'Contrato suspendido', null);
        $this->assertSame('admin_disabled', $this->state($closed)['state']);
    }

    // ───────────── the real connection test ─────────────

    public function test_the_connection_test_calls_wompi_and_only_a_success_validates(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true]);
        $this->fakeApi(['sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => ['id' => 1, 'name' => 'Comercio']], 200)]);

        $this->post(route('integrations.test', $this->stored()->id))->assertSessionHas('success');

        $this->assertTrue($this->stored()->last_test_ok);
        $this->assertSame('validated', $this->state()['state']);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.wompi.co/v1/merchants/'.self::PUBLIC && $request->method() === 'GET');
        $this->assertStringContainsString('no se pueden comprobar con la API', (string) $this->stored()->last_test_message); // the message says what was NOT proven
    }

    public function test_a_rejected_key_or_a_network_failure_never_validates(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true]);

        $this->fakeApi(['sandbox.wompi.co/*' => Http::response(['error' => 'x'], 404)]);
        $this->post(route('integrations.test', $this->stored()->id))->assertSessionHas('error');
        $this->assertFalse($this->stored()->last_test_ok);
        $this->assertSame('error', $this->state()['state']);

        $this->fakeApi(['sandbox.wompi.co/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $this->post(route('integrations.test', $this->stored()->id))->assertSessionHas('error');
        $this->assertSame('Wompi no respondió a tiempo.', $this->stored()->last_test_message);
        $this->assertFalse($this->stored()->last_test_ok);
    }

    public function test_the_test_is_refused_for_an_incomplete_configuration_without_calling_wompi(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save(['environment' => 'sandbox', 'public_key' => self::PUBLIC])->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true]);

        $this->post(route('integrations.test', $this->stored()->id))->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertFalse($this->stored()->last_test_ok);
    }

    public function test_changing_the_configuration_discards_the_validation(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['last_test_ok' => true, 'last_tested_at' => now()]);

        $this->save([...self::FORM, 'public_key' => 'pub_test_changedkey9999'])->assertSessionHasNoErrors();

        $this->assertNull($this->stored()->last_test_ok);
    }

    public function test_the_call_is_recorded_in_its_own_history_with_the_workspace_and_without_keys_or_bodies(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true]);
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response(['data' => ['id' => 1]], 200)]);

        $this->post(route('integrations.test', $this->stored()->id));

        $log = ApiCallLog::where('integration', 'wompi_workspace')->firstOrFail();
        $this->assertSame($this->shop->id, $log->workspace_id);
        $this->assertSame('get_merchant', $log->operation);
        $this->assertNull($log->request_body);
        $this->assertNull($log->response_body);
        $this->assertStringNotContainsString(self::INTEGRITY, json_encode($log->toArray()));
        $this->assertSame(0, ApiCallLog::where('integration', 'wompi')->count()); // the platform's history is untouched
    }

    // ───────────── the checkout ─────────────

    public function test_the_checkout_url_carries_the_server_amount_the_reference_and_the_documented_integrity_signature(): void
    {
        $this->connect($this->shop);
        $order = $this->order(null, '50000.00');

        [$transaction, , $url, $query] = $this->started($order);

        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        $this->assertSame(self::PUBLIC, $query['public-key']);
        $this->assertSame('COP', $query['currency']);
        $this->assertSame('5000000', $query['amount-in-cents']);
        $this->assertSame($transaction->provider_reference, $query['reference']);
        $this->assertSame(hash('sha256', $transaction->provider_reference.'5000000'.'COP'.self::INTEGRITY), $query['signature:integrity']);
        $this->assertArrayNotHasKey('expiration-time', $query); // its format could not be verified: not sent
        $this->assertStringNotContainsString(self::INTEGRITY, $url); // the secret signs, it never travels
        $this->assertSame(5000000, $transaction->amount_minor);
        $this->assertSame('redirect', $this->payments()->checkout($this->shop, $order, 'wompi', 'a1')->checkout->method);
    }

    public function test_a_second_visit_resumes_the_same_checkout_without_a_new_session(): void
    {
        $this->connect($this->shop);
        $order = $this->order();
        [$first, , $url] = $this->started($order);

        $again = $this->payments()->checkout($this->shop, $order, 'wompi', 'another-attempt');

        $this->assertFalse($again->created);
        $this->assertSame($first->id, $again->transaction->id);
        $this->assertSame($url, $again->checkout->url);
    }

    public function test_an_order_in_another_currency_is_not_collected_with_wompi(): void
    {
        $this->connect($this->shop);
        $order = $this->order(null, '100.00', 'DOP');

        try {
            $this->payments()->checkout($this->shop, $order, 'wompi', 'x');
            $this->fail('Wompi Colombia only collects COP.');
        } catch (PaymentException $exception) {
            $this->assertSame('gateway_unavailable', $exception->reason);
        }

        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_a_workspace_outside_colombia_does_not_get_wompi(): void
    {
        $this->connect($this->shop);
        $this->shop->settingsOrDefault()->update(['tax_country' => 'DO']);

        $this->assertSame('incompatible', $this->state()['state']);
    }

    public function test_the_page_offers_wompi_only_while_it_is_validated(): void
    {
        $integration = $this->connect($this->shop, attributes: ['last_test_ok' => null]);
        ['token' => $token] = app(PaymentLinks::class)->create($this->shop, $this->order(), null);

        $this->get("/pay/{$token}")->assertInertia(fn ($page) => $page->has('gateways', 0));

        $integration->update(['last_test_ok' => true]);
        $this->get("/pay/{$token}")->assertInertia(fn ($page) => $page->has('gateways', 1)->where('gateways.0.key', 'wompi'));
    }

    // ───────────── the customer's return: the browser proves nothing ─────────────

    public function test_returning_with_an_approved_looking_url_approves_nothing_until_wompi_confirms(): void
    {
        $this->connect($this->shop);
        [$transaction, $token] = $this->started();

        $this->get("/api/payments/return/{$token}?status=APPROVED&id=01-1700000000-12345&ResponseCode=00")->assertRedirect(route('payments.status', $token));
        // Wompi does not know that id: the browser's id is only a candidate and the answer is «nothing yet».
        $this->assertNotSame('approved', $transaction->refresh()->status);
    }

    public function test_the_candidate_id_is_verified_against_wompi_and_approves_only_when_everything_matches(): void
    {
        $this->connect($this->shop);
        [$transaction, $token] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/01-1700000000-12345' => Http::response($this->wompiTransaction($transaction), 200)]);

        $this->get("/api/payments/return/{$token}?id=01-1700000000-12345")->assertRedirect();

        $transaction->refresh();
        $this->assertSame('approved', $transaction->status);
        $this->assertSame('01-1700000000-12345', $transaction->external_id);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.wompi.co/v1/transactions/01-1700000000-12345' && $request->hasHeader('Authorization'));
    }

    public function test_an_id_that_belongs_to_another_reference_declines_nothing_and_approves_nothing(): void
    {
        $this->connect($this->shop);
        [$transaction, $token] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/*' => Http::response($this->wompiTransaction($transaction, 'DECLINED', ['reference' => 'SOMEONE-ELSES-ORDER']), 200)]);

        $this->get("/api/payments/return/{$token}?id=01-1700000000-99999");

        $this->assertSame('processing', $transaction->refresh()->status); // the customer did come back, but a forged id cannot reject (or approve) this payment
        $this->assertNull($transaction->external_id);
    }

    public function test_an_approval_of_another_amount_or_currency_is_never_believed(): void
    {
        $this->connect($this->shop);
        [$amount, $amountToken] = $this->started($this->order(), attempt: 'a');
        [$currency, $currencyToken] = $this->started($this->order(), attempt: 'b');
        $this->fakeApi([
            'sandbox.wompi.co/v1/transactions/ID-AMOUNT' => Http::response($this->wompiTransaction($amount, 'APPROVED', ['id' => 'ID-AMOUNT', 'amount_in_cents' => 100]), 200),
            'sandbox.wompi.co/v1/transactions/ID-CURRENCY' => Http::response($this->wompiTransaction($currency, 'APPROVED', ['id' => 'ID-CURRENCY', 'currency' => 'USD']), 200),
        ]);

        $this->get("/api/payments/return/{$amountToken}?id=ID-AMOUNT");
        $this->get("/api/payments/return/{$currencyToken}?id=ID-CURRENCY");

        $this->assertSame('amount_mismatch', $amount->refresh()->failure_code);
        $this->assertSame('currency_mismatch', $currency->refresh()->failure_code);
        $this->assertSame(0, PaymentTransaction::where('status', 'approved')->count());
    }

    // ───────────── the events ─────────────

    public function test_a_signed_event_is_confirmed_with_wompi_and_pays_the_order(): void
    {
        $integration = $this->connect($this->shop);
        $order = $this->order();
        [$transaction] = $this->started($order);
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/01-1700000000-12345' => Http::response($this->wompiTransaction($transaction), 200)]);

        $this->deliver($integration, $this->event($this->eventOf($transaction)))->assertOk();

        $this->assertSame('approved', $transaction->refresh()->status);
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
        $this->assertDatabaseHas('payment_transaction_events', ['payment_transaction_id' => $transaction->id, 'kind' => 'notification', 'source' => 'provider', 'to_status' => 'approved']);
    }

    public function test_the_event_alone_is_not_enough_wompi_decides(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        // The (signed) event says APPROVED; Wompi's API says the transaction was DECLINED: the API wins.
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/*' => Http::response($this->wompiTransaction($transaction, 'DECLINED'), 200)]);

        $this->deliver($integration, $this->event($this->eventOf($transaction, 'APPROVED')))->assertOk();

        $this->assertSame('declined', $transaction->refresh()->status);
    }

    public function test_an_invalid_signature_a_forged_checksum_or_a_wrong_secret_is_rejected_and_changes_nothing(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $event = $this->event($this->eventOf($transaction));

        $this->deliver($integration, $this->event($this->eventOf($transaction), 'test_events_AnotherSecret999'))->assertStatus(400); // signed with another secret
        $tampered = $event;
        $tampered['data']['transaction']['amount_in_cents'] = 1; // the amount changed after signing
        $this->deliver($integration, $tampered)->assertStatus(400);
        $this->deliver($integration, $event, str_repeat('a', 64))->assertStatus(400); // header does not match
        $unsigned = $event;
        unset($unsigned['signature']);
        $this->deliver($integration, $unsigned, 'x')->assertStatus(400);

        $this->assertSame('pending', $transaction->refresh()->status);
        $this->assertContains('wompi_workspace_event_invalid_signature', $this->alerts->raised);
        Http::assertNothingSent();
    }

    public function test_an_unknown_token_is_a_404_and_the_token_of_one_connection_never_serves_another(): void
    {
        $integration = $this->connect($this->shop);
        $other = $this->colombianShop('OTRA');
        $foreign = $this->connect($other, ['events_salt' => 'otra-sal'], ['events_secret' => 'test_events_OtherSecret123']);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $event = $this->event($this->eventOf($transaction));

        $this->postJson('/api/webhooks/payments/wompi/1.'.str_repeat('a', 40), $event)->assertNotFound();
        $this->postJson('/api/webhooks/payments/wompi/not-a-token', $event)->assertNotFound();
        $this->postJson('/api/webhooks/payments/wompi/'.$integration->id.'.'.str_repeat('0', 40), $event)->assertNotFound();

        // The other Workspace's address with THIS Workspace's valid event: its secret differs (400); and even with its own secret the reference is not in its Workspace (OK, nothing).
        $this->deliver($foreign, $event)->assertStatus(400);
        $this->deliver($foreign, $this->event($this->eventOf($transaction), 'test_events_OtherSecret123'))->assertOk();
        $this->assertSame('pending', $transaction->refresh()->status);
        $this->assertContains('wompi_workspace_event_unknown_reference', $this->alerts->raised);
    }

    public function test_an_event_of_the_other_environment_is_ignored(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);

        $this->deliver($integration, $this->event($this->eventOf($transaction), self::EVENTS, ['environment' => 'prod']))->assertOk();

        $this->assertSame('pending', $transaction->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_unknown_reference_is_acknowledged_without_creating_anything(): void
    {
        $integration = $this->connect($this->shop);
        $stranger = ['id' => 'X-1', 'status' => 'APPROVED', 'amount_in_cents' => 100, 'reference' => 'NOT-OURS', 'currency' => 'COP'];

        $this->deliver($integration, $this->event($stranger))->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
        Http::assertNothingSent();
    }

    public function test_when_wompi_cannot_be_asked_the_answer_is_a_retry_and_the_payment_is_never_rejected(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $event = $this->event($this->eventOf($transaction));

        $this->fakeApi(['sandbox.wompi.co/*' => fn () => throw new ConnectionException('cURL error 7: refused')]);
        $this->deliver($integration, $event)->assertStatus(503);
        $this->assertSame('verification_required', $transaction->refresh()->status);

        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $this->deliver($integration, $event)->assertOk(); // Wompi sends the SAME event again: the failed attempt did not consume it
        $this->assertSame('approved', $transaction->refresh()->status);
    }

    public function test_the_same_event_twice_is_applied_once(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $event = $this->event($this->eventOf($transaction));

        $this->deliver($integration, $event)->assertOk();
        $this->deliver($integration, $event)->assertOk();

        $this->assertSame(1, DB::table('payment_transaction_events')->where('payment_transaction_id', $transaction->id)->where('to_status', 'approved')->count());
        $this->assertSame(1, DB::table('payment_transaction_events')->where('payment_transaction_id', $transaction->id)->where('outcome', 'duplicate')->count());
    }

    public function test_events_out_of_order_never_undo_an_approval(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'APPROVED')))->assertOk();

        // The older PENDING event arrives late; Wompi's API still says APPROVED.
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'PENDING'), self::EVENTS, ['timestamp' => 1699999000]))->assertOk();
        // And a stale API answer cannot undo it either.
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction, 'PENDING'), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'PENDING'), self::EVENTS, ['timestamp' => 1699999100]))->assertOk();

        $this->assertSame('approved', $transaction->refresh()->status);
    }

    public function test_an_event_whose_transaction_belongs_to_another_reference_is_dropped_with_an_alert(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction, 'APPROVED', ['reference' => 'ANOTHER-ORDER']), 200)]);

        $this->deliver($integration, $this->event($this->eventOf($transaction)))->assertOk();

        $this->assertSame('pending', $transaction->refresh()->status);
        $this->assertContains('wompi_workspace_event_reference_mismatch', $this->alerts->raised);
    }

    public function test_a_rejected_payment_then_an_approved_retry_in_the_same_checkout_is_paid(): void
    {
        $integration = $this->connect($this->shop);
        $order = $this->order();
        [$transaction] = $this->started($order);

        $this->fakeApi(['sandbox.wompi.co/v1/transactions/FIRST' => Http::response($this->wompiTransaction($transaction, 'DECLINED', ['id' => 'FIRST']), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'DECLINED', 'FIRST')))->assertOk();
        $this->assertSame('declined', $transaction->refresh()->status);

        // The customer tries again inside the same Wompi checkout (same reference, new Wompi transaction) and pays.
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/SECOND' => Http::response($this->wompiTransaction($transaction, 'APPROVED', ['id' => 'SECOND']), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'APPROVED', 'SECOND')))->assertOk();

        $this->assertSame('approved', $transaction->refresh()->status);
        $this->assertSame('SECOND', $transaction->external_id);
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
    }

    // ───────────── late payments, duplicates, returns ─────────────

    public function test_a_payment_after_ava_expired_the_attempt_is_still_found_by_the_event_even_past_the_watch(): void
    {
        $integration = $this->connect($this->shop);
        $order = $this->order();
        [$transaction] = $this->started($order);
        $transaction->forceFill(['status' => 'expired', 'active_key' => null, 'watch_until' => now()->subDay(), 'next_check_at' => null])->save(); // closed by Ava; the watch is over
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);

        $this->deliver($integration, $this->event($this->eventOf($transaction)))->assertOk();

        $this->assertSame('approved', $transaction->refresh()->status);
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
    }

    public function test_two_approved_attempts_of_one_order_are_both_kept_flagged_and_alerted(): void
    {
        $integration = $this->connect($this->shop);
        $order = $this->order();
        [$first] = $this->started($order, attempt: 'one');
        $first->forceFill(['expires_at' => now()->subMinute()])->save();
        [$second] = $this->started($order, attempt: 'two');
        $this->assertNotSame($first->id, $second->id);

        $this->fakeApi([
            'sandbox.wompi.co/v1/transactions/PAY-1' => Http::response($this->wompiTransaction($first, 'APPROVED', ['id' => 'PAY-1']), 200),
            'sandbox.wompi.co/v1/transactions/PAY-2' => Http::response($this->wompiTransaction($second, 'APPROVED', ['id' => 'PAY-2']), 200),
        ]);
        $this->deliver($integration, $this->event($this->eventOf($first, 'APPROVED', 'PAY-1')))->assertOk();
        $this->deliver($integration, $this->event($this->eventOf($second, 'APPROVED', 'PAY-2'), self::EVENTS, ['timestamp' => 1700000500]))->assertOk();

        $this->assertSame('approved', $first->refresh()->status);
        $this->assertSame('approved', $second->refresh()->status);
        $this->assertSame('duplicate_payment', $second->failure_code); // the later one is flagged; neither is lost
        $this->assertNull($first->failure_code);
        $this->assertContains('payment_duplicate_approved', $this->alerts->raised);
        $this->assertSame('duplicate', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
    }

    public function test_a_return_made_in_wompis_own_portal_marks_the_payment_refunded_and_frees_the_order_to_cancel(): void
    {
        $integration = $this->connect($this->shop);
        $order = $this->order();
        [$transaction] = $this->started($order);
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction)))->assertOk();
        $this->assertSame('paid', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);

        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction, 'VOIDED'), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'VOIDED'), self::EVENTS, ['timestamp' => 1700009000]))->assertOk();

        $this->assertSame('refunded', $transaction->refresh()->status);
        $this->assertSame('refunded', $this->payments()->summary($this->shop->id, 'order', $order->id)['state']);
        $this->assertContains('payment_returned_by_provider', $this->alerts->raised);
        app(OrderService::class)->transition($order, 'cancelled', null, 'Devuelto en Wompi'); // no payment blocks it any more
    }

    public function test_a_voided_payment_ava_never_saw_approved_is_recorded_as_approved_then_refunded_only_if_it_matches(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction, 'VOIDED', ['amount_in_cents' => 1]), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'VOIDED')))->assertOk();
        $this->assertSame('pending', $transaction->refresh()->status); // the amount does not match: nothing is believed

        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction, 'VOIDED'), 200)]);
        $this->deliver($integration, $this->event($this->eventOf($transaction, 'VOIDED'), self::EVENTS, ['timestamp' => 1700000100]))->assertOk();

        $this->assertSame('refunded', $transaction->refresh()->status);
        $this->assertSame(['approved', 'refunded'], DB::table('payment_transaction_events')->where('payment_transaction_id', $transaction->id)->whereIn('to_status', ['approved', 'refunded'])->orderBy('id')->pluck('to_status')->all());
    }

    // ───────────── reconciliation ─────────────

    public function test_with_nothing_known_at_wompi_the_attempt_stays_pending_and_expires_without_being_rejected(): void
    {
        $this->connect($this->shop);
        [$transaction] = $this->started();

        $this->payments()->verify($transaction); // no id, no event: «not started»
        $this->assertSame('pending', $transaction->refresh()->status); // not promoted to «processing»
        Http::assertNothingSent();

        $transaction->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->payments()->reconcile($transaction->id);

        $this->assertSame('expired', $transaction->refresh()->status);
        $this->assertNotNull($transaction->watch_until);
    }

    public function test_reconciliation_confirms_a_payment_through_the_recorded_id_when_no_event_came(): void
    {
        $this->connect($this->shop);
        [$transaction, $token] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/KNOWN-1' => Http::response($this->wompiTransaction($transaction, 'PENDING', ['id' => 'KNOWN-1']), 200)]);
        $this->get("/api/payments/return/{$token}?id=KNOWN-1");
        $this->assertSame('processing', $transaction->refresh()->status);

        $this->fakeApi(['sandbox.wompi.co/v1/transactions/KNOWN-1' => Http::response($this->wompiTransaction($transaction, 'APPROVED', ['id' => 'KNOWN-1']), 200)]);
        $transaction->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->payments()->reconcile($transaction->id);

        $this->assertSame('approved', $transaction->refresh()->status);
    }

    public function test_a_wompi_outage_during_reconciliation_leaves_the_payment_in_verification(): void
    {
        $this->connect($this->shop);
        [$transaction, $token] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction, 'PENDING', ['id' => 'KNOWN-2']), 200)]);
        $this->get("/api/payments/return/{$token}?id=KNOWN-2");

        $this->fakeApi(['sandbox.wompi.co/*' => Http::response('', 503)]);
        $this->payments()->verify($transaction->refresh());

        $this->assertSame('verification_required', $transaction->refresh()->status);
        $this->assertNotSame('declined', $transaction->status);
        $this->assertNotNull($transaction->next_check_at);
    }

    public function test_staff_can_verify_any_attempt_again_even_after_the_watch_and_only_inside_their_workspace(): void
    {
        $this->connect($this->shop);
        $order = $this->order();
        [$transaction] = $this->started($order);
        $transaction->forceFill(['status' => 'expired', 'active_key' => null, 'watch_until' => now()->subDays(2), 'next_check_at' => null, 'external_id' => 'LATE-1'])->save();
        $this->fakeApi(['sandbox.wompi.co/v1/transactions/LATE-1' => Http::response($this->wompiTransaction($transaction, 'APPROVED', ['id' => 'LATE-1']), 200)]);
        $this->actAsMember($this->shop, 'admin');

        $this->post(route('orders.payments.verify', [$order->id, $transaction->id]))->assertSessionHas('success');
        $this->assertSame('approved', $transaction->refresh()->status);

        $foreignShop = $this->colombianShop('AJENA');
        $this->connect($foreignShop);
        [$foreign] = $this->started($this->order($foreignShop), $foreignShop);
        $this->post(route('orders.payments.verify', [$order->id, $foreign->id]))->assertNotFound(); // another Workspace's attempt through this order
        $this->post(route('orders.payments.verify', [$this->order($foreignShop)->id, $foreign->id]))->assertNotFound();
    }

    public function test_verifying_again_needs_the_collect_permission(): void
    {
        $this->connect($this->shop);
        $order = $this->order();
        [$transaction] = $this->started($order);
        Role::findOrCreate('tienda', 'web')->syncPermissions(['view-orders', 'view-order-details']);
        $this->actAsMember($this->shop, 'tienda');

        $this->post(route('orders.payments.verify', [$order->id, $transaction->id]))->assertForbidden();
    }

    // ───────────── total separation from the platform's own Wompi ─────────────

    public function test_the_platform_wompi_is_untouched_and_unaware_of_workspace_connections(): void
    {
        PaymentProviderSetting::create(['provider' => 'wompi', 'environment' => 'sandbox', 'is_active' => true, 'public_key' => 'pub_test_PLATFORMKEY1234', 'events_secret' => 'test_events_PlatformSecret1', 'integrity_secret' => 'test_integrity_PlatformSecret1']);
        $this->connect($this->shop);

        $config = app(WompiConfig::class);
        $this->assertSame('pub_test_PLATFORMKEY1234', $config->publicKey());
        $this->assertSame('test_events_PlatformSecret1', $config->eventsSecret());
        $this->assertSame('database', $config->source());

        $this->assertSame(1, PaymentProviderSetting::count());
        $this->assertSame(0, DB::table('payment_events')->count());
    }

    public function test_a_workspace_event_sent_to_the_platform_address_does_nothing_and_the_other_way_round(): void
    {
        $integration = $this->connect($this->shop);
        [$transaction] = $this->started();
        $this->fakeApi(['sandbox.wompi.co/*' => Http::response($this->wompiTransaction($transaction), 200)]);
        $event = $this->event($this->eventOf($transaction));

        // To the platform's address: its own secret does not match (400) and nothing in the platform's tables appears.
        $this->postJson('/api/webhooks/wompi', $event, ['X-Event-Checksum' => $event['signature']['checksum']])->assertStatus(400);
        $this->assertSame('pending', $transaction->refresh()->status);
        $this->assertSame(0, DB::table('payment_events')->count());

        // A platform-style event to the Workspace address: the reference is not a payment of that connection.
        $platformEvent = $this->event(['id' => 'P-1', 'status' => 'APPROVED', 'amount_in_cents' => 100, 'reference' => 'SIGNUP-PUBLIC-ID', 'currency' => 'COP']);
        $this->deliver($integration, $platformEvent)->assertOk();
        $this->assertSame(0, DB::table('payment_events')->count());
        $this->assertSame(1, PaymentTransaction::count());
    }
}
