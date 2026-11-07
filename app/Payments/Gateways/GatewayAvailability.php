<?php

namespace App\Payments\Gateways;

use App\Models\Integration;
use App\Models\Plan;
use App\Models\Workspace;
use App\Models\WorkspacePaymentGatewayOverride;
use App\Services\ModuleAccess;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a Workspace can collect with a gateway. The SERVER decides here, on every operation; the screens only reflect it. In order:
 *  1. Plan and administration: the plan lists the gateway (`plans.payment_gateways`; NULL = none, closed by default) and the administrator did not REVOKE it; or the
 *     administrator GRANTED it. Only platform administration writes grants/revokes: a Workspace can never enable a gateway for itself.
 *  2. The connection: it exists, is complete, is ACTIVE, points to an allowed environment (production only when `payments.allow_production`) and was VALIDATED
 *     by a real connection test. A grant never makes an incomplete or unvalidated connection usable.
 *  3. Compatibility: the Workspace's currency and tax country are served by the provider.
 * The state names are the ones the cards of Integraciones show.
 */
class GatewayAvailability
{
    public function __construct(private readonly PaymentGatewayManager $gateways, private readonly ModuleAccess $modules) {}

    private static bool $overridesReady = false;

    private function overridesReady(): bool
    {
        return self::$overridesReady = self::$overridesReady || Schema::hasTable('workspace_payment_gateway_overrides');
    }

    public static function ready(): bool
    {
        return Schema::hasColumn('plans', 'payment_gateways') && Schema::hasTable('payment_transactions');
    }

    public function override(Workspace $workspace, string $key): ?string
    {
        return $this->overridesReady()
            ? WorkspacePaymentGatewayOverride::where('workspace_id', $workspace->id)->where('gateway', $key)->value('mode')
            : null;
    }

    public function planAllows(Workspace $workspace, string $key): bool
    {
        if ($workspace->isAdministrative()) {
            return true;
        }

        if ($this->modules->keys($workspace) === []) {
            return false;
        }

        $plan = $workspace->plan_id ? $workspace->plan : $this->modules->defaultPlan();

        return $plan instanceof Plan && Schema::hasColumn('plans', 'payment_gateways') && in_array($key, $plan->payment_gateways ?? [], true);
    }

    /** Plan + administrative exception, before looking at the connection. */
    public function permitted(Workspace $workspace, string $key): bool
    {
        if (! $this->gateways->has($key)) {
            return false;
        }

        return match ($this->override($workspace, $key)) {
            WorkspacePaymentGatewayOverride::REVOKE => false,
            WorkspacePaymentGatewayOverride::GRANT => $workspace->isAdministrative() || $this->modules->keys($workspace) !== [],
            default => $this->planAllows($workspace, $key),
        };
    }

    public function integration(Workspace $workspace, string $key): ?Integration
    {
        return $workspace->integrations()->where('type', $key)->orderBy('id')->first();
    }

    /** @return array{key: string, label: string, state: string, available: bool, reasons: list<string>, environment: ?string} */
    public function describe(Workspace $workspace, string $key, ?string $currency = null): array
    {
        $adapter = $this->gateways->adapter($key);
        $base = ['key' => $key, 'label' => $adapter->label(), 'environment' => null];
        $deny = function (string $state, string $reason) use (&$base) {
            return $base + ['state' => $state, 'available' => false, 'reasons' => [$reason]];
        };

        if ($this->override($workspace, $key) === WorkspacePaymentGatewayOverride::REVOKE) {
            return $deny('admin_disabled', 'La administración desactivó este método de pago para tu Workspace.');
        }

        if (! $this->permitted($workspace, $key)) {
            return $deny('plan_disabled', 'El proveedor no está habilitado en tu plan.');
        }

        $integration = $this->integration($workspace, $key);

        if (! $integration) {
            return $deny('not_configured', 'Falta configurar la conexión con el proveedor.');
        }

        $problems = $adapter->configurationProblems($integration);
        $base['environment'] = $adapter->environment($integration);

        if ($problems !== []) {
            return $base + ['state' => 'incomplete', 'available' => false, 'reasons' => $problems];
        }

        if ($base['environment'] === 'production' && ! config('payments.allow_production')) {
            return $deny('blocked_environment', 'Producción todavía no está autorizada en esta instalación.');
        }

        if ($integration->last_test_ok === false) {
            return $deny('error', 'La última prueba de conexión falló.');
        }

        if ($integration->last_test_ok === null) {
            return $deny('saved', 'La conexión está guardada pero todavía no se ha validado.');
        }

        if (! $integration->is_active) {
            return $deny('inactive', 'La conexión está desactivada.');
        }

        $country = $workspace->settingsOrDefault()->tax_country;

        if (! in_array($currency ?? $workspace->settingsOrDefault()->currency, $adapter->currencies(), true) || ! in_array($country, $adapter->countries(), true)) {
            return $deny('incompatible', 'El proveedor no atiende la moneda o el país de este comercio.');
        }

        return $base + ['state' => 'validated', 'available' => true, 'reasons' => []];
    }

    /** The connection to charge with, or null when the Workspace may not collect with this gateway right now. */
    public function usable(Workspace $workspace, string $key, ?string $currency = null): ?Integration
    {
        if (! $this->gateways->has($key) || ! $this->describe($workspace, $key, $currency)['available']) {
            return null;
        }

        return $this->integration($workspace, $key);
    }

    /** @return list<array{key: string, label: string, state: string, available: bool, reasons: list<string>, environment: ?string}> */
    public function all(Workspace $workspace, ?string $currency = null): array
    {
        return array_map(fn (string $key) => $this->describe($workspace, $key, $currency), $this->gateways->keys());
    }

    /** @return list<array{key: string, label: string, state: string, available: bool, reasons: list<string>, environment: ?string}> only what the customer may choose */
    public function offered(Workspace $workspace, ?string $currency = null): array
    {
        return array_values(array_filter($this->all($workspace, $currency), fn (array $gateway) => $gateway['available']));
    }
}
