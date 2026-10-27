<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\SignupRequest;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Payments\PaymentGateway;
use App\Payments\UnconfiguredPaymentGateway;
use App\Payments\WompiPaymentGateway;
use App\Services\ModuleAccess;
use App\Services\SignupService;
use App\Support\Nit;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Wompi against the public sign-up. Unit/integration tests with Wompi's responses SIMULATED (Http::fake): no request leaves the machine, no payment is made
 * and the keys below are fictitious. Nothing here was run against Wompi Sandbox.
 */
class WompiPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ana@empresa.co';

    private const EVENTS_SECRET = 'test_events_fake';

    private const INTEGRITY = 'test_integrity_fake';

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['wompi.environment' => 'sandbox', 'wompi.public_key' => 'pub_test_fake', 'wompi.integrity_secret' => self::INTEGRITY, 'wompi.events_secret' => self::EVENTS_SECRET]);
        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $health = ModuleCategory::where('key', 'health')->firstOrFail();
        $health->update(['is_public' => true]);
        $this->starter = Plan::create(['name' => 'Starter', 'description' => 'Plan', 'module_category_id' => $health->id, 'price_monthly_cents' => 4990000, 'currency' => 'COP', 'is_public' => true]);
        $this->starter->modules()->sync(Module::whereIn('key', ['dashboard', 'reports'])->pluck('id')->all());
    }

    /** A contract waiting for its payment, reached through the real wizard. */
    private function awaitingPayment(?Plan $plan = null, string $email = self::EMAIL): SignupRequest
    {
        $plan ??= $this->starter;
        $this->post('/registro/correo', ['email' => $email]);
        $code = Mail::sent(SignupCodeMail::class)->last()->code;
        $this->post('/registro/codigo', ['code' => $code]);
        $body = '900123456';
        $this->post('/registro/empresa', [
            'admin_name' => 'Ana Pérez', 'company_name' => 'Clínica Central', 'tax_id' => $body.'-'.Nit::checkDigit($body), 'phone' => '+57 300 111 2233',
            'workspace_code' => 'clinica-central', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ])->assertSessionHasNoErrors();
        $this->post('/registro/solucion', ['solution' => $plan->module_category_id ? (string) $plan->module_category_id : 'custom']);
        $this->post('/registro/plan', ['plan_id' => $plan->id])->assertSessionHasNoErrors();

        return SignupRequest::where('email', $email)->latest('id')->firstOrFail();
    }

    private function contract(): void
    {
        $documents = array_keys(app(SignupService::class)->requiredDocuments());
        $this->post('/registro/contratar', ['accepted' => $documents])->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> the body Wompi posts, signed like Wompi does (properties, timestamp, events secret). */
    private function event(SignupRequest $request, string $status = 'APPROVED', string $tx = '1234-1-1', int $cents = 4990000, string $environment = 'test', ?string $secret = null): array
    {
        $timestamp = 1761000000;
        $checksum = hash('sha256', $tx.$status.$cents.$timestamp.($secret ?? self::EVENTS_SECRET));

        return [
            'event' => 'transaction.updated', 'environment' => $environment, 'timestamp' => $timestamp, 'sent_at' => '2026-10-27T10:00:00.000Z',
            'data' => ['transaction' => ['id' => $tx, 'status' => $status, 'amount_in_cents' => $cents, 'reference' => $request->public_id, 'currency' => 'COP']],
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => $checksum],
        ];
    }

    private function wompiSays(SignupRequest $request, string $status = 'APPROVED', string $tx = '1234-1-1', int $cents = 4990000, string $currency = 'COP'): void
    {
        $this->fakeWompi(Http::response(['data' => ['id' => $tx, 'status' => $status, 'amount_in_cents' => $cents, 'currency' => $currency, 'reference' => $request->public_id]]));
    }

    /** Replaces (not appends to) the simulated answers of Wompi: Http::fake keeps the first matching stub. */
    private function fakeWompi(mixed $answer): void
    {
        Http::swap(new Factory);
        Http::fake(['sandbox.wompi.co/*' => $answer]);
    }

    private function send(array $payload, ?string $header = null)
    {
        return $this->postJson('/api/webhooks/wompi', $payload, ['X-Event-Checksum' => $header ?? $payload['signature']['checksum']]);
    }

    // --- checkout ---------------------------------------------------------------------------------------------------

    public function test_the_checkout_url_is_built_on_the_server_with_the_stored_price_and_the_integrity_signature(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();
        $url = app(PaymentGateway::class)->checkoutUrl($request->fresh());

        $this->assertInstanceOf(WompiPaymentGateway::class, app(PaymentGateway::class));
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('pub_test_fake', $query['public-key']);
        $this->assertSame('COP', $query['currency']);
        $this->assertSame('4990000', $query['amount-in-cents']); // 49.900 COP: the stored centavos, not multiplied again
        $this->assertSame($request->public_id, $query['reference']);
        $this->assertSame(hash('sha256', $request->public_id.'4990000COP'.self::INTEGRITY), $query['signature:integrity']);
        $this->assertSame(route('signup.index'), $query['redirect-url']);
        $this->assertStringNotContainsString(self::INTEGRITY, $url);
        $this->assertStringNotContainsString(self::EVENTS_SECRET, $url);
        $this->assertSame('awaiting_payment', $request->fresh()->status);
    }

    public function test_the_contract_step_redirects_to_the_checkout_and_the_browser_can_not_choose_the_amount(): void
    {
        $request = $this->awaitingPayment();
        $documents = array_keys(app(SignupService::class)->requiredDocuments());

        $response = $this->post('/registro/contratar', ['accepted' => $documents, 'amount' => 1, 'price_cents' => 1, 'reference' => 'x']);

        $response->assertRedirectContains('checkout.wompi.co/p/');
        $this->assertStringContainsString('amount-in-cents=4990000', $response->headers->get('Location'));
        $this->assertStringContainsString('reference='.$request->public_id, $response->headers->get('Location'));
    }

    public function test_without_complete_keys_or_with_a_key_of_the_other_environment_there_is_no_payment(): void
    {
        config(['wompi.environment' => 'production']); // sandbox key in production
        $this->assertInstanceOf(UnconfiguredPaymentGateway::class, app(PaymentGateway::class));

        config(['wompi.environment' => 'sandbox', 'wompi.events_secret' => null]);
        $this->assertFalse(app(PaymentGateway::class)->isConfigured());

        config(['wompi.environment' => 'production', 'wompi.public_key' => 'pub_prod_fake', 'wompi.events_secret' => 'x']);
        $this->assertTrue(app(PaymentGateway::class)->isConfigured());
    }

    public function test_a_user_who_comes_back_without_paying_sees_a_pending_state_and_nothing_is_activated(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();

        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'payment')->where('payment.status', 'awaiting_payment')->where('payment.paid', false)->where('payment.available', true));
        $this->assertSame(0, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->get('/registro/bienvenida')->assertRedirect(route('signup.index'));
        $this->post('/registro/pagar')->assertRedirectContains('checkout.wompi.co/p/'); // same reference, no new request
        $this->assertSame(1, SignupRequest::count());
        $this->assertNull($request->fresh()->paid_at);
    }

    // --- events -----------------------------------------------------------------------------------------------------

    public function test_an_approved_event_verified_against_wompi_activates_workspace_subscription_and_modules_once(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();
        $this->wompiSays($request);

        $this->send($this->event($request))->assertOk();
        $this->send($this->event($request))->assertOk(); // Wompi repeats events

        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertSame('1234-1-1', $request->payment_reference);
        $this->assertSame(1, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->assertSame(1, Subscription::where('workspace_id', $request->workspace_id)->count());
        $this->assertSame(Subscription::ACTIVE, Subscription::where('workspace_id', $request->workspace_id)->value('status'));
        $this->assertEqualsCanonicalizing(['dashboard', 'reports'], app(ModuleAccess::class)->keys(Workspace::findOrFail($request->workspace_id)));
        $this->assertSame(['confirmed', 'confirmed'], PaymentEvent::orderBy('id')->pluck('outcome')->all());
    }

    public function test_pending_declined_voided_and_error_transactions_activate_nothing(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();

        foreach (['PENDING', 'DECLINED', 'VOIDED', 'ERROR'] as $status) {
            $this->wompiSays($request, $status);
            $this->send($this->event($request, $status))->assertOk();
        }

        $this->assertSame('awaiting_payment', $request->fresh()->status);
        $this->assertNull($request->fresh()->paid_at);
        $this->assertSame(0, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->assertSame(4, PaymentEvent::where('outcome', 'not_approved')->count());
    }

    public function test_an_event_that_claims_approval_is_not_trusted_when_wompi_says_otherwise_and_a_late_pending_event_cannot_undo_an_approval(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();

        $this->wompiSays($request, 'PENDING');
        $this->send($this->event($request, 'APPROVED'))->assertOk(); // the event lies / is early: the API answer rules
        $this->assertSame('awaiting_payment', $request->fresh()->status);

        $this->wompiSays($request, 'APPROVED');
        $this->send($this->event($request, 'APPROVED'))->assertOk();
        $this->send($this->event($request, 'PENDING'))->assertOk(); // arrives late, out of order
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(1, Subscription::count());
    }

    public function test_an_event_with_a_bad_checksum_or_signed_with_another_secret_is_refused_and_changes_nothing(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();
        Http::fake();

        $this->send($this->event($request, secret: 'someone_elses_secret'))->assertStatus(400);
        $this->send($this->event($request), str_repeat('a', 64))->assertStatus(400); // header does not match
        $tampered = $this->event($request);
        $tampered['data']['transaction']['amount_in_cents'] = 1;
        $this->send($tampered)->assertStatus(400);
        $unsigned = $this->event($request);
        unset($unsigned['signature']);
        $this->postJson('/api/webhooks/wompi', $unsigned)->assertStatus(400);

        Http::assertNothingSent();
        $this->assertSame(0, PaymentEvent::count());
        $this->assertSame('awaiting_payment', $request->fresh()->status);
    }

    public function test_an_event_of_the_other_environment_is_ignored(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();
        Http::fake();

        $this->send($this->event($request, environment: 'prod'))->assertOk();

        Http::assertNothingSent();
        $this->assertSame('awaiting_payment', $request->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_an_unknown_reference_is_recorded_and_activates_nothing(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();
        $stranger = new SignupRequest(['public_id' => '00000000-0000-4000-8000-000000000000']);
        Http::fake();

        $this->send($this->event($stranger))->assertOk();

        Http::assertNothingSent();
        $this->assertSame('unknown_reference', PaymentEvent::firstOrFail()->outcome);
        $this->assertSame('awaiting_payment', $request->fresh()->status);
    }

    public function test_a_different_amount_or_currency_confirmed_by_wompi_is_rejected(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();

        $this->wompiSays($request, 'APPROVED', cents: 100);
        $this->send($this->event($request, cents: 100))->assertOk();
        $this->wompiSays($request, 'APPROVED', currency: 'USD');
        $this->send($this->event($request))->assertOk();

        $this->assertSame('awaiting_payment', $request->fresh()->status);
        $this->assertNull($request->fresh()->paid_at);
        $this->assertSame(0, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->assertSame(['rejected', 'rejected'], PaymentEvent::orderBy('id')->pluck('outcome')->all());
    }

    public function test_when_wompi_can_not_be_asked_the_answer_asks_for_a_retry_and_the_retry_completes_it(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();

        $this->fakeWompi(Http::response('boom', 500));
        $this->send($this->event($request))->assertStatus(503);
        $this->assertSame('awaiting_payment', $request->fresh()->status);

        $this->fakeWompi(fn () => throw new ConnectionException('down'));
        $this->send($this->event($request))->assertStatus(503);

        $this->wompiSays($request);
        $this->send($this->event($request))->assertOk();
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(1, Workspace::where('code', 'CLINICA-CENTRAL')->count());
    }

    public function test_a_transaction_of_another_reference_returned_by_wompi_is_not_applied(): void
    {
        $request = $this->awaitingPayment();
        $this->contract();
        $this->fakeWompi(Http::response(['data' => ['id' => '1234-1-1', 'status' => 'APPROVED', 'amount_in_cents' => 4990000, 'currency' => 'COP', 'reference' => 'other']]));

        $this->send($this->event($request))->assertOk();

        $this->assertSame('awaiting_payment', $request->fresh()->status);
        $this->assertSame('reference_mismatch', PaymentEvent::firstOrFail()->outcome);
    }

    public function test_a_quote_plan_is_never_charged_and_a_payment_event_for_it_activates_nothing(): void
    {
        $custom = Plan::create(['name' => 'Custom', 'description' => 'A tu medida', 'is_public' => true, 'requires_quote' => true]);
        $custom->modules()->sync(Module::whereIn('key', ['dashboard'])->pluck('id')->all());
        $request = $this->awaitingPayment($custom);
        $this->post('/registro/cotizacion')->assertSessionHasNoErrors();
        $this->assertSame('quote_requested', $request->fresh()->status);

        $this->wompiSays($request, cents: 4990000);
        $this->send($this->event($request))->assertOk();

        $this->assertSame('quote_requested', $request->fresh()->status);
        $this->assertSame(0, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->assertNull($request->fresh()->paid_at);
    }

    public function test_the_platform_development_workspace_keeps_its_modules_and_nothing_else_changes_when_a_customer_pays(): void
    {
        $dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $before = collect(app(ModuleAccess::class)->keys($dev))->sort()->values()->all();
        $request = $this->awaitingPayment();
        $this->contract();
        $this->wompiSays($request);

        $this->send($this->event($request))->assertOk();

        $this->assertSame($before, collect(app(ModuleAccess::class)->keys($dev->fresh()))->sort()->values()->all());
        $this->assertSame(0, Subscription::where('workspace_id', $dev->id)->count());
    }

    public function test_the_secrets_never_appear_in_the_pages_the_browser_receives(): void
    {
        $this->awaitingPayment();
        $this->contract();

        $html = $this->get('/registro')->getContent();

        foreach ([self::INTEGRITY, self::EVENTS_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }
}
