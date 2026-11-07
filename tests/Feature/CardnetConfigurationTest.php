<?php

namespace Tests\Feature;

use App\Integrations\CardnetType;
use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Workspace;
use App\Payments\Gateways\CardnetAdapter;
use App\Payments\Gateways\GatewayAvailability;
use App\Payments\Gateways\GatewayOutcome;
use App\Payments\Gateways\GatewayResult;
use App\Payments\Gateways\PaymentException;
use App\Payments\Gateways\PaymentLinks;
use App\Payments\Gateways\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * CardNET as far as it can honestly go without its official specification: the commercial configuration (encrypted, write-only, masked), the plan control, the states and the
 * refusal to call anything «validada» or to charge. Nothing here reaches CardNET (`Http::preventStrayRequests`) and nothing here is a laboratory test.
 */
class CardnetConfigurationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const FULL = [
        'environment' => 'sandbox', 'merchant_number' => '349011300', 'merchant_terminal' => '80029126', 'merchant_terminal_amex' => '00000001', 'merchant_type' => '5812',
        'merchant_name' => 'COMERCIO DE PRUEBAS', 'transaction_type' => '200', 'page_language' => 'ES', 'contact_email' => 'ventas@example.com', 'contact_phone' => '809-111-2222',
        'address_line1' => 'Calle Uno 2', 'city' => 'Santo Domingo', 'state' => 'DN', 'country' => 'DO', 'postal_code' => '10101',
    ];

    private Workspace $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:*' => Http::response('', 404)]);
        $this->seedRoleCatalog();
        $this->shop = $this->workspaceWith(['cardnet'], 'TIENDA');
    }

    private function workspaceWith(array $gateways, string $code): Workspace
    {
        $workspace = $this->bookingWorkspace($code, ['dashboard', 'integrations', 'product_catalog', 'orders']);
        $workspace->plan->forceFill(['payment_gateways' => $gateways])->save();
        $workspace->settingsOrDefault()->update(['currency' => 'DOP', 'tax_country' => 'DO']);

        return $workspace->refresh();
    }

    private function save(array $data = self::FULL, string $gateway = 'cardnet')
    {
        return $this->put(route('integrations.payment-gateways.update', $gateway), $data);
    }

    private function stored(?Workspace $workspace = null): ?Integration
    {
        return ($workspace ?? $this->shop)->integrations()->where('type', 'cardnet')->first();
    }

    private function state(?Workspace $workspace = null): array
    {
        return app(GatewayAvailability::class)->describe(($workspace ?? $this->shop)->refresh(), 'cardnet');
    }

    // ───────────── the card and the catalog ─────────────

    public function test_cardnet_is_a_registered_gateway_with_its_own_card_and_not_a_generic_connection(): void
    {
        $this->assertSame(CardnetType::class, get_class(app(IntegrationRegistry::class)->get('cardnet')));
        $this->assertNotContains('cardnet', app(IntegrationRegistry::class)->keys()); // it never appears in the generic «Nueva conexión» form
        $this->actAsMember($this->shop, 'admin');

        $this->get(route('integrations.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Integrations/Index')
            ->has('paymentGateways', 2)->where('paymentGateways.0.key', 'cardnet')->where('paymentGateways.0.state', 'not_configured')->where('paymentGateways.0.testable', false)
            ->has('paymentGateways.0.blockedBy', 2)->where('paymentGateways.0.integration', null)
            ->where('paymentGateways.0.fields', fn ($fields) => collect($fields)->pluck('section')->unique()->values()->all() === ['Datos comerciales', 'Ambiente', 'Parámetros técnicos', 'Contacto y dirección']));
    }

    public function test_the_form_marks_what_the_official_specification_has_not_confirmed(): void
    {
        $fields = collect((new CardnetType)->fields())->keyBy('name');

        foreach (['merchant_terminal_amex', 'transaction_type', 'page_language', 'contact_email', 'address_line1', 'country', 'merchant_name'] as $name) {
            $this->assertArrayHasKey('pending', $fields[$name], "[{$name}] must say it is pending confirmation");
        }

        $this->assertSame('secret', $fields['merchant_number']['kind']);
        $this->assertSame('secret', $fields['merchant_terminal']['kind']);
        $this->assertNotContains('api_key', $fields->keys()->all()); // no secret CardNET never asked for is requested
        $this->assertNotContains('secret_key', $fields->keys()->all());
    }

    // ───────────── saving ─────────────

    public function test_a_complete_configuration_is_saved_inactive_unvalidated_and_audited(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save()->assertSessionHasNoErrors()->assertSessionHas('success');

        $integration = $this->stored();
        $this->assertNotNull($integration);
        $this->assertFalse($integration->is_active); // saving never switches a connection on
        $this->assertNull($integration->last_test_ok);
        $this->assertSame('sandbox', $integration->config['environment']);
        $this->assertSame('5812', $integration->config['merchant_type']);
        $this->assertSame('saved', $this->state()['state']); // saved is NOT validated
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'integration', 'workspace_id' => $this->shop->id]);
    }

    public function test_an_incomplete_configuration_can_be_saved_and_says_what_is_missing(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save(['environment' => 'sandbox', 'merchant_number' => '349011300'])->assertSessionHasNoErrors();

        $state = $this->state();
        $this->assertSame('incomplete', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertEqualsCanonicalizing(['Falta la terminal.', 'Falta la categoría del comercio.', 'Falta el nombre del comercio.'], $state['reasons']);
    }

    public function test_invalid_values_are_rejected_and_nothing_is_stored(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $cases = [
            'merchant_number' => 'ABC123', 'merchant_terminal' => '1234567890123', 'merchant_terminal_amex' => 'x', 'merchant_type' => '58', 'merchant_name' => str_repeat('A', 41),
            'environment' => 'staging', 'transaction_type' => 'two', 'page_language' => 'FR', 'contact_email' => 'no-es-correo', 'contact_phone' => 'abc', 'country' => 'DOM',
        ];

        foreach ($cases as $field => $value) {
            $this->save([...self::FULL, $field => $value])->assertSessionHasErrors($field);
        }

        $this->save([...self::FULL, 'environment' => null])->assertSessionHasErrors('environment');
        $this->assertNull($this->stored());
    }

    public function test_sandbox_and_production_are_kept_apart_and_production_is_not_authorized_to_charge(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save([...self::FULL, 'environment' => 'production'])->assertSessionHasNoErrors();
        $this->assertSame('production', $this->stored()->config['environment']);
        $this->assertSame('production', $this->state()['environment']);

        $this->stored()->update(['is_active' => true, 'last_test_ok' => true]);
        $this->assertSame('blocked_environment', $this->state()['state']); // the installation has not authorized live charges

        $this->assertSame('labservicios.cardnet.com.do', config('integrations.cardnet.hosts.sandbox'));
        $this->assertSame('ecommerce.cardnet.com.do', config('integrations.cardnet.hosts.production'));
    }

    // ───────────── secrets ─────────────

    public function test_the_merchant_identifiers_are_encrypted_at_rest_masked_everywhere_and_never_in_the_audit(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();

        $raw = (string) DB::table('integrations')->where('id', $this->stored()->id)->value('secrets');
        foreach (['349011300', '80029126', '00000001'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        $this->assertSame('349011300', $this->stored()->secrets['merchant_number']); // the model decrypts it for the server

        $page = $this->get(route('integrations.index'));
        foreach (['349011300', '80029126', '00000001'] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent());
        }

        $page->assertInertia(fn ($inertia) => $inertia->where('paymentGateways.0.integration.form.merchant_number', '')->where('paymentGateways.0.integration.form.merchant_number_set', true)
            ->where('paymentGateways.0.integration.form.merchant_number_mask', '••••1300')->where('paymentGateways.0.integration.form.merchant_terminal_mask', '••••9126')
            ->where('paymentGateways.0.integration.summary.rows.1.value', '••••1300'));

        $audited = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode(' ');
        foreach (['349011300', '80029126', 'ventas@example.com', '809-111-2222', 'Calle Uno'] as $value) {
            $this->assertStringNotContainsString($value, $audited);
        }

        $this->assertStringNotContainsString('349011300', json_encode($this->stored()->toArray()));
    }

    public function test_a_blank_secret_keeps_the_stored_one_and_a_new_value_replaces_it(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();

        $this->save([...self::FULL, 'merchant_number' => '', 'merchant_terminal' => '', 'merchant_name' => 'OTRO NOMBRE'])->assertSessionHasNoErrors();
        $this->assertSame('349011300', $this->stored()->secrets['merchant_number']);
        $this->assertSame('80029126', $this->stored()->secrets['merchant_terminal']);
        $this->assertSame('OTRO NOMBRE', $this->stored()->config['merchant_name']);

        $this->save([...self::FULL, 'merchant_number' => '123456789'])->assertSessionHasNoErrors();
        $this->assertSame('123456789', $this->stored()->secrets['merchant_number']);
        $this->assertSame('80029126', $this->stored()->secrets['merchant_terminal']);
    }

    public function test_changing_the_configuration_discards_a_previous_validation(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['last_test_ok' => true, 'last_tested_at' => now(), 'last_test_message' => 'ok']);

        $this->save([...self::FULL, 'merchant_type' => '5999'])->assertSessionHasNoErrors();

        $this->assertNull($this->stored()->last_test_ok);
        $this->assertNull($this->stored()->last_tested_at);
    }

    // ───────────── isolation and permissions ─────────────

    public function test_two_workspaces_keep_their_own_cardnet_account_apart(): void
    {
        $other = $this->workspaceWith(['cardnet'], 'OTRA');
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->actAsMember($other, 'admin');
        $this->save([...self::FULL, 'merchant_number' => '999888777', 'merchant_name' => 'OTRA TIENDA'])->assertSessionHasNoErrors();

        $this->assertSame('349011300', $this->stored($this->shop)->secrets['merchant_number']);
        $this->assertSame('999888777', $this->stored($other)->secrets['merchant_number']);
        $this->assertNotSame($this->stored($this->shop)->id, $this->stored($other)->id);

        $this->get(route('integrations.index'))->assertInertia(fn ($page) => $page->where('paymentGateways.0.integration.summary.rows.1.value', '••••8777'));
        $this->assertStringNotContainsString('349011300', $this->get(route('integrations.index'))->getContent());
    }

    public function test_the_workspace_comes_from_the_session_never_from_the_request(): void
    {
        $other = $this->workspaceWith(['cardnet'], 'OTRA');
        $this->actAsMember($this->shop, 'admin');

        $this->save([...self::FULL, 'workspace_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertNotNull($this->stored($this->shop));
        $this->assertNull($this->stored($other));
    }

    public function test_a_role_without_manage_integrations_cannot_configure_a_gateway(): void
    {
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-dashboard', 'view-integrations']);
        $this->actAsMember($this->shop, 'lector');

        $this->save()->assertForbidden();
        $this->assertNull($this->stored());
    }

    public function test_an_unknown_gateway_is_a_404(): void
    {
        $this->actAsMember($this->shop, 'admin');

        $this->save(self::FULL, 'paypal')->assertNotFound();
    }

    // ───────────── plan control ─────────────

    public function test_a_workspace_whose_plan_does_not_include_cardnet_cannot_configure_or_see_it_enabled(): void
    {
        $closed = $this->workspaceWith([], 'SINPLAN');
        $this->actAsMember($closed, 'admin');

        $this->save()->assertSessionHasErrors('gateway');
        $this->assertNull($this->stored($closed));
        $this->get(route('integrations.index'))->assertInertia(fn ($page) => $page->where('paymentGateways.0.state', 'plan_disabled')->where('paymentGateways.0.available', false)
            ->where('paymentGateways.0.unavailable', 'El proveedor no está habilitado en tu plan.'));
    }

    public function test_a_plan_that_never_saved_a_gateway_list_includes_none(): void
    {
        $this->shop->plan->forceFill(['payment_gateways' => null])->save();
        $this->actAsMember($this->shop->refresh(), 'admin');

        $this->save()->assertSessionHasErrors('gateway');
        $this->assertSame('plan_disabled', $this->state()['state']);
    }

    // ───────────── activation without a real validation ─────────────

    public function test_activating_a_complete_configuration_does_not_validate_it_and_the_gateway_stays_closed(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();

        $this->post(route('integrations.activate', $this->stored()->id))->assertSessionHasNoErrors();

        $this->assertTrue($this->stored()->is_active);
        $state = $this->state();
        $this->assertSame('saved', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertNull(app(GatewayAvailability::class)->usable($this->shop, 'cardnet', 'DOP'));
        $this->assertSame([], app(GatewayAvailability::class)->offered($this->shop, 'DOP'));
    }

    public function test_an_incomplete_configuration_cannot_be_activated(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save(['environment' => 'sandbox'])->assertSessionHasNoErrors();

        $this->post(route('integrations.activate', $this->stored()->id))->assertSessionHasErrors('status');
        $this->assertFalse($this->stored()->is_active);
    }

    public function test_a_gateway_the_plan_no_longer_includes_cannot_be_activated(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->shop->plan->forceFill(['payment_gateways' => []])->save();

        $this->post(route('integrations.activate', $this->stored()->id))->assertSessionHasErrors('status');
        $this->assertFalse($this->stored()->is_active);
    }

    public function test_the_connection_test_is_refused_because_it_does_not_exist_yet_never_faked(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true]);

        $this->post(route('integrations.test', $this->stored()->id))->assertSessionHas('error', 'Esta integración todavía no admite prueba de conexión.');

        $this->assertNull($this->stored()->last_test_ok);
        $this->assertSame('saved', $this->state()['state']);
        Http::assertNothingSent();
    }

    // ───────────── the adapter: structure only, protocol pending ─────────────

    public function test_the_adapter_declares_what_it_cannot_do_and_does_not_invent_the_protocol(): void
    {
        $adapter = app(CardnetAdapter::class);

        $this->assertFalse($adapter->capabilities()->query);
        $this->assertFalse($adapter->capabilities()->refund);
        $this->assertFalse($adapter->capabilities()->notifications);
        $this->assertSame(['DOP'], $adapter->currencies());
        $this->assertSame(['DO'], $adapter->countries());
        $this->assertEqualsCanonicalizing(['labservicios.cardnet.com.do', 'ecommerce.cardnet.com.do'], $adapter->hosts());
        $this->assertSame('214', CardnetAdapter::CURRENCY_CODES['DOP']);
        $this->assertLessThanOrEqual(20, strlen($adapter->providerReference('AVA'.str_repeat('X', 13))));
    }

    public function test_even_a_connection_forced_to_validated_cannot_charge_because_the_protocol_is_missing(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true, 'last_test_ok' => true]); // not reachable from the application: forced straight into the database

        $order = Order::create(['workspace_id' => $this->shop->id, 'number' => 1, 'customer_name' => 'Laura', 'currency' => 'DOP', 'total' => '100.00', 'status' => 'received']);

        try {
            app(PaymentService::class)->checkout($this->shop->refresh(), $order, 'cardnet', 'try-1');
            $this->fail('CardNET cannot create a session without its protocol.');
        } catch (PaymentException $exception) {
            $this->assertSame('protocol_pending', $exception->reason);
        }

        $this->assertSame(0, PaymentTransaction::whereNotNull('active_key')->count()); // nothing is left holding the order
        Http::assertNothingSent();
    }

    public function test_every_answer_of_the_adapter_is_unknown_so_nothing_can_be_approved(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $transaction = new PaymentTransaction(['provider' => 'cardnet']);

        $result = app(CardnetAdapter::class)->query($transaction, $this->stored());

        $this->assertInstanceOf(GatewayResult::class, $result);
        $this->assertSame(GatewayOutcome::Unknown, $result->outcome);
    }

    public function test_cardnet_is_not_offered_in_the_payment_page_while_unvalidated(): void
    {
        $this->actAsMember($this->shop, 'admin');
        $this->save()->assertSessionHasNoErrors();
        $this->stored()->update(['is_active' => true]);

        $order = Order::create(['workspace_id' => $this->shop->id, 'number' => 1, 'customer_name' => 'Laura', 'currency' => 'DOP', 'total' => '100.00', 'status' => 'received']);
        ['token' => $token] = app(PaymentLinks::class)->create($this->shop, $order, null);

        $this->get("/pay/{$token}")->assertInertia(fn ($page) => $page->component('Pay/Show')->has('gateways', 0));
    }
}
