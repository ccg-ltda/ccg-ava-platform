<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Block 3: products and services are assigned to locations (sedes) from their own forms, a historical product with no location is flagged (never auto-assigned),
 * and a member can be limited to selected locations, enforced on the SERVER in every route that reads or writes location data. "All" is the default; an empty
 * selection means no location. Everything runs through HTTP, the permission middleware and the module middleware, not through isolated methods.
 */
class LocationScopeProductsAndServicesTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'locations', 'product_catalog', 'orders', 'service_catalog', 'professionals', 'bookings', 'users_roles', 'reports'];

    private Workspace $ws;

    private Workspace $other;

    private Location $norte;

    private Location $sur;

    private User $admin;

    private User $limited;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->ws = $this->bookingWorkspace('TIENDA', self::MODULES);
        $this->other = $this->bookingWorkspace('OTRA', self::MODULES);
        $this->norte = $this->ws->locations()->create(['name' => 'Norte', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->sur = $this->ws->locations()->create(['name' => 'Sur', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->admin = $this->member($this->ws, 'admin');
        $this->limited = $this->member($this->ws, 'admin');
        app(LocationScope::class)->save($this->ws, $this->limited, 'selected', [$this->norte->id]);
        $this->service = $this->makeService($this->ws, ['name' => 'Consulta', 'duration_minutes' => 30]);
        $this->makeProfessional($this->ws, [$this->service]);
    }

    private function as(User $user, ?Workspace $workspace = null): void
    {
        $this->actingAs($user)->withSession(['workspace_id' => ($workspace ?? $this->ws)->id]);
    }

    /** A product offered in these locations (none = the historical "offered everywhere"). */
    private function product(string $name, array $locations = [], bool $tracks = false): Product
    {
        $product = $this->ws->products()->create(['name' => $name, 'price' => 1000, 'is_active' => true, 'tracks_inventory' => $tracks]);
        $product->locations()->sync(collect($locations)->mapWithKeys(fn ($id) => [$id => ['workspace_id' => $this->ws->id]])->all());

        return $product;
    }

    private function productPayload(array $override = []): array
    {
        return $override + ['name' => 'Nuevo', 'price' => '2500', 'location_ids' => [$this->norte->id]];
    }

    private function order(?Location $location, string $name = 'Ana'): Order
    {
        static $n = 0;

        return $this->ws->orders()->create(['number' => ++$n, 'customer_name' => $name, 'status' => 'received', 'delivery_method' => 'pickup', 'total' => 1000, 'currency' => 'COP', 'location_id' => $location?->id, 'location_name' => $location?->name, 'status_changed_at' => now()]);
    }

    // --- products: the assignment is mandatory for a new product ----------------------------------------------------------------

    public function test_a_new_product_needs_an_active_location_of_this_workspace_and_may_have_several(): void
    {
        $this->as($this->admin);
        $closed = $this->ws->locations()->create(['name' => 'Cerrada', 'address' => 'x', 'city' => 'y', 'is_active' => false]);
        $foreign = $this->other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);

        $this->post('/catalog', ['name' => 'Sin sede', 'price' => '1000'])->assertSessionHasErrors('location_ids');
        $this->post('/catalog', ['name' => 'Sin sede', 'price' => '1000', 'location_ids' => []])->assertSessionHasErrors('location_ids');
        $this->post('/catalog', $this->productPayload(['location_ids' => [$closed->id]]))->assertSessionHasErrors('location_ids');
        $this->post('/catalog', $this->productPayload(['location_ids' => [$foreign->id]]))->assertSessionHasErrors('location_ids.0');
        $this->post('/catalog', $this->productPayload(['location_ids' => [$this->norte->id, $closed->id]]))->assertSessionHasErrors('location_ids');
        $this->assertSame(0, $this->ws->products()->count());

        $this->post('/catalog', $this->productPayload(['name' => 'Una']))->assertSessionHasNoErrors();
        $this->post('/catalog', $this->productPayload(['name' => 'Dos', 'location_ids' => [$this->norte->id, $this->sur->id]]))->assertSessionHasNoErrors();

        $uno = $this->ws->products()->where('name', 'Una')->firstOrFail();
        $dos = $this->ws->products()->where('name', 'Dos')->firstOrFail();
        $this->assertSame([$this->norte->id], $uno->locations()->pluck('locations.id')->all());
        $this->assertEqualsCanonicalizing([$this->norte->id, $this->sur->id], $dos->locations()->pluck('locations.id')->all());
        $this->assertSame(2, $this->ws->products()->count(), 'the identity of a product is not duplicated per location');
        $this->assertTrue(AuditLog::where('resource_type', 'product')->where('workspace_id', $this->ws->id)->exists());
    }

    public function test_a_workspace_without_active_locations_keeps_creating_products_without_one(): void
    {
        $this->as($this->member($this->other, 'admin'), $this->other);

        $this->post('/catalog', ['name' => 'General', 'price' => '1000'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->other->products()->count());
    }

    // --- historical products ----------------------------------------------------------------------------------------------------

    public function test_a_historical_product_without_a_location_is_flagged_never_assigned_and_is_fixed_on_purpose(): void
    {
        $legacy = $this->product('Antiguo');
        $order = $this->order($this->norte);
        $order->lines()->create(['workspace_id' => $this->ws->id, 'product_id' => $legacy->id, 'product_name' => 'Antiguo', 'unit_price' => 1000, 'quantity' => 1, 'line_total' => 1000, 'currency' => 'COP']);
        $this->as($this->admin);

        $this->get('/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('products.data.0.name', 'Antiguo')->where('products.data.0.needsLocation', true)->where('products.data.0.locationIds', []));
        $this->assertSame(0, DB::table('location_product')->count(), 'nothing was assigned by itself');

        // Saving it asks for a location (the explicit way to review it); with one it is fixed and everything else is intact.
        $this->put("/catalog/{$legacy->id}", ['name' => 'Antiguo', 'price' => '1000'])->assertSessionHasErrors('location_ids');
        $this->put("/catalog/{$legacy->id}", ['name' => 'Antiguo', 'price' => '1000', 'location_ids' => [$this->sur->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->sur->id], $legacy->locations()->pluck('locations.id')->all());
        $this->assertSame(1, $order->fresh()->lines()->count());
        $this->get('/catalog')->assertInertia(fn ($page) => $page->where('products.data.0.needsLocation', false));
    }

    // --- products: a member limited to selected locations ----------------------------------------------------------------------

    public function test_a_limited_member_only_reads_and_changes_products_of_their_locations_whatever_id_they_send(): void
    {
        $norteOnly = $this->product('Solo norte', [$this->norte->id]);
        $surOnly = $this->product('Solo sur', [$this->sur->id]);
        $both = $this->product('En ambas', [$this->norte->id, $this->sur->id]);
        $legacy = $this->product('Antiguo');
        $this->as($this->limited);

        $this->get('/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('products.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['En ambas', 'Solo norte'])
            ->where('locations', fn ($places) => collect($places)->pluck('id')->all() === [$this->norte->id]));
        $this->get("/catalog/{$norteOnly->id}")->assertOk();
        $this->get("/catalog/{$both->id}")->assertOk();
        $this->get("/catalog/{$surOnly->id}")->assertForbidden();
        $this->get("/catalog/{$legacy->id}")->assertForbidden();
        $this->get("/catalog/{$surOnly->id}/image")->assertForbidden();

        $edit = fn (Product $p, array $loc) => $this->put("/catalog/{$p->id}", ['name' => $p->name.' x', 'price' => '1', 'location_ids' => $loc]);
        $edit($norteOnly, [$this->norte->id])->assertSessionHasNoErrors();
        $edit($surOnly, [$this->norte->id])->assertForbidden();
        $edit($both, [$this->norte->id])->assertForbidden(); // it is also offered in Sur: changing it would change Sur
        $edit($legacy, [$this->norte->id])->assertForbidden(); // offered everywhere = not theirs
        $edit($norteOnly, [$this->sur->id])->assertSessionHasErrors('location_ids'); // can not offer it in a location that is not theirs
        $this->post("/catalog/{$surOnly->id}/deactivate")->assertForbidden();
        $this->post("/catalog/{$both->id}/deactivate")->assertForbidden();
        $this->post("/catalog/{$norteOnly->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertTrue($surOnly->fresh()->is_active);

        $this->post('/catalog', $this->productPayload(['name' => 'En sur', 'location_ids' => [$this->sur->id]]))->assertSessionHasErrors('location_ids');
        $this->post('/catalog', $this->productPayload(['name' => 'En norte']))->assertSessionHasNoErrors();
    }

    public function test_the_stock_of_another_location_or_the_general_stock_is_not_reachable_by_a_limited_member(): void
    {
        $tracked = $this->product('Con stock', [$this->norte->id, $this->sur->id], true);
        $surOnly = $this->product('Solo sur', [$this->sur->id], true);
        $this->as($this->limited);

        $this->post("/catalog/{$tracked->id}/stock/receive", ['location_id' => $this->norte->id, 'quantity' => 5])->assertSessionHasNoErrors();
        $this->post("/catalog/{$tracked->id}/stock/receive", ['location_id' => $this->sur->id, 'quantity' => 5])->assertSessionHasErrors('location_id')->assertSessionMissing('success');
        $this->post("/catalog/{$tracked->id}/stock/receive", ['quantity' => 5])->assertStatus(403);
        $this->post("/catalog/{$surOnly->id}/stock/receive", ['location_id' => $this->norte->id, 'quantity' => 5])->assertForbidden();
        $this->put("/catalog/{$tracked->id}/stock/control", ['tracks_inventory' => false])->assertForbidden(); // it changes the product everywhere

        $this->assertSame(5, (int) InventoryStock::where('product_id', $tracked->id)->where('location_id', $this->norte->id)->value('quantity'));
        $this->assertSame(0, InventoryStock::where('product_id', $tracked->id)->where('location_id', $this->sur->id)->count());

        // The list does not leak the figures of Sur either.
        $this->get('/catalog?place='.$this->sur->id)->assertOk()->assertInertia(fn ($page) => $page->where('inventory.place', ''));
    }

    // --- orders ----------------------------------------------------------------------------------------------------------------

    public function test_a_limited_member_only_sees_and_moves_the_orders_of_their_locations(): void
    {
        $mine = $this->order($this->norte, 'Mía');
        $theirs = $this->order($this->sur, 'Ajena');
        $nowhere = $this->order(null, 'Sin sede');
        $this->as($this->limited);

        $this->get('/orders')->assertOk()->assertInertia(fn ($page) => $page->where('orders.data', fn ($rows) => collect($rows)->pluck('customerName')->all() === ['Mía']));
        $this->get("/orders/{$mine->id}")->assertOk();
        $this->get("/orders/{$theirs->id}")->assertForbidden();
        $this->get("/orders/{$nowhere->id}")->assertForbidden();
        $this->post("/orders/{$theirs->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->post("/orders/{$nowhere->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->put("/orders/{$theirs->id}", ['customer_name' => 'Cambiado'])->assertForbidden();
        $this->assertSame('received', $theirs->fresh()->status);
        $this->post("/orders/{$mine->id}/status", ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $mine->fresh()->status);

        // Placing an order: one of their locations is required and the product must be offered there.
        $product = $this->product('Solo norte', [$this->norte->id]);
        $line = ['product_id' => $product->id, 'quantity' => 1];
        $base = ['customer_name' => 'Cliente', 'delivery_method' => 'pickup', 'lines' => [$line]];
        $this->post('/orders', $base)->assertSessionHasErrors('location_id');
        $this->post('/orders', $base + ['location_id' => $this->sur->id])->assertSessionHasErrors('location_id');
        $this->assertSame(3, $this->ws->orders()->count());
        $this->post('/orders', $base + ['location_id' => $this->norte->id])->assertSessionHasNoErrors();
        $this->assertSame(4, $this->ws->orders()->count());

        // Everybody else sees them all, as before.
        $this->as($this->admin);
        $this->get('/orders')->assertInertia(fn ($page) => $page->has('orders.data', 4));
    }

    // --- services ---------------------------------------------------------------------------------------------------------------

    public function test_a_service_is_assigned_to_locations_from_its_own_form_and_the_server_validates_them(): void
    {
        $this->as($this->admin);
        $closed = $this->ws->locations()->create(['name' => 'Cerrada', 'address' => 'x', 'city' => 'y', 'is_active' => false]);
        $foreign = $this->other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $payload = ['name' => 'Masaje', 'duration_minutes' => 45, 'price' => '30'];

        $this->post('/services', $payload + ['location_ids' => [$foreign->id]])->assertSessionHasErrors('location_ids.0');
        $this->post('/services', $payload + ['location_ids' => [$closed->id]])->assertSessionHasErrors('location_ids');
        $this->post('/services', $payload + ['location_ids' => ['x']])->assertSessionHasErrors();
        $this->assertSame(0, $this->ws->services()->where('name', 'Masaje')->count());

        $this->post('/services', $payload + ['location_ids' => [$this->norte->id]])->assertSessionHasNoErrors();
        $masaje = $this->ws->services()->where('name', 'Masaje')->firstOrFail();
        $this->assertSame([$this->norte->id], $masaje->locations()->pluck('locations.id')->all());

        // Edit: add Sur, then remove Norte.
        $this->put("/services/{$masaje->id}", $payload + ['location_ids' => [$this->norte->id, $this->sur->id]])->assertSessionHasNoErrors();
        $this->put("/services/{$masaje->id}", $payload + ['location_ids' => [$this->sur->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->sur->id], $masaje->locations()->pluck('locations.id')->all());
        $this->assertSame(1, DB::table('location_service')->where('service_id', $masaje->id)->count(), 'one row per location: no duplicates');

        // Where it is offered decides the availability, and the page shows it and who may manage it.
        $this->get('/services')->assertInertia(fn ($page) => $page->where('services', fn ($rows) => collect($rows)->firstWhere('name', 'Masaje')['locationIds'] === [$this->sur->id]));
        $this->assertTrue(AuditLog::where('resource_type', 'service')->where('workspace_id', $this->ws->id)->get()->contains(fn ($log) => str_contains(json_encode($log->changes), 'Sedes donde se ofrece')));
    }

    public function test_a_historical_service_without_locations_is_reported_as_offered_everywhere_and_nothing_is_assigned(): void
    {
        $this->as($this->admin);

        $this->get('/services')->assertInertia(fn ($page) => $page->where('services.0.name', 'Consulta')->where('services.0.offeredEverywhere', true)->where('services.0.locationIds', []));
        $this->put("/services/{$this->service->id}", ['name' => 'Consulta', 'duration_minutes' => 30, 'price' => '25'])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('location_service')->count());
        $this->assertSame(25.0, (float) $this->service->fresh()->price, 'prices and durations of existing services are untouched');
    }

    public function test_a_limited_member_only_manages_services_offered_only_in_their_locations(): void
    {
        $inNorte = $this->makeService($this->ws, ['name' => 'Solo norte']);
        $inSur = $this->makeService($this->ws, ['name' => 'Solo sur']);
        $inBoth = $this->makeService($this->ws, ['name' => 'Ambas']);
        $inNorte->locations()->sync([$this->norte->id => ['workspace_id' => $this->ws->id]]);
        $inSur->locations()->sync([$this->sur->id => ['workspace_id' => $this->ws->id]]);
        $inBoth->locations()->sync([$this->norte->id => ['workspace_id' => $this->ws->id], $this->sur->id => ['workspace_id' => $this->ws->id]]);
        $this->as($this->limited);
        $edit = fn (Service $s, array $loc) => $this->put("/services/{$s->id}", ['name' => $s->name, 'duration_minutes' => 30, 'price' => '9', 'location_ids' => $loc]);

        $this->get('/services')->assertInertia(fn ($page) => $page->where('services', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Ambas', 'Consulta', 'Solo norte'])
            ->where('locations', fn ($places) => collect($places)->pluck('id')->all() === [$this->norte->id]));
        $edit($inNorte, [$this->norte->id])->assertSessionHasNoErrors();
        $edit($inSur, [$this->norte->id])->assertForbidden();
        $edit($inBoth, [$this->norte->id])->assertForbidden();
        $edit($this->service, [$this->norte->id])->assertForbidden(); // offered everywhere (historical): not theirs
        $this->post("/services/{$inSur->id}/deactivate")->assertForbidden();
        $this->post("/services/{$inBoth->id}/deactivate")->assertForbidden();
        $edit($inNorte, [$this->sur->id])->assertSessionHasErrors('location_ids');
        $this->post('/services', ['name' => 'Nuevo', 'duration_minutes' => 30])->assertSessionHasErrors('location_ids'); // "no sedes" would mean everywhere
        $this->post('/services', ['name' => 'Nuevo', 'duration_minutes' => 30, 'location_ids' => [$this->sur->id]])->assertSessionHasErrors('location_ids');
        $this->post('/services', ['name' => 'Nuevo', 'duration_minutes' => 30, 'location_ids' => [$this->norte->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->sur->id], $inSur->locations()->pluck('locations.id')->all());
        $this->assertTrue($inSur->fresh()->is_active);
    }

    // --- locations ---------------------------------------------------------------------------------------------------------------

    public function test_a_limited_member_only_sees_and_edits_their_locations_and_can_not_create_one(): void
    {
        $this->as($this->limited);

        $this->get('/locations')->assertOk()->assertInertia(fn ($page) => $page->where('locations', fn ($rows) => collect($rows)->pluck('name')->all() === ['Norte']));
        $this->put("/locations/{$this->sur->id}", ['name' => 'Hackeada', 'address' => 'x', 'city' => 'y'])->assertForbidden();
        $this->post("/locations/{$this->sur->id}/deactivate")->assertForbidden();
        $this->post('/locations', ['name' => 'Nueva', 'address' => 'x', 'city' => 'y'])->assertForbidden();
        $this->put("/locations/{$this->norte->id}", ['name' => 'Norte renombrada', 'address' => 'x', 'city' => 'y'])->assertSessionHasNoErrors();
        $this->assertSame('Sur', $this->sur->fresh()->name);
        $this->assertTrue($this->sur->fresh()->is_active);
        $this->assertSame(2, $this->ws->locations()->count());
    }

    // --- appointments and professionals ------------------------------------------------------------------------------------------

    private function book(Location $location): Appointment
    {
        $this->as($this->admin);
        $this->post('/bookings', ['service_id' => $this->service->id, 'location_id' => $location->id, 'starts_at' => '2026-10-13T1'.($location->is($this->norte) ? '0' : '1').':00:00Z', 'customer' => ['name' => 'Laura '.$location->name, 'phone' => '+57300111'.($location->is($this->norte) ? '2233' : '4455')]])->assertSessionHasNoErrors();

        return Appointment::where('location_id', $location->id)->latest('id')->firstOrFail();
    }

    public function test_a_limited_member_only_sees_books_and_changes_appointments_of_their_locations(): void
    {
        $mine = $this->book($this->norte);
        $theirs = $this->book($this->sur);
        $this->as($this->limited);

        $this->get('/bookings?from=2026-10-12&days=7')->assertOk()->assertInertia(fn ($page) => $page->where('appointments', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id]));
        $this->post("/bookings/{$theirs->id}/cancel", [])->assertForbidden();
        $this->post("/bookings/{$theirs->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->post("/bookings/{$theirs->id}/customer-confirmation")->assertForbidden();
        $this->assertNotSame('cancelled', $theirs->fresh()->status);
        $this->post("/bookings/{$mine->id}/cancel", [])->assertSessionHasNoErrors();

        // A new appointment in another location, and a search for free hours there, are refused before anything is looked at.
        $payload = fn (Location $l) => ['service_id' => $this->service->id, 'location_id' => $l->id, 'starts_at' => '2026-10-14T10:00:00Z', 'customer' => ['name' => 'Otra', 'phone' => '+573009998877']];
        $this->post('/bookings', $payload($this->sur))->assertForbidden();
        $this->assertSame(0, Appointment::where('location_id', $this->sur->id)->where('starts_at', '2026-10-14 10:00:00')->count());
        $this->getJson('/bookings/availability?service_id='.$this->service->id.'&location_id='.$this->sur->id.'&from=2026-10-14')->assertForbidden();
        $this->post('/bookings', $payload($this->norte))->assertSessionHasNoErrors();
    }

    public function test_a_limited_member_with_no_authorized_location_can_not_book_as_if_the_workspace_had_none(): void
    {
        app(LocationScope::class)->save($this->ws, $this->limited, 'selected', []);
        $this->as($this->limited);

        $this->post('/bookings', ['service_id' => $this->service->id, 'starts_at' => '2026-10-14T10:00:00Z', 'customer' => ['name' => 'Otra', 'phone' => '+573009998877']])->assertForbidden();
        $this->assertSame(0, Appointment::count());
        $this->get('/locations')->assertInertia(fn ($page) => $page->has('locations', 0));
        $this->get('/catalog')->assertInertia(fn ($page) => $page->has('products.data', 0));
    }

    public function test_a_limited_member_only_rewrites_the_hours_of_their_locations_for_a_professional(): void
    {
        $professional = $this->ws->professionals()->firstOrFail();
        $professional->scheduleRules()->delete();
        $rule = fn (?Location $l, string $from) => ['workspace_id' => $this->ws->id, 'weekday' => 2, 'starts_at' => $from, 'ends_at' => '12:00:00', 'location_id' => $l?->id];
        $professional->scheduleRules()->create($rule($this->norte, '08:00:00'));
        $professional->scheduleRules()->create($rule($this->sur, '09:00:00'));
        $professional->scheduleRules()->create($rule(null, '10:00:00'));
        $this->as($this->limited);
        $base = ['name' => $professional->name, 'service_ids' => [$this->service->id]];

        $this->put("/professionals/{$professional->id}", $base + ['schedule' => [['weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '12:00', 'location_id' => $this->sur->id]]])->assertForbidden();
        $this->put("/professionals/{$professional->id}", $base + ['schedule' => [['weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '12:00', 'location_id' => null]]])->assertForbidden();
        $this->put("/professionals/{$professional->id}", $base + ['schedule' => [['weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '12:00', 'location_id' => $this->norte->id]]])->assertSessionHasNoErrors();

        $rules = $professional->scheduleRules()->get();
        $this->assertSame(3, $rules->count());
        $this->assertTrue($rules->contains(fn ($r) => $r->location_id === $this->sur->id && $r->weekday === 2), 'the hours of Sur are untouched');
        $this->assertTrue($rules->contains(fn ($r) => $r->location_id === null && $r->weekday === 2), 'the hours that apply everywhere are untouched');
        $this->assertTrue($rules->contains(fn ($r) => $r->location_id === $this->norte->id && $r->weekday === 3), 'their own hours were replaced');
    }

    // --- reports -----------------------------------------------------------------------------------------------------------------

    public function test_a_limited_member_gets_the_operational_reports_of_their_locations_only(): void
    {
        $this->order($this->sur);
        $this->order($this->norte);
        $this->as($this->admin);
        $this->get('/reports')->assertOk()->assertInertia(fn ($page) => $page->where('sources.orders', true)->where('operations.orders.data.kpis.total', 2));

        // Since the per-location filter exists (ReportsByLocationTest covers every figure) they receive the report computed for THEIR locations, never the whole Workspace.
        $this->as($this->limited);
        $this->get('/reports')->assertOk()->assertInertia(fn ($page) => $page->where('sources.orders', true)->where('operations.orders.data.kpis.total', 1)->where('locations.limited', true));
        $this->get('/reports?location='.$this->sur->id)->assertForbidden();
    }

    // --- administration of the scope ---------------------------------------------------------------------------------------------

    public function test_all_is_the_default_and_an_administrator_limits_a_member_with_audit_and_validation(): void
    {
        $member = $this->member($this->ws, 'admin');
        $this->assertNull(app(LocationScope::class)->allowedIds($this->ws, $member), 'a member nobody configured keeps working with every location');
        $foreign = $this->other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->as($this->admin);

        $this->getJson("/users/{$member->id}/locations")->assertOk()->assertJsonPath('scope', 'all')->assertJsonPath('mayChange', true)->assertJsonCount(2, 'locations');
        $this->putJson("/users/{$member->id}/locations", ['scope' => 'selected', 'location_ids' => [$foreign->id]])->assertStatus(422);
        $this->putJson("/users/{$member->id}/locations", ['scope' => 'nada'])->assertStatus(422);
        $this->put("/users/{$member->id}/locations", ['scope' => 'selected', 'location_ids' => [$this->sur->id]])->assertSessionHasNoErrors();

        $this->assertSame([$this->sur->id], app(LocationScope::class)->allowedIds($this->ws, $member->fresh()));
        $this->assertSame(0, DB::table('workspace_user_locations')->where('location_id', $foreign->id)->count());
        $this->assertTrue(AuditLog::where('resource_type', 'membership')->where('workspace_id', $this->ws->id)->get()->contains(fn ($log) => str_contains(json_encode($log->changes), 'Alcance de sedes')));

        // Back to all: the rows go away and nothing else changes.
        $this->put("/users/{$member->id}/locations", ['scope' => 'all', 'location_ids' => [$this->sur->id]])->assertSessionHasNoErrors();
        $this->assertNull(app(LocationScope::class)->allowedIds($this->ws, $member->fresh()));
        $this->assertSame(0, DB::table('workspace_user_locations')->where('user_id', $member->id)->count());

        // Empty selection = no location at all (never "all").
        $this->put("/users/{$member->id}/locations", ['scope' => 'selected', 'location_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame([], app(LocationScope::class)->allowedIds($this->ws, $member->fresh()));
    }

    public function test_only_who_may_assign_roles_and_is_not_limited_can_change_a_scope_and_never_across_workspaces(): void
    {
        $member = $this->member($this->ws, 'admin');
        $outsider = $this->member($this->other, 'admin');

        // A limited member can not change anybody's scope (nor widen their own).
        $this->as($this->limited);
        $this->put("/users/{$member->id}/locations", ['scope' => 'all'])->assertForbidden();
        $this->put("/users/{$this->limited->id}/locations", ['scope' => 'all'])->assertForbidden();
        $this->assertSame([$this->norte->id], app(LocationScope::class)->allowedIds($this->ws, $this->limited->fresh()));

        // A role without `assign-roles` is refused by the route.
        $viewer = $this->member($this->ws, 'cliente');
        $this->as($viewer);
        $this->put("/users/{$member->id}/locations", ['scope' => 'selected', 'location_ids' => []])->assertForbidden();

        // The administrator of ANOTHER Workspace never reaches a member of this one.
        $this->as($outsider, $this->other);
        $this->put("/users/{$member->id}/locations", ['scope' => 'selected', 'location_ids' => []])->assertNotFound();
        $this->getJson("/users/{$member->id}/locations")->assertNotFound();
        $this->assertNull(app(LocationScope::class)->allowedIds($this->ws, $member->fresh()));
    }

    public function test_the_scope_never_widens_roles_or_modules_and_the_internal_administration_is_never_limited(): void
    {
        // A role without view-orders stays out of Pedidos with every location; a module outside the plan stays closed.
        $viewer = $this->member($this->ws, 'cliente');
        $this->as($viewer);
        $this->get('/orders')->assertForbidden();

        $noOrders = $this->bookingWorkspace('SINPED', ['dashboard', 'locations']);
        $user = $this->member($noOrders, 'admin');
        $this->as($user, $noOrders);
        $this->get('/orders')->assertForbidden();

        // The administrative Workspace is never limited, even if a row says so.
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $devUser = User::factory()->create();
        $dev->users()->attach($devUser->id, ['role' => 'plataforma']);
        DB::table('workspace_user')->where('workspace_id', $dev->id)->where('user_id', $devUser->id)->update(['location_scope' => 'selected']);
        $this->assertNull(app(LocationScope::class)->allowedIds($dev, $devUser));

        $root = User::factory()->create(['is_superuser' => true]);
        $this->ws->users()->attach($root->id, ['role' => 'admin']);
        DB::table('workspace_user')->where('workspace_id', $this->ws->id)->where('user_id', $root->id)->update(['location_scope' => 'selected']);
        $this->assertSame([], app(LocationScope::class)->allowedIds($this->ws, $root), 'inside a CLIENT Workspace a superuser is a member like any other: the membership decides');
    }

    public function test_the_unconfigured_state_and_a_database_without_the_migration_keep_the_previous_behavior(): void
    {
        $scope = app(LocationScope::class);

        $this->assertTrue($scope->ready());
        $this->assertNull($scope->allowedIds($this->ws, $this->admin));
        $this->assertTrue($scope->allows($this->ws, $this->admin, null), 'unrestricted members keep working with data of no location');
        $this->assertFalse($scope->allows($this->ws, $this->limited, null), 'a limited member never works with data of no location');
        $this->assertFalse($scope->allows($this->ws, $this->limited, $this->sur->id));
        $this->assertTrue($scope->allows($this->ws, $this->limited, $this->norte->id));
    }
}
