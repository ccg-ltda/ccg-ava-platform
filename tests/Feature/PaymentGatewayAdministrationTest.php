<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Models\WorkspacePaymentGatewayOverride;
use App\Payments\Gateways\GatewayAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\Support\FakeGatewayAdapter;
use Tests\Support\FakePayType;
use Tests\TestCase;

/**
 * The administrative exception of a Workspace over its plan, as the platform administration manages it: grant, revoke or go back to the plan, always with a reason, audited with
 * who and when, and never able to switch on an incomplete or unvalidated connection. A Workspace (or its administrator) can never grant itself a gateway.
 */
class PaymentGatewayAdministrationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:*' => Http::response('', 404)]);
        FakeGatewayAdapter::reset();
        config(['payments.gateways' => ['fakepay' => FakeGatewayAdapter::class], 'integrations.types.fakepay' => FakePayType::class]);

        $this->seedRoleCatalog();
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', ['dashboard']);
        $this->client = $this->bookingWorkspace('CLIENTE', ['dashboard', 'integrations', 'product_catalog', 'orders']);
        $this->client->plan->forceFill(['payment_gateways' => []])->save();
    }

    private function asPlatform(): void
    {
        $user = $this->actAsMember($this->dev, 'plataforma');
        $user->forceFill(['is_superuser' => true])->save();
    }

    private function connect(Workspace $workspace, array $attributes = []): void
    {
        $workspace->integrations()->create($attributes + [
            'name' => 'FakePay', 'provider' => 'fakepay', 'type' => 'fakepay', 'is_active' => true,
            'config' => ['merchant' => 'M1', 'environment' => 'sandbox'], 'secrets' => ['key' => 'k'], 'last_test_ok' => true,
        ]);
    }

    private function change(string $mode, ?string $reason = 'Piloto con el cliente', string $gateway = 'fakepay', ?Workspace $workspace = null)
    {
        return $this->put(route('workspaces.payment-gateways.update', ($workspace ?? $this->client)->id), ['gateway' => $gateway, 'mode' => $mode, 'reason' => $reason]);
    }

    public function test_the_administration_sees_the_plan_the_exception_and_the_effective_state_of_each_gateway(): void
    {
        $this->asPlatform();

        $this->get(route('workspaces.modules.show', $this->client->id))->assertOk()->assertInertia(fn ($page) => $page->component('Plans/WorkspaceModules')
            ->has('gateways', 1)->where('gateways.0.key', 'fakepay')->where('gateways.0.inPlan', false)->where('gateways.0.override', null)->where('gateways.0.state', 'plan_disabled')->where('gateways.0.available', false));
    }

    public function test_a_grant_needs_a_reason_and_records_who_when_and_why(): void
    {
        $this->asPlatform();

        $this->change('grant', null)->assertSessionHasErrors('reason');
        $this->change('grant', 'abc')->assertSessionHasErrors('reason');
        $this->assertSame(0, WorkspacePaymentGatewayOverride::count());

        $this->change('grant', 'Piloto con el cliente')->assertSessionHasNoErrors();

        $override = WorkspacePaymentGatewayOverride::firstOrFail();
        $this->assertSame($this->client->id, $override->workspace_id);
        $this->assertSame('grant', $override->mode);
        $this->assertSame('Piloto con el cliente', $override->reason);
        $this->assertNotNull($override->created_by);

        $log = AuditLog::where('resource_type', 'payment_gateway')->firstOrFail();
        $this->assertSame($this->client->id, $log->workspace_id);
        $this->assertStringContainsString('Piloto con el cliente', json_encode($log->changes));

        $this->get(route('workspaces.modules.show', $this->client->id))->assertInertia(fn ($page) => $page->where('gateways.0.override.mode', 'grant')
            ->where('gateways.0.override.reason', 'Piloto con el cliente')->where('gateways.0.override.by', fn ($by) => is_string($by) && $by !== '')->has('gateways.0.override.at')
            ->where('history.0.text', fn ($text) => str_contains((string) $text, $this->client->name)));
    }

    public function test_a_grant_never_activates_an_incomplete_or_unvalidated_connection(): void
    {
        $this->asPlatform();
        $this->change('grant')->assertSessionHasNoErrors();
        $availability = app(GatewayAvailability::class);

        $this->assertSame('not_configured', $availability->describe($this->client->refresh(), 'fakepay')['state']);

        $this->connect($this->client, ['last_test_ok' => null]);
        $state = $availability->describe($this->client, 'fakepay');
        $this->assertSame('saved', $state['state']);
        $this->assertFalse($state['available']);
        $this->assertNull($availability->usable($this->client, 'fakepay'));

        $this->client->integrations()->update(['config' => ['environment' => 'sandbox']]); // incomplete
        $this->assertSame('incomplete', $availability->describe($this->client, 'fakepay')['state']);
    }

    public function test_a_revoke_beats_the_plan_and_clearing_it_goes_back_to_the_plan(): void
    {
        $this->client->plan->forceFill(['payment_gateways' => ['fakepay']])->save();
        $this->connect($this->client);
        $this->asPlatform();
        $availability = app(GatewayAvailability::class);
        $this->assertTrue($availability->describe($this->client->refresh(), 'fakepay')['available']);

        $this->change('revoke', 'Contrato suspendido')->assertSessionHasNoErrors();
        $this->assertSame('admin_disabled', $availability->describe($this->client, 'fakepay')['state']);

        $this->change('clear', null)->assertSessionHasNoErrors();
        $this->assertSame(0, WorkspacePaymentGatewayOverride::count());
        $this->assertTrue($availability->describe($this->client, 'fakepay')['available']);
        $this->assertSame(2, AuditLog::where('resource_type', 'payment_gateway')->count());
    }

    public function test_a_forged_gateway_or_mode_is_rejected(): void
    {
        $this->asPlatform();

        $this->change('grant', 'Motivo válido', 'paypal')->assertSessionHasErrors('gateway');
        $this->change('forever', 'Motivo válido')->assertSessionHasErrors('mode');
        $this->assertSame(0, WorkspacePaymentGatewayOverride::count());
    }

    public function test_a_workspace_administrator_cannot_grant_itself_a_gateway(): void
    {
        $this->actAsMember($this->client, 'admin');

        $this->change('grant')->assertForbidden();
        $this->get(route('workspaces.modules.show', $this->client->id))->assertForbidden();
        $this->assertSame(0, WorkspacePaymentGatewayOverride::count());
        $this->assertSame('plan_disabled', app(GatewayAvailability::class)->describe($this->client->refresh(), 'fakepay')['state']);
    }

    public function test_a_superuser_working_inside_a_client_workspace_has_no_platform_access_either(): void
    {
        $user = $this->actAsMember($this->client, 'admin');
        $user->forceFill(['is_superuser' => true])->save();

        $this->change('grant')->assertForbidden();
    }

    public function test_a_platform_role_without_assign_plans_cannot_change_exceptions(): void
    {
        Role::findOrCreate('soloconsulta', 'web')->syncPermissions(['view-dashboard', 'view-plans']);
        $this->actAsMember($this->dev, 'soloconsulta');

        $this->change('grant')->assertForbidden();
        $this->assertSame(0, WorkspacePaymentGatewayOverride::count());
    }

    public function test_the_administrative_workspace_takes_no_exception(): void
    {
        $this->asPlatform();

        $this->change('grant', 'Motivo válido', 'fakepay', $this->dev)->assertNotFound();
    }

    public function test_an_exception_of_one_workspace_never_touches_another(): void
    {
        $other = $this->bookingWorkspace('OTRO', ['dashboard', 'integrations', 'product_catalog', 'orders']);
        $other->plan->forceFill(['payment_gateways' => []])->save();
        $this->asPlatform();

        $this->change('grant')->assertSessionHasNoErrors();

        $this->assertSame('plan_disabled', app(GatewayAvailability::class)->describe($other->refresh(), 'fakepay')['state']);
        $this->assertSame(1, WorkspacePaymentGatewayOverride::where('workspace_id', $this->client->id)->count());
        $this->assertSame(0, WorkspacePaymentGatewayOverride::where('workspace_id', $other->id)->count());
    }
}
