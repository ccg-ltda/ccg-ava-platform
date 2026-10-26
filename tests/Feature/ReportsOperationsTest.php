<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Module;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Services\DemoBusinessData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Reportes of Agenda, Pedidos and Clientes: real figures of the active Workspace, behind the plan and the permission of each module. */
class ReportsOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $admin;

    private Workspace $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        $this->seedRoleCatalog();
        $organization = Organization::create(['name' => 'CCG']);
        $this->admin = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE', 'name' => 'Cliente']);
        foreach ([$this->admin, $this->client] as $workspace) {
            $workspace->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'UTC']));
        }
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    private function actIn(Workspace $workspace, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function props(string $query = ''): array
    {
        return $this->get('/reports'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_the_figures_are_the_ones_the_modules_hold(): void
    {
        app(DemoBusinessData::class)->generate($this->admin);
        $this->actIn($this->admin);

        $props = $this->props('?period=30d');
        $from = now()->subDays(29)->startOfDay();
        $to = now()->endOfDay();

        // Agenda
        $appointments = Appointment::where('workspace_id', $this->admin->id)->whereBetween('starts_at', [$from, $to]);
        $bookings = $props['operations']['bookings']['data'];
        $this->assertSame((clone $appointments)->count(), $bookings['kpis']['total']);
        $this->assertSame((clone $appointments)->where('status', 'completed')->count(), $bookings['kpis']['completed']);
        $this->assertSame((clone $appointments)->where('status', 'cancelled')->count(), $bookings['kpis']['cancelled']);
        $this->assertSame((clone $appointments)->where('status', 'no_show')->count(), $bookings['kpis']['noShow']);
        $this->assertSame(Appointment::where('workspace_id', $this->admin->id)->where('starts_at', '>', $to)->whereIn('status', Appointment::BLOCKING)->count(), $bookings['kpis']['upcoming']);
        $this->assertEqualsWithDelta((float) (clone $appointments)->where('status', 'completed')->whereNotNull('price_amount')->sum('price_amount'), $bookings['kpis']['recordedValue'], 0.01);
        $this->assertSame($bookings['kpis']['total'], collect($bookings['byStatus'])->sum('value'));

        // Pedidos
        $orders = Order::where('workspace_id', $this->admin->id)->whereBetween('created_at', [$from, $to]);
        $figures = $props['operations']['orders']['data'];
        $this->assertSame((clone $orders)->count(), $figures['kpis']['total']);
        $this->assertSame((clone $orders)->where('status', 'delivered')->count(), $figures['kpis']['delivered']);
        $sold = (clone $orders)->where('status', '!=', 'cancelled');
        $this->assertEqualsWithDelta((float) (clone $sold)->sum('total'), $figures['kpis']['sales'], 0.01);
        $this->assertEqualsWithDelta((float) (clone $sold)->sum('total') / max(1, (clone $sold)->count()), $figures['kpis']['averageTicket'], 0.01);
        $this->assertSame($figures['kpis']['total'], collect($figures['byStatus'])->sum('value'));
        $this->assertNotEmpty($figures['topProducts']);

        // Clientes
        $customers = $props['operations']['customers']['data'];
        $this->assertSame(10, $customers['kpis']['total']);
        $this->assertSame(10, collect($customers['byConsent'])->sum('value'));
        $this->assertSame($this->admin->customers()->where('is_active', true)->where('marketing_consent', 'granted')->count(), $customers['kpis']['reachable']);
    }

    public function test_the_orders_report_carries_every_field_the_orders_tab_draws_with_data_and_without_it(): void
    {
        // The Pedidos tab reads exactly these keys: a missing one would blank the screen, so the contract is pinned with data and with none.
        $keys = ['kpis' => ['total', 'delivered', 'open', 'cancelled', 'sales', 'averageTicket', 'currency', 'mixedCurrencies'], 'lists' => ['byStatus', 'topProducts', 'byMethod']];

        $this->actIn($this->admin);
        $empty = $this->props('?period=30d&tab=orders')['operations']['orders'];
        $this->assertNull($empty['error']);

        app(DemoBusinessData::class)->generate($this->admin);
        $full = $this->props('?period=30d&tab=orders')['operations']['orders'];

        foreach ([$empty, $full] as $section) {
            $this->assertNull($section['error']);
            foreach ($keys['kpis'] as $key) {
                $this->assertArrayHasKey($key, $section['data']['kpis'], "kpis.{$key}");
            }
            foreach ($keys['lists'] as $key) {
                $this->assertIsArray($section['data'][$key], $key);
            }
        }

        $this->assertSame(0, $empty['data']['kpis']['total']);
        $this->assertGreaterThan(0, $full['data']['kpis']['total']);
    }

    public function test_another_workspace_never_adds_to_the_figures(): void
    {
        app(DemoBusinessData::class)->generate($this->admin);
        // A client Workspace with its own, different data.
        $this->client->customers()->create(['first_name' => 'Solo', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'x']);
        $this->actIn($this->client);

        $props = $this->props();

        // The client's plan (default) has no Agenda or Pedidos: nothing is computed, and the customers module only exists if the plan includes it.
        $this->assertNull($props['operations']['bookings']);
        $this->assertNull($props['operations']['orders']);
        $this->assertFalse($props['sources']['bookings']);
        $this->assertFalse($props['sources']['orders']);
    }

    public function test_a_workspace_that_has_the_modules_only_sees_its_own_numbers(): void
    {
        app(DemoBusinessData::class)->generate($this->admin);
        $this->client->update(['plan_id' => null]);
        $plan = Plan::create(['name' => 'Completo cliente']);
        $plan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports', 'customers', 'orders', 'product_catalog', 'bookings', 'service_catalog', 'professionals'])->pluck('id'));
        $this->client->update(['plan_id' => $plan->id]);
        $this->client->customers()->create(['first_name' => 'Solo', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);
        $this->actIn($this->client);

        $props = $this->props();

        $this->assertSame(1, $props['operations']['customers']['data']['kpis']['total']);
        $this->assertSame(0, $props['operations']['orders']['data']['kpis']['total']);
        $this->assertSame(0, $props['operations']['bookings']['data']['kpis']['total']);
        $this->assertStringNotContainsString('@demo.invalid', json_encode($props));
    }

    public function test_each_section_needs_the_permission_of_its_own_module(): void
    {
        app(DemoBusinessData::class)->generate($this->admin);
        Role::findOrCreate('solo-reportes', 'web')->syncPermissions(['view-dashboard']);
        Role::findOrCreate('ve-pedidos', 'web')->syncPermissions(['view-dashboard', 'view-orders']);

        $this->actIn($this->admin, 'solo-reportes');
        $props = $this->props();
        $this->assertSame([false, false, false], [$props['sources']['bookings'], $props['sources']['orders'], $props['sources']['customers']]);
        $this->assertSame([null, null, null], [$props['operations']['bookings'], $props['operations']['orders'], $props['operations']['customers']]);

        $this->actIn($this->admin, 've-pedidos');
        $props = $this->props();
        $this->assertSame([false, true, false], [$props['sources']['bookings'], $props['sources']['orders'], $props['sources']['customers']]);
        $this->assertNull($props['operations']['bookings']);
        $this->assertNotNull($props['operations']['orders']);
    }

    public function test_a_platform_administrator_looking_at_another_workspace_or_all_gets_no_operational_figures(): void
    {
        app(DemoBusinessData::class)->generate($this->admin);
        $user = $this->actIn($this->admin);
        $user->forceFill(['is_superuser' => true])->save();

        foreach (['?workspace=all', '?workspace='.$this->client->id] as $query) {
            $props = $this->props($query);
            $this->assertNull($props['operations']['orders'], $query);
            $this->assertNull($props['operations']['bookings'], $query);
            $this->assertNull($props['operations']['customers'], $query);
        }
    }

    public function test_money_in_two_currencies_is_not_added_up(): void
    {
        app(DemoBusinessData::class)->generate($this->admin);
        $this->actIn($this->admin);
        Order::where('workspace_id', $this->admin->id)->orderBy('id')->first()->forceFill(['currency' => 'USD'])->save();

        $kpis = $this->props()['operations']['orders']['data']['kpis'];

        $this->assertTrue($kpis['mixedCurrencies']);
        $this->assertNull($kpis['sales']);
        $this->assertNull($kpis['averageTicket']);
    }

    public function test_the_reports_tab_list_offers_only_what_the_workspace_has(): void
    {
        $this->actIn($this->client);

        $this->get('/reports')->assertInertia(fn (AssertableInertia $page) => $page->where('sources.bookings', false)->where('sources.orders', false)->where('sources.customers', false));
    }
}
