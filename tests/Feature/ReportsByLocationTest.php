<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Reportes filtered by location, decided on the SERVER and applied inside every query (totals, rankings, secondary queries, counts), not to the rows afterwards.
 * Fixed data: Norte has 2 completed appointments (80 000 + 120 000), 2 delivered orders (1 000 + 2 000), stock 10 + 3 units of 2 products and 5 units sold;
 * Sur has 1 completed appointment (50 000), 1 order (5 000) and 7 units of stock; one appointment, one order and one stock row belong to no location (history).
 * Customers carry no location, so they never change with the filter. There is no export of these reports in the application (nothing to filter there).
 */
class ReportsByLocationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'reports', 'bookings', 'orders', 'product_catalog', 'locations', 'service_catalog', 'professionals', 'customers'];

    private Workspace $ws;

    private Location $norte;

    private Location $sur;

    private User $admin;

    private User $limitedNorte;

    private User $both;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->ws = $this->bookingWorkspace('TIENDA', self::MODULES);
        $this->norte = $this->ws->locations()->create(['name' => 'Norte', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->sur = $this->ws->locations()->create(['name' => 'Sur', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->admin = $this->member($this->ws, 'admin');
        $this->limitedNorte = $this->member($this->ws, 'admin');
        $this->both = $this->member($this->ws, 'admin');
        $scope = app(LocationScope::class);
        $scope->save($this->ws, $this->limitedNorte, 'selected', [$this->norte->id]);
        $scope->save($this->ws, $this->both, 'selected', [$this->norte->id, $this->sur->id]);
        $this->seedData($this->ws, true);
    }

    private function seedData(Workspace $ws, bool $withLocations): void
    {
        $service = $ws->services()->create(['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 1]);
        $pro = $ws->professionals()->create(['name' => 'Ana', 'is_active' => true]);
        $north = $withLocations ? $this->norte : null;
        $south = $withLocations ? $this->sur : null;
        $n = 0;

        $appointment = function (?Location $location, string $service_name, int $price, string $when = '-2 days') use ($ws, $service, $pro, &$n) {
            $customer = $ws->bookingCustomers()->create(['name' => 'Cliente '.(++$n), 'phone' => '+57300000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
            $start = now()->modify($when)->setTime(10, 0)->utc();
            $ws->appointments()->create([
                'service_id' => $service->id, 'professional_id' => $pro->id, 'customer_id' => $customer->id, 'service_name' => $service_name, 'professional_name' => 'Ana',
                'location_id' => $location?->id, 'location_name' => $location?->name, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'blocks_until' => $start->addMinutes(30),
                'status' => Appointment::COMPLETED, 'provider' => 'internal', 'sync_status' => 'none', 'channel' => 'admin', 'origin_channel' => 'admin', 'token_hash' => hash('sha256', "t{$n}".$ws->id),
                'price_amount' => $price, 'price_currency' => 'COP',
            ]);
        };

        $appointment($north, 'Consulta Norte', 80000);
        $appointment($north, 'Consulta Norte', 120000, '-3 days');
        $appointment($south, 'Masaje Sur', 50000);
        $appointment(null, 'Histórica', 9000);

        $order = function (?Location $location, int $total, string $status = 'delivered') use ($ws, &$n) {
            $ws->orders()->create(['number' => ++$n + $ws->id * 1000, 'customer_name' => 'C'.$n, 'status' => $status, 'delivery_method' => 'pickup', 'total' => $total, 'currency' => 'COP', 'location_id' => $location?->id, 'location_name' => $location?->name, 'status_changed_at' => now()]);
        };
        $order($north, 1000);
        $order($north, 2000);
        $order($south, 5000);
        $order(null, 700);

        $p1 = $ws->products()->create(['name' => 'Crema', 'price' => 10, 'is_active' => true, 'tracks_inventory' => true]);
        $p2 = $ws->products()->create(['name' => 'Jabón', 'price' => 10, 'is_active' => true, 'tracks_inventory' => true]);
        $p3 = $ws->products()->create(['name' => 'Sin configurar', 'price' => 10, 'is_active' => true, 'tracks_inventory' => true]);
        $stock = fn ($p, ?Location $l, int $qty, int $min = 0) => DB::table('inventory_stocks')->insert(['workspace_id' => $ws->id, 'product_id' => $p->id, 'location_id' => $l?->id, 'quantity' => $qty, 'min_quantity' => $min, 'created_at' => now(), 'updated_at' => now()]);
        $stock($p1, $north, 10);
        $stock($p2, $north, 3, 5);   // low in Norte
        $stock($p1, $south, 7);
        $stock($p2, null, 100);       // general stock: no location
        $move = fn ($p, ?Location $l, string $type, int $change) => DB::table('inventory_movements')->insert(['workspace_id' => $ws->id, 'product_id' => $p->id, 'location_id' => $l?->id, 'type' => $type, 'quantity_change' => $change, 'quantity_before' => 0, 'quantity_after' => 0, 'created_at' => now()]);
        $move($p1, $north, 'sale', -5);
        $move($p1, $south, 'sale', -2);
        $move($p1, $north, 'entry', 20);
        // The third product is offered in Sur (explicitly) but has no stock row there.
        $p3->locations()->sync([$this->sur->id => ['workspace_id' => $ws->id]]);
        $ws->customers()->create(['first_name' => 'Ana', 'phone' => '+573001230001', 'source' => 'manual', 'marketing_consent' => 'unknown']);
    }

    private function as(User $user, ?Workspace $ws = null): void
    {
        $this->actingAs($user)->withSession(['workspace_id' => ($ws ?? $this->ws)->id]);
    }

    /** @return array<string, mixed> the props of Reportes for a query string */
    private function props(string $query = ''): array
    {
        return $this->get('/reports?tab=agenda&'.$query)->assertOk()->viewData('page')['props'];
    }

    private function bookings(array $props): array
    {
        return $props['operations']['bookings']['data'];
    }

    // --- every part of the report follows the same filter ------------------------------------------------------------------------------

    public function test_without_a_filter_a_member_with_every_location_gets_the_whole_workspace_exactly_as_before(): void
    {
        $this->as($this->admin);
        $props = $this->props();

        $b = $this->bookings($props);
        $this->assertSame([4, 4], [$b['kpis']['total'], $b['kpis']['completed']]);
        $this->assertSame(259000.0, $b['kpis']['recordedValue']);
        $o = $props['operations']['orders']['data'];
        $this->assertSame([4, 8700.0], [$o['kpis']['total'], $o['kpis']['sales']]);
        $i = $props['operations']['inventory']['data'];
        $this->assertSame(120, $i['kpis']['units']); // 10 + 3 + 7 + 100 (the general stock counts when nothing is filtered)
        $this->assertSame(['Norte', 'Sur', 'Inventario general'], collect($i['byLocation'])->pluck('label')->sort()->values()->map(fn ($l) => $l)->sortBy(fn ($l) => ['Norte' => 0, 'Sur' => 1, 'Inventario general' => 2][$l])->values()->all());
        $this->assertEquals(['enabled' => true, 'limited' => false, 'selected' => null], collect($props['locations'])->only(['enabled', 'limited', 'selected'])->all());
        $this->assertSame(['Norte', 'Sur'], collect($props['locations']['options'])->pluck('name')->all());
    }

    public function test_choosing_a_location_filters_every_figure_the_rankings_and_the_secondary_queries(): void
    {
        $this->as($this->admin);
        $props = $this->props('location='.$this->norte->id);

        $b = $this->bookings($props);
        $this->assertSame([2, 2, 200000.0, 0], [$b['kpis']['total'], $b['kpis']['completed'], $b['kpis']['recordedValue'], $b['kpis']['upcoming']]);
        $this->assertSame(['Consulta Norte'], collect($b['byService'])->pluck('label')->all(), 'the ranking only knows the appointments of the location');
        $this->assertSame(2, collect($b['byStatus'])->firstWhere('key', 'completed')['value']);

        $o = $props['operations']['orders']['data'];
        $this->assertSame([2, 3000.0, 1500.0], [$o['kpis']['total'], $o['kpis']['sales'], $o['kpis']['averageTicket']]);

        $i = $props['operations']['inventory']['data'];
        $this->assertSame([13, 1, 5, 20], [$i['kpis']['units'], $i['kpis']['low'], $i['kpis']['sold'], $i['kpis']['entries']]);
        $this->assertSame(['Norte'], collect($i['byLocation'])->pluck('label')->all());
        $this->assertSame(2, $i['kpis']['tracked']);
        $this->assertSame(0, $i['kpis']['unconfigured'], 'the product offered only in Sur is not pending in Norte');
        $this->assertSame(['Jabón'], collect($i['attention'])->pluck('name')->all());
        $this->assertSame(5, collect($i['movements'])->firstWhere('key', 'sale')['value']);

        $props = $this->props('location='.$this->sur->id);
        $this->assertSame([1, 50000.0], [$this->bookings($props)['kpis']['total'], $this->bookings($props)['kpis']['recordedValue']]);
        $this->assertSame([1, 5000.0], [$props['operations']['orders']['data']['kpis']['total'], $props['operations']['orders']['data']['kpis']['sales']]);
        $sur = $props['operations']['inventory']['data'];
        $this->assertSame([7, 2, 1], [$sur['kpis']['units'], $sur['kpis']['sold'], $sur['kpis']['unconfigured']], 'Sur: its stock, its sales, and the product offered there without a stock row');

        // History without a location is attributed to no location: it only exists in the unfiltered report.
        $this->assertSame(9000, (int) Appointment::whereNull('location_id')->value('price_amount'));
    }

    public function test_customers_do_not_depend_on_the_location_and_the_page_says_which_sections_follow_the_filter(): void
    {
        $this->as($this->admin);
        $all = $this->props()['operations']['customers']['data']['kpis']['total'];
        $filtered = $this->props('location='.$this->norte->id)['operations']['customers']['data']['kpis']['total'];

        $this->assertSame([1, 1], [$all, $filtered]);
    }

    // --- authorization -----------------------------------------------------------------------------------------------------------------

    public function test_a_member_limited_to_one_location_always_gets_only_that_location_with_or_without_the_parameter(): void
    {
        $this->as($this->limitedNorte);

        $none = $this->props();
        $this->assertSame([2, 200000.0], [$this->bookings($none)['kpis']['total'], $this->bookings($none)['kpis']['recordedValue']]);
        $this->assertSame(3000.0, $none['operations']['orders']['data']['kpis']['sales']);
        $this->assertSame(13, $none['operations']['inventory']['data']['kpis']['units'], 'the general stock and Sur are not theirs');
        $this->assertEquals(['limited' => true, 'selected' => null], collect($none['locations'])->only(['limited', 'selected'])->all());
        $this->assertSame(['Norte'], collect($none['locations']['options'])->pluck('name')->all(), 'one location: the page shows no selector');

        $own = $this->props('location='.$this->norte->id);
        $this->assertSame($this->bookings($none)['kpis'], $this->bookings($own)['kpis']);
    }

    public function test_a_forged_foreign_or_unknown_location_is_refused_not_ignored(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        $foreign = $other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);

        $this->as($this->limitedNorte);
        $this->get('/reports?location='.$this->sur->id)->assertForbidden();
        $this->get('/reports?location='.$foreign->id)->assertForbidden();
        $this->get('/reports?location=999999')->assertForbidden();

        $this->as($this->admin);
        $this->get('/reports?location='.$foreign->id)->assertSessionHasErrors('location');
        $this->get('/reports?location=999999')->assertSessionHasErrors('location');
        $this->get('/reports?location=abc')->assertSessionHasErrors('location');
        $this->get('/reports?location=-1')->assertSessionHasErrors('location');
    }

    public function test_a_member_with_several_selected_locations_gets_their_union_and_may_narrow_to_one_of_them(): void
    {
        $inactive = $this->ws->locations()->create(['name' => 'Cerrada', 'address' => 'x', 'city' => 'y', 'is_active' => false]);
        $this->as($this->both);

        $union = $this->props();
        $this->assertSame([3, 250000.0], [$this->bookings($union)['kpis']['total'], $this->bookings($union)['kpis']['recordedValue']], 'Norte + Sur, without the history that has no location');
        $this->assertSame(8000.0, $union['operations']['orders']['data']['kpis']['sales']);
        $this->assertSame(['Norte', 'Sur'], collect($union['locations']['options'])->pluck('name')->all(), 'only the locations they are authorized for');

        $this->assertSame(1, $this->bookings($this->props('location='.$this->sur->id))['kpis']['total']);
        $this->get('/reports?location='.$inactive->id)->assertForbidden();
    }

    public function test_a_member_authorized_for_no_location_gets_empty_figures_not_the_whole_workspace(): void
    {
        $nobody = $this->member($this->ws, 'admin');
        app(LocationScope::class)->save($this->ws, $nobody, 'selected', []);
        $this->as($nobody);

        $props = $this->props();
        $this->assertSame(0, $this->bookings($props)['kpis']['total']);
        $this->assertSame(0, $props['operations']['orders']['data']['kpis']['total']);
        $this->assertSame(0, $props['operations']['inventory']['data']['kpis']['units']);
    }

    public function test_roles_and_modules_still_decide_what_the_filter_is_applied_to(): void
    {
        // Without view-orders the orders section is not even computed, whatever the location.
        Role::findOrCreate('analista', 'web')->syncPermissions(['view-dashboard', 'view-bookings']);
        $this->as($this->member($this->ws, 'analista'));
        $props = $this->props('location='.$this->norte->id);
        $this->assertNull($props['operations']['orders']);
        $this->assertNull($props['operations']['inventory']);
        $this->assertNotNull($props['operations']['bookings']);

        // A Workspace without the Sedes module has no filter and no selector (the parameter is ignored there).
        $plain = $this->bookingWorkspace('SINSEDES', ['dashboard', 'reports', 'bookings']);
        $this->as($this->member($plain, 'admin'), $plain);
        $this->assertEquals(['enabled' => false], collect($this->props('location=1')['locations'])->only(['enabled'])->all());
    }

    public function test_workspaces_never_mix_and_the_internal_workspace_has_no_location_filter(): void
    {
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        $this->seedOtherWorkspace($other);
        $this->as($this->admin);
        $props = $this->props();
        $this->assertSame(4, $this->bookings($props)['kpis']['total'], 'nothing of the other Workspace');

        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $root = User::factory()->create();
        $root->forceFill(['is_superuser' => true])->save();
        $dev->users()->attach($root->id, ['role' => 'admin']);
        DB::table('workspace_user')->where('workspace_id', $dev->id)->where('user_id', $root->id)->update(['location_scope' => 'selected']);
        $this->actingAs($root)->withSession(['workspace_id' => $dev->id]);
        $res = $this->get('/reports')->assertOk()->viewData('page')['props'];

        $this->assertEquals(['enabled' => true, 'limited' => false], collect($res['locations'])->only(['enabled', 'limited'])->all(), 'the internal Workspace is never limited, even if a row says so');
        $this->assertSame([], $res['locations']['options']);
    }

    private function seedOtherWorkspace(Workspace $other): void
    {
        $service = $other->services()->create(['name' => 'Ajeno', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 1]);
        $pro = $other->professionals()->create(['name' => 'Zoe', 'is_active' => true]);
        $location = $other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $customer = $other->bookingCustomers()->create(['name' => 'X', 'phone' => '+573009990000']);
        $start = now()->subDay()->setTime(10, 0)->utc();
        $other->appointments()->create(['service_id' => $service->id, 'professional_id' => $pro->id, 'customer_id' => $customer->id, 'service_name' => 'Ajeno', 'professional_name' => 'Zoe', 'location_id' => $location->id, 'location_name' => 'Ajena', 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'blocks_until' => $start->addMinutes(30), 'status' => Appointment::COMPLETED, 'provider' => 'internal', 'sync_status' => 'none', 'channel' => 'admin', 'origin_channel' => 'admin', 'token_hash' => 'zzz', 'price_amount' => 999999, 'price_currency' => 'COP']);
    }
}
