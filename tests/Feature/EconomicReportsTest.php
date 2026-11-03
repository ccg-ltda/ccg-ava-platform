<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The economic section of Reportes. Only two modules generate money and only the records that REALLY happened count: appointments that were carried out (`completed`) and
 * orders that were delivered (`delivered`). Nothing pending, cancelled, in progress, failed or of another Workspace adds anything; a record is counted once; money in two
 * currencies is never added; and the figures are never called «cash received» because Ava records no payments.
 */
class EconomicReportsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'reports', 'bookings', 'orders', 'product_catalog', 'locations', 'service_catalog', 'professionals', 'customers'];

    private Workspace $ws;

    private Workspace $other;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->ws = $this->bookingWorkspace('ECO', self::MODULES);
        $this->other = $this->bookingWorkspace('ECO_B', self::MODULES);
        Role::findOrCreate('solo_pedidos', 'web')->syncPermissions(['view-dashboard', 'view-orders']);
        Role::findOrCreate('solo_agenda', 'web')->syncPermissions(['view-dashboard', 'view-bookings']);
        Role::findOrCreate('solo_resumen', 'web')->syncPermissions(['view-dashboard']);
    }

    private function appointment(Workspace $ws, string $status, ?int $price, string $when = '-2 days', string $currency = 'COP', ?Location $location = null): Appointment
    {
        $service = $ws->services()->first() ?? $ws->services()->create(['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 1]);
        $customer = $ws->bookingCustomers()->create(['name' => 'Cliente '.(++$this->n), 'phone' => '+57300000'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT)]);
        $start = now()->modify($when)->setTime(10, 0)->utc();

        return $ws->appointments()->create([
            'service_id' => $service->id, 'customer_id' => $customer->id, 'service_name' => 'Consulta', 'professional_name' => 'Ana', 'location_id' => $location?->id, 'location_name' => $location?->name,
            'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'blocks_until' => $start->addMinutes(30), 'status' => $status, 'provider' => 'internal', 'sync_status' => 'none',
            'channel' => 'admin', 'origin_channel' => 'admin', 'token_hash' => hash('sha256', 't'.$this->n.$ws->id), 'price_amount' => $price, 'price_currency' => $price === null ? null : $currency,
        ]);
    }

    private function order(Workspace $ws, string $status, int $total, string $changed = '-1 day', string $currency = 'COP', ?Location $location = null): void
    {
        $ws->orders()->create([
            'number' => ++$this->n + $ws->id * 1000, 'customer_name' => 'C'.$this->n, 'status' => $status, 'delivery_method' => 'pickup', 'total' => $total, 'currency' => $currency,
            'location_id' => $location?->id, 'location_name' => $location?->name, 'status_changed_at' => now()->modify($changed),
        ]);
    }

    /** @return array<string, mixed> */
    private function props(string $query = '', string $role = 'admin'): array
    {
        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->ws, $role);

        return $this->get('/reports?tab=economics&'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_only_services_that_were_carried_out_count_as_income(): void
    {
        $this->appointment($this->ws, Appointment::COMPLETED, 80000);
        $this->appointment($this->ws, Appointment::COMPLETED, 120000, '-3 days');
        foreach ([Appointment::REQUESTED, Appointment::CONFIRMED, Appointment::CANCELLED, Appointment::NO_SHOW, Appointment::FAILED] as $status) {
            $this->appointment($this->ws, $status, 999000);
        }
        $this->appointment($this->ws, Appointment::COMPLETED, null); // carried out, but nobody recorded a price
        $this->appointment($this->other, Appointment::COMPLETED, 777000); // another Workspace

        $data = $this->props()['economics']['bookings']['data'];

        $this->assertSame(200000.0, $data['kpis']['income']['value']);
        $this->assertSame(2, $data['kpis']['count']['value']);
        $this->assertSame(100000.0, $data['kpis']['average']['value']);
        $this->assertSame(1, $data['kpis']['withoutValue'], 'a carried-out appointment without a price is said, not invented');
        $this->assertSame(200000.0, $data['series']['total']);
        $this->assertFalse($data['kpis']['mixedCurrencies']);
    }

    public function test_only_delivered_orders_count_and_each_one_is_counted_once(): void
    {
        $this->order($this->ws, 'delivered', 1000);
        $this->order($this->ws, 'delivered', 2000, '-2 days');
        foreach (['received', 'confirmed', 'preparing', 'ready', 'dispatched', 'cancelled'] as $status) {
            $this->order($this->ws, $status, 50000);
        }
        $this->order($this->other, 'delivered', 888000);

        $data = $this->props()['economics']['orders']['data'];

        $this->assertSame(3000.0, $data['kpis']['income']['value']);
        $this->assertSame(2, $data['kpis']['count']['value']);
        $this->assertSame(1500.0, $data['kpis']['average']['value']);
        $this->assertSame(0, $data['kpis']['withoutValue']);
        $this->assertSame(3000.0, array_sum(array_column(array_column($data['series']['points'], 'values'), 'income')), 'the series adds up to the total: nothing is counted twice');
        $this->assertSame(2, array_sum(array_column($data['series']['points'], 'count')));
    }

    public function test_the_period_is_compared_with_the_previous_one_on_the_same_timeline(): void
    {
        $this->appointment($this->ws, Appointment::COMPLETED, 100000, '-2 days');
        $this->appointment($this->ws, Appointment::COMPLETED, 50000, '-40 days'); // the previous period (30 days before the last 30)
        $this->appointment($this->ws, Appointment::COMPLETED, 7000, '-100 days'); // outside both

        $data = $this->props()['economics']['bookings']['data'];

        $this->assertSame([100000.0, 50000.0, 100], [$data['kpis']['income']['value'], $data['kpis']['income']['previous'], $data['kpis']['income']['change']]);
        $this->assertSame([1, 1], [$data['kpis']['count']['value'], $data['kpis']['count']['previous']]);

        $shifted = now()->modify('-40 days')->addDays(30)->toDateString();
        $point = collect($data['series']['points'])->firstWhere('key', $shifted);
        $this->assertSame(50000.0, $point['values']['previous'], 'the previous period is drawn shifted forward by its length, so both sit on the same line');
        $this->assertSame(50000.0, array_sum(array_column(array_column($data['series']['points'], 'values'), 'previous')));
    }

    public function test_no_comparison_percentage_is_invented_without_a_previous_period(): void
    {
        $this->order($this->ws, 'delivered', 1000);

        $kpis = $this->props()['economics']['orders']['data']['kpis'];

        $this->assertNull($kpis['income']['change']);
        $this->assertEquals(0, $kpis['income']['previous']);
    }

    public function test_money_in_two_currencies_is_never_added(): void
    {
        $this->order($this->ws, 'delivered', 1000, '-1 day', 'COP');
        $this->order($this->ws, 'delivered', 50, '-2 days', 'USD');

        $data = $this->props()['economics']['orders']['data'];

        $this->assertTrue($data['kpis']['mixedCurrencies']);
        $this->assertNull($data['kpis']['income']);
        $this->assertNull($data['kpis']['average']);
        $this->assertNull($data['series']);
        $this->assertSame(2, $data['kpis']['count']['value'], 'the records are still counted');
    }

    public function test_an_empty_period_is_a_real_zero(): void
    {
        $data = $this->props()['economics']['bookings']['data'];

        $this->assertSame([0.0, 0, null], [$data['kpis']['income']['value'], $data['kpis']['count']['value'], $data['kpis']['income']['change']]);
        $this->assertSame(0.0, $data['series']['total']);
    }

    public function test_the_location_filter_applies_to_the_money_too(): void
    {
        $north = $this->ws->locations()->create(['name' => 'Norte', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $south = $this->ws->locations()->create(['name' => 'Sur', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->appointment($this->ws, Appointment::COMPLETED, 80000, '-2 days', 'COP', $north);
        $this->appointment($this->ws, Appointment::COMPLETED, 50000, '-2 days', 'COP', $south);
        $this->order($this->ws, 'delivered', 1000, '-1 day', 'COP', $north);
        $this->order($this->ws, 'delivered', 5000, '-1 day', 'COP', $south);

        $all = $this->props()['economics'];
        $filtered = $this->props('location='.$north->id)['economics'];

        $this->assertSame([130000.0, 6000.0], [$all['bookings']['data']['kpis']['income']['value'], $all['orders']['data']['kpis']['income']['value']]);
        $this->assertSame([80000.0, 1000.0], [$filtered['bookings']['data']['kpis']['income']['value'], $filtered['orders']['data']['kpis']['income']['value']]);
    }

    public function test_each_module_needs_its_own_permission_and_plan(): void
    {
        $this->appointment($this->ws, Appointment::COMPLETED, 80000);
        $this->order($this->ws, 'delivered', 1000);

        $orders = $this->props('', 'solo_pedidos');
        $this->assertNull($orders['economics']['bookings']);
        $this->assertSame(1000.0, $orders['economics']['orders']['data']['kpis']['income']['value']);
        $this->assertTrue($orders['sources']['economics']);

        $agenda = $this->props('', 'solo_agenda');
        $this->assertNull($agenda['economics']['orders']);
        $this->assertSame(80000.0, $agenda['economics']['bookings']['data']['kpis']['income']['value']);

        $none = $this->props('', 'solo_resumen');
        $this->assertSame([null, null], [$none['economics']['bookings'], $none['economics']['orders']]);
        $this->assertFalse($none['sources']['economics']);

        $this->contractModules($this->ws, ['dashboard', 'reports', 'customers']);
        $noModules = $this->props();
        $this->assertSame([null, null], [$noModules['economics']['bookings'], $noModules['economics']['orders']]);
        $this->assertFalse($noModules['sources']['economics']);
    }

    public function test_the_plan_decides_which_economic_blocks_exist_and_the_two_modules_do_not_depend_on_each_other(): void
    {
        $this->appointment($this->ws, Appointment::COMPLETED, 80000);
        $this->order($this->ws, 'delivered', 1000);

        $this->contractModules($this->ws, ['dashboard', 'reports', 'bookings', 'service_catalog', 'professionals']);
        $onlyBookings = $this->props();
        $this->assertSame(80000.0, $onlyBookings['economics']['bookings']['data']['kpis']['income']['value']);
        $this->assertNull($onlyBookings['economics']['orders'], 'no orders module: the block does not exist and no order figure is computed');
        $this->assertSame(['economics' => true, 'bookings' => true, 'orders' => false], array_intersect_key($onlyBookings['sources'], array_flip(['economics', 'bookings', 'orders'])));

        $this->contractModules($this->ws, ['dashboard', 'reports', 'orders', 'product_catalog']);
        $onlyOrders = $this->props();
        $this->assertNull($onlyOrders['economics']['bookings'], 'no agenda: the block does not exist');
        $this->assertSame(1000.0, $onlyOrders['economics']['orders']['data']['kpis']['income']['value']);

        $this->contractModules($this->ws, ['dashboard', 'reports', 'bookings', 'service_catalog', 'professionals', 'orders', 'product_catalog']);
        $both = $this->props();
        $this->assertSame([80000.0, 1000.0], [$both['economics']['bookings']['data']['kpis']['income']['value'], $both['economics']['orders']['data']['kpis']['income']['value']]);

        $this->contractModules($this->ws, ['dashboard', 'reports']);
        $neither = $this->props();
        $this->assertSame([null, null], [$neither['economics']['bookings'], $neither['economics']['orders']]);
        $this->assertFalse($neither['sources']['economics']);
    }

    public function test_two_workspaces_with_different_data_never_mix_their_figures(): void
    {
        $this->appointment($this->ws, Appointment::COMPLETED, 10000);
        $this->appointment($this->other, Appointment::COMPLETED, 99000);
        $this->appointment($this->other, Appointment::COMPLETED, 1000);
        $this->order($this->ws, 'delivered', 500);
        $this->order($this->other, 'delivered', 7000);

        $mine = $this->props()['economics'];

        $this->assertSame([10000.0, 1, 500.0, 1], [$mine['bookings']['data']['kpis']['income']['value'], $mine['bookings']['data']['kpis']['count']['value'], $mine['orders']['data']['kpis']['income']['value'], $mine['orders']['data']['kpis']['count']['value']]);

        $this->app['auth']->forgetGuards();
        $user = User::factory()->create();
        $this->other->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->other->id]);
        $theirs = $this->get('/reports?tab=economics')->assertOk()->viewData('page')['props']['economics'];

        $this->assertSame([100000.0, 2, 7000.0, 1], [$theirs['bookings']['data']['kpis']['income']['value'], $theirs['bookings']['data']['kpis']['count']['value'], $theirs['orders']['data']['kpis']['income']['value'], $theirs['orders']['data']['kpis']['count']['value']]);
    }

    public function test_another_workspace_cannot_be_read_by_asking_for_it(): void
    {
        $this->order($this->other, 'delivered', 888000);
        $this->order($this->ws, 'delivered', 1000);

        $this->app['auth']->forgetGuards();
        $this->actAsMember($this->ws);

        $this->get('/reports?tab=economics&workspace='.$this->other->id)->assertForbidden();
        $this->get('/reports?tab=economics&workspace=all')->assertForbidden();
        $this->assertSame(1000.0, $this->props()['economics']['orders']['data']['kpis']['income']['value']);
    }

    public function test_a_user_of_another_workspace_gets_only_their_own_money(): void
    {
        $this->order($this->ws, 'delivered', 1000);
        $this->order($this->other, 'delivered', 4000);
        $this->app['auth']->forgetGuards();
        $user = User::factory()->create();
        $this->other->users()->attach($user->id, ['role' => 'admin']);
        $this->actingAs($user)->withSession(['workspace_id' => $this->other->id]);

        $data = $this->get('/reports?tab=economics')->assertOk()->viewData('page')['props']['economics']['orders']['data'];

        $this->assertSame(4000.0, $data['kpis']['income']['value']);
    }
}
