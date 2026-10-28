<?php

namespace Tests\Feature;

use App\Models\ApiCallLog;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaymentEvent;
use App\Models\PaymentProviderSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\PaymentGateway;
use App\Payments\UnconfiguredPaymentGateway;
use App\Payments\WompiConfig;
use App\Payments\WompiPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Wompi credentials managed from the platform. Wompi's answers are SIMULATED (Http::fake) and every key is fictitious: nothing here reached Wompi Sandbox.
 */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PUB = 'pub_test_fakekey1234';

    private const EVENTS = 'test_events_FAKESECRETabcd';

    private const INTEGRITY = 'test_integrity_FAKESECRETwxyz';

    private const PRIVATE = 'prv_test_FAKEPRIVATEkey9999';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
    }

    private function platformAdmin(): User
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => true])->save();
        Role::findOrCreate('admin', 'web');
        $dev->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        return $user;
    }

    private function clientAdmin(): User
    {
        $ws = Workspace::create(['organization_id' => Organization::create(['name' => 'Cliente'])->id, 'code' => 'CLIENTE_A', 'name' => 'Cliente A']);
        $user = User::factory()->create();
        $ws->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $ws->id]);

        return $user;
    }

    private function payload(array $override = []): array
    {
        return $override + ['environment' => 'sandbox', 'public_key' => self::PUB, 'private_key' => self::PRIVATE, 'events_secret' => self::EVENTS, 'integrity_secret' => self::INTEGRITY];
    }

    public function test_secrets_are_stored_encrypted_and_never_returned(): void
    {
        $this->platformAdmin();
        $this->put('/settings/wompi', $this->payload(['activate' => true]))->assertSessionHasNoErrors();

        $raw = DB::table('payment_provider_settings')->first();
        foreach ([self::EVENTS, self::INTEGRITY, self::PRIVATE] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($raw));
        }
        $this->assertSame(self::EVENTS, PaymentProviderSetting::first()->events_secret);

        $page = $this->get('/settings');
        $page->assertOk();
        foreach ([self::EVENTS, self::INTEGRITY, self::PRIVATE] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent());
        }
        $page->assertInertia(fn ($p) => $p->where('wompi.environments.sandbox.state', 'saved')->where('wompi.environments.sandbox.eventsSecretSet', true)->where('wompi.environments.production.state', 'incomplete'));
    }

    public function test_blank_secret_keeps_the_stored_one_and_a_change_clears_the_validation(): void
    {
        $this->platformAdmin();
        $this->put('/settings/wompi', $this->payload());
        PaymentProviderSetting::first()->forceFill(['validation_status' => 'ok', 'validated_at' => now()])->save();

        $this->put('/settings/wompi', ['environment' => 'sandbox', 'public_key' => self::PUB, 'events_secret' => '', 'integrity_secret' => null]);
        $row = PaymentProviderSetting::first();
        $this->assertSame(self::EVENTS, $row->events_secret);
        $this->assertSame(self::INTEGRITY, $row->integrity_secret);
        $this->assertSame('ok', $row->validation_status);

        $this->put('/settings/wompi', ['environment' => 'sandbox', 'events_secret' => 'test_events_NEWSECRET0000']);
        $row = PaymentProviderSetting::first();
        $this->assertSame('test_events_NEWSECRET0000', $row->events_secret);
        $this->assertNull($row->validation_status);
    }

    public function test_environments_are_separate_and_prefixes_are_enforced(): void
    {
        $this->platformAdmin();
        $this->put('/settings/wompi', $this->payload(['environment' => 'production']))->assertSessionHasErrors('public_key');
        $this->put('/settings/wompi', $this->payload(['activate' => true]))->assertSessionHasNoErrors();
        $this->put('/settings/wompi', $this->payload(['environment' => 'production', 'public_key' => 'pub_prod_fakekey', 'events_secret' => 'prod_events_FAKE', 'integrity_secret' => 'prod_integrity_FAKE']))->assertSessionHasNoErrors();

        $this->assertSame(2, PaymentProviderSetting::count());
        $config = app(WompiConfig::class);
        $this->assertSame('sandbox', $config->environment());
        $this->assertSame(self::PUB, $config->publicKey());
        $this->assertStringContainsString('sandbox.wompi.co', $config->apiUrl());

        $this->put('/settings/wompi', ['environment' => 'production', 'activate' => true]);
        $config->refresh();
        $this->assertSame('production', $config->environment());
        $this->assertSame('pub_prod_fakekey', $config->publicKey());
        $this->assertSame('prod', $config->eventEnvironment());
        $this->assertSame(1, PaymentProviderSetting::where('is_active', true)->count());
    }

    public function test_gateway_follows_the_database_and_fails_safe_without_it(): void
    {
        config(['wompi.public_key' => null, 'wompi.integrity_secret' => null, 'wompi.events_secret' => null]);
        $this->assertInstanceOf(UnconfiguredPaymentGateway::class, app(PaymentGateway::class));

        $this->platformAdmin();
        $this->put('/settings/wompi', $this->payload(['activate' => true]));
        $this->app->forgetInstance(PaymentGateway::class);
        $this->app->forgetInstance(WompiConfig::class);
        $this->assertInstanceOf(WompiPaymentGateway::class, app(PaymentGateway::class));
    }

    public function test_only_the_platform_administration_reaches_wompi(): void
    {
        $this->clientAdmin();
        $this->get('/settings')->assertOk()->assertInertia(fn ($p) => $p->where('wompi', null));
        $this->put('/settings/wompi', $this->payload())->assertForbidden();
        $this->postJson('/settings/wompi/validate', ['environment' => 'sandbox'])->assertForbidden();
        $this->getJson('/settings/wompi/history')->assertForbidden();
        $this->assertSame(0, PaymentProviderSetting::count());
    }

    public function test_validation_calls_the_official_endpoint_and_distinguishes_saved_from_validated(): void
    {
        $this->platformAdmin();
        $this->put('/settings/wompi', $this->payload(['activate' => true]));

        Http::swap(new Factory);
        Http::fake(['sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => ['id' => 1, 'name' => 'Comercio']])]);
        $this->postJson('/settings/wompi/validate', ['environment' => 'sandbox'])->assertOk()->assertJson(['ok' => true]);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.wompi.co/v1/merchants/'.self::PUB && ! $request->hasHeader('Authorization'));

        $row = PaymentProviderSetting::first();
        $this->assertSame('ok', $row->validation_status);
        $this->assertNotNull($row->validated_at);

        // A rejected key is "failed", never "validated".
        Http::swap(new Factory);
        Http::fake(['sandbox.wompi.co/*' => Http::response(['error' => ['type' => 'NOT_FOUND_ERROR']], 404)]);
        $this->postJson('/settings/wompi/validate', ['environment' => 'sandbox'])->assertOk()->assertJson(['ok' => false]);
        $this->assertSame('failed', PaymentProviderSetting::first()->validation_status);

        // Timeout.
        Http::swap(new Factory);
        Http::fake(['sandbox.wompi.co/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->postJson('/settings/wompi/validate', ['environment' => 'sandbox'])->assertJson(['ok' => false, 'message' => 'Wompi no respondió a tiempo.']);

        $this->assertSame(3, ApiCallLog::where('integration', 'wompi')->count());
        $this->assertSame('timeout', ApiCallLog::latest('id')->first()->outcome);
    }

    public function test_validating_an_environment_without_credentials_is_refused(): void
    {
        $this->platformAdmin();
        $this->postJson('/settings/wompi/validate', ['environment' => 'production'])->assertStatus(422);
    }

    public function test_audit_and_logs_never_hold_a_secret(): void
    {
        $this->platformAdmin();
        $this->put('/settings/wompi', $this->payload(['activate' => true]));
        Http::swap(new Factory);
        Http::fake(['sandbox.wompi.co/*' => Http::response(['data' => ['id' => 1]])]);
        $this->postJson('/settings/wompi/validate', ['environment' => 'sandbox']);

        $dump = json_encode(AuditLog::all()->toArray()).json_encode(ApiCallLog::all()->toArray());
        foreach ([self::EVENTS, self::INTEGRITY, self::PRIVATE] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame(2, AuditLog::where('resource_type', 'payment_settings')->count());
    }

    public function test_history_lists_only_persisted_payments_without_inventing_any(): void
    {
        $this->platformAdmin();
        $this->getJson('/settings/wompi/history')->assertOk()->assertJsonPath('total', 0);

        PaymentEvent::create(['provider' => 'wompi', 'environment' => 'sandbox', 'transaction_id' => 'tx-1', 'reference' => 'ref-1', 'event_status' => 'PENDING', 'status' => 'PENDING', 'amount_cents' => 100000, 'currency' => 'COP']);
        PaymentEvent::create(['provider' => 'wompi', 'environment' => 'sandbox', 'transaction_id' => 'tx-1', 'reference' => 'ref-1', 'event_status' => 'APPROVED', 'status' => 'APPROVED', 'amount_cents' => 100000, 'currency' => 'COP', 'outcome' => 'confirmed']);
        PaymentEvent::create(['provider' => 'wompi', 'environment' => 'production', 'transaction_id' => 'tx-2', 'reference' => 'ref-2', 'event_status' => 'APPROVED', 'status' => 'DECLINED', 'outcome' => 'not_approved']);

        $all = $this->getJson('/settings/wompi/history')->assertOk();
        $all->assertJsonPath('total', 2);
        // One row per transaction, the latest event: tx-1 is approved and "pending invoicing" (no invoicing provider is connected), the declined one has no invoice.
        $rows = collect($all->json('data'))->keyBy('transactionId');
        $this->assertSame('APPROVED', $rows['tx-1']['status']);
        $this->assertSame('pending', $rows['tx-1']['invoice']);
        $this->assertNull($rows['tx-2']['invoice']);

        $this->getJson('/settings/wompi/history?environment=production')->assertJsonPath('total', 1);
        $this->getJson('/settings/wompi/history?status=APPROVED')->assertJsonPath('total', 1);
        $this->getJson('/settings/wompi/history?q=ref-2')->assertJsonPath('total', 1);

        $id = $rows['tx-1']['id'];
        $detail = $this->getJson("/settings/wompi/history/{$id}")->assertOk();
        $this->assertCount(2, $detail->json('events'));
        $this->getJson('/settings/wompi/history/999999')->assertNotFound();
    }
}
