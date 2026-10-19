<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\Workspace;
use App\Orders\OrderNotifier;
use App\Orders\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Catálogo de productos and Pedidos (E-commerce and Delivery): the catalog never deletes, an order keeps what was bought as it was bought, the
 * server owns every price and total, the flow only moves along the allowed graph, and nothing crosses a Workspace.
 */
class CommerceTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'product_catalog', 'orders', 'locations', 'customers'];

    private Workspace $shop;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->shop = $this->bookingWorkspace('TIENDA', self::MODULES);
        $this->other = $this->bookingWorkspace('OTRA', self::MODULES);
        $this->shop->settingsOrDefault()->update(['currency' => 'COP']);
        Role::findOrCreate('operador', 'web')->syncPermissions(['view-orders', 'view-order-details', 'process-orders']);
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-orders', 'view-order-details', 'view-catalog']);
    }

    private function product(Workspace $workspace, string $name = 'Hamburguesa', string $price = '18500.00', array $extra = []): Product
    {
        return $workspace->products()->create($extra + ['name' => $name, 'price' => $price, 'sku' => null])->fresh();
    }

    private function as(string $role = 'admin', ?Workspace $workspace = null)
    {
        return $this->actAsMember($workspace ?? $this->shop, $role);
    }

    /** @return array<string, mixed> */
    private function payload(array $lines, array $extra = []): array
    {
        return $extra + ['customer_name' => 'Laura Pérez', 'customer_phone' => '+573001112233', 'delivery_method' => 'pickup', 'lines' => $lines];
    }

    private function place(array $lines, array $extra = []): Order
    {
        $this->post('/orders', $this->payload($lines, $extra))->assertSessionHasNoErrors()->assertSessionHas('success');

        return Order::orderByDesc('id')->firstOrFail();
    }

    // --- catalog -----------------------------------------------------------------------------------------------------------------------

    public function test_the_catalog_is_created_edited_searched_and_deactivated_but_never_deleted(): void
    {
        $this->as();
        $this->post('/catalog', ['name' => 'Hamburguesa', 'sku' => 'HAM-01', 'price' => '18500', 'category' => 'Comida'])->assertSessionHasNoErrors();
        $this->post('/catalog', ['name' => 'Gaseosa', 'price' => '4000.50', 'category' => 'Bebidas'])->assertSessionHasNoErrors();
        $burger = Product::where('sku', 'HAM-01')->firstOrFail();
        $this->assertSame('18500.00', $burger->price);

        $this->get('/catalog?search=ham')->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.sku', 'HAM-01'));
        $this->get('/catalog?category=Bebidas')->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.name', 'Gaseosa'));

        $this->put("/catalog/{$burger->id}", ['name' => 'Hamburguesa doble', 'sku' => 'HAM-01', 'price' => '21000'])->assertSessionHasNoErrors();
        $this->assertSame('Hamburguesa doble', $burger->fresh()->name);

        $this->post("/catalog/{$burger->id}/deactivate")->assertSessionHas('warning');
        $this->assertFalse($burger->fresh()->is_active);
        $this->get('/catalog?status=inactive')->assertInertia(fn ($page) => $page->has('products.data', 1));
        $this->post("/catalog/{$burger->id}/activate");
        $this->assertTrue($burger->fresh()->is_active);

        $this->delete("/catalog/{$burger->id}")->assertStatus(405);
        $this->assertSame(2, Product::count());
        $this->assertTrue(AuditLog::where('resource_type', 'product')->where('action', 'created')->exists());
    }

    public function test_the_product_form_validates_price_sku_and_locations(): void
    {
        $this->as();
        $this->product($this->shop, 'Existente', '1.00', ['sku' => 'DUP-1']);
        $foreign = $this->other->locations()->create(['name' => 'Ajena']);
        $base = ['name' => 'Nuevo', 'price' => '10'];

        $this->post('/catalog', ['name' => '', 'price' => '10'])->assertSessionHasErrors('name');
        $this->post('/catalog', ['price' => '-1'] + $base)->assertSessionHasErrors('price');
        $this->post('/catalog', ['price' => '10.999'] + $base)->assertSessionHasErrors('price');
        $this->post('/catalog', ['price' => 'abc'] + $base)->assertSessionHasErrors('price');
        $this->post('/catalog', ['price' => '100000000'] + $base)->assertSessionHasErrors('price');
        $this->post('/catalog', ['sku' => 'DUP-1'] + $base)->assertSessionHasErrors(['sku' => 'Ya existe un producto con ese SKU en este Workspace.']);
        $this->post('/catalog', ['sku' => 'mal sku!'] + $base)->assertSessionHasErrors('sku');
        $this->post('/catalog', ['location_ids' => [$foreign->id]] + $base)->assertSessionHasErrors('location_ids.0');
        $this->assertSame(1, Product::count());

        // The same sku in ANOTHER Workspace is fine; a product without sku never collides with another one without it.
        $this->product($this->other, 'Otra', '5', ['sku' => 'DUP-1']);
        $this->post('/catalog', $base)->assertSessionHasNoErrors();
        $this->post('/catalog', ['name' => 'Otro sin sku', 'price' => '3'])->assertSessionHasNoErrors();
    }

    public function test_catalog_permissions_plan_and_isolation(): void
    {
        $mine = $this->product($this->shop);
        $foreign = $this->product($this->other, 'Ajeno');

        $this->as('lector');
        $this->get('/catalog')->assertOk();
        $this->post('/catalog', ['name' => 'X', 'price' => '1'])->assertForbidden();
        $this->put("/catalog/{$mine->id}", ['name' => 'Y', 'price' => '1'])->assertForbidden();
        $this->post("/catalog/{$mine->id}/deactivate")->assertForbidden();

        $this->as('cliente');
        $this->get('/catalog')->assertForbidden();

        $this->as();
        $this->put("/catalog/{$foreign->id}", ['name' => 'Robado', 'price' => '1'])->assertNotFound();
        $this->post("/catalog/{$foreign->id}/deactivate")->assertNotFound();
        $this->assertSame('Ajeno', $foreign->fresh()->name);
        $this->get('/catalog')->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.name', 'Hamburguesa'));

        // A plan without the module: the URL does not exist for this Workspace.
        $this->contractModules($this->shop, ['dashboard']);
        $this->as();
        $this->get('/catalog')->assertForbidden();
        $this->get('/orders')->assertForbidden();
    }

    // --- orders: money belongs to the server ---------------------------------------------------------------------------------------------

    public function test_the_total_comes_from_the_catalog_and_nothing_the_browser_sends_about_money_counts(): void
    {
        $burger = $this->product($this->shop, 'Hamburguesa', '18500.00', ['sku' => 'HAM-01']);
        $soda = $this->product($this->shop, 'Gaseosa', '4000.50');
        $this->as();

        $this->post('/orders', $this->payload([['product_id' => $burger->id, 'quantity' => 2, 'unit_price' => '1', 'line_total' => '1'], ['product_id' => $soda->id, 'quantity' => 3]], ['total' => '1', 'status' => 'delivered', 'number' => 999, 'currency' => 'USD', 'workspace_id' => $this->other->id]))->assertSessionHasNoErrors();

        $order = Order::firstOrFail();
        $this->assertSame([1, 'COP', '49001.50', 'received', $this->shop->id], [$order->number, $order->currency, $order->total, $order->status, $order->workspace_id]);
        $this->assertSame(['37000.00', '12001.50'], $order->lines->pluck('line_total')->all());
        $this->assertSame(['HAM-01', null], $order->lines->pluck('product_sku')->all());
        $this->assertSame(1, OrderStatusChange::where('order_id', $order->id)->where('to_status', 'received')->whereNull('from_status')->count());
    }

    public function test_an_order_keeps_what_was_bought_when_the_catalog_changes(): void
    {
        $burger = $this->product($this->shop, 'Hamburguesa', '18500.00', ['sku' => 'HAM-01']);
        $this->as();
        $order = $this->place([['product_id' => $burger->id, 'quantity' => 2]]);

        $burger->update(['name' => 'Renombrada', 'price' => '99999.00', 'sku' => 'NEW-9']);
        $this->post("/catalog/{$burger->id}/deactivate");

        $line = $order->fresh()->lines->sole();
        $this->assertSame(['Hamburguesa', 'HAM-01', '18500.00', '37000.00'], [$line->product_name, $line->product_sku, $line->unit_price, $order->fresh()->total]);
        $this->get("/orders/{$order->id}")->assertInertia(fn ($page) => $page->where('order.lines.0.name', 'Hamburguesa')->where('order.lines.0.unitPrice', 18500)->where('order.total', 37000));

        // A deactivated product is no longer offered for NEW orders; the old order is untouched.
        $this->post('/orders', $this->payload([['product_id' => $burger->id, 'quantity' => 1]]))->assertSessionHas('error');
        $this->assertSame(1, Order::count());
        // And the product cannot be deleted while orders use it: the database refuses.
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $burger->id)->delete();
    }

    public function test_numbers_are_sequential_per_workspace_and_duplicated_lines_are_merged(): void
    {
        $mine = $this->product($this->shop);
        $theirs = $this->product($this->other, 'Ajena', '5.00');
        $this->as();
        $first = $this->place([['product_id' => $mine->id, 'quantity' => 1], ['product_id' => $mine->id, 'quantity' => 2]]);
        $second = $this->place([['product_id' => $mine->id, 'quantity' => 1]]);
        $this->as('admin', $this->other);
        $foreignOrder = $this->place([['product_id' => $theirs->id, 'quantity' => 1]]);

        $this->assertSame([1, 2, 1], [$first->number, $second->number, $foreignOrder->number]);
        $this->assertSame([3], $first->lines->pluck('quantity')->all());
    }

    public function test_an_order_cannot_use_products_customers_or_locations_of_another_workspace_or_unavailable_ones(): void
    {
        $mine = $this->product($this->shop);
        $theirs = $this->product($this->other, 'Ajena');
        $northHere = $this->shop->locations()->create(['name' => 'Norte']);
        $southHere = $this->shop->locations()->create(['name' => 'Sur']);
        $foreignLocation = $this->other->locations()->create(['name' => 'Ajena']);
        $foreignCustomer = $this->other->customers()->create(['first_name' => 'Ajeno', 'source' => 'manual', 'marketing_consent' => 'unknown', 'phone' => '+573009998888', 'phone_normalized' => '573009998888', 'phone_e164' => '+573009998888']);
        $this->as();
        $line = [['product_id' => $mine->id, 'quantity' => 1]];

        $this->post('/orders', $this->payload([['product_id' => $theirs->id, 'quantity' => 1]]))->assertSessionHasErrors('lines.0.product_id');
        $this->post('/orders', $this->payload($line, ['location_id' => $foreignLocation->id]))->assertSessionHasErrors('location_id');
        $this->post('/orders', $this->payload($line, ['customer_id' => $foreignCustomer->id]))->assertSessionHasErrors('customer_id');
        $this->assertSame(0, Order::count());

        // A product offered only at one location cannot be ordered for another; an inactive location is refused.
        $mine->locations()->sync([$northHere->id => ['workspace_id' => $this->shop->id]]);
        $this->post('/orders', $this->payload($line, ['location_id' => $southHere->id]))->assertSessionHas('error');
        $this->place($line, ['location_id' => $northHere->id]);
        $southHere->update(['is_active' => false]);
        $other = $this->product($this->shop, 'Libre');
        $this->post('/orders', $this->payload([['product_id' => $other->id, 'quantity' => 1]], ['location_id' => $southHere->id]))->assertSessionHas('error');
        $this->assertSame(1, Order::count());
        $this->assertSame('Norte', Order::firstOrFail()->location_name);

        // A location is optional: no location, no problem.
        $this->place([['product_id' => $other->id, 'quantity' => 1]]);
        $this->assertNull(Order::orderByDesc('id')->first()->location_id);

        // Without Sedes in the plan a location cannot be named.
        $this->contractModules($this->shop, ['dashboard', 'product_catalog', 'orders']);
        $this->as();
        $this->post('/orders', $this->payload([['product_id' => $other->id, 'quantity' => 1]], ['location_id' => $northHere->id]))->assertSessionHas('error');
    }

    public function test_an_order_may_be_tied_to_a_customer_of_the_workspace_and_validation_is_clear(): void
    {
        $mine = $this->product($this->shop);
        $customer = $this->shop->customers()->create(['first_name' => 'Laura', 'last_name' => 'Pérez', 'source' => 'manual', 'marketing_consent' => 'unknown', 'phone' => '+573001112233', 'phone_normalized' => '573001112233', 'phone_e164' => '+573001112233']);
        $this->as();

        $this->post('/orders', ['customer_id' => $customer->id, 'delivery_method' => 'pickup', 'lines' => [['product_id' => $mine->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame([$customer->id, 'Laura Pérez', '+573001112233'], [$order->customer_id, $order->customer_name, $order->customer_phone]);

        $line = [['product_id' => $mine->id, 'quantity' => 1]];
        $this->post('/orders', $this->payload([]))->assertSessionHasErrors('lines');
        $this->post('/orders', $this->payload([['product_id' => $mine->id, 'quantity' => 0]]))->assertSessionHasErrors('lines.0.quantity');
        $this->post('/orders', $this->payload([['product_id' => $mine->id, 'quantity' => 10000]]))->assertSessionHasErrors('lines.0.quantity');
        $this->post('/orders', $this->payload($line, ['delivery_method' => 'drone']))->assertSessionHasErrors('delivery_method');
        $this->post('/orders', $this->payload($line, ['delivery_method' => 'delivery']))->assertSessionHasErrors('delivery_address');
        $this->post('/orders', $this->payload($line, ['customer_phone' => '3001112233']))->assertSessionHasErrors('customer_phone');
        $this->post('/orders', $this->payload($line, ['notes' => str_repeat('a', 501)]))->assertSessionHasErrors('notes');
        $this->post('/orders', ['delivery_method' => 'pickup', 'lines' => $line])->assertSessionHasErrors('customer_name');
        $this->assertSame(1, Order::count());

        $this->place($line, ['delivery_method' => 'delivery', 'delivery_address' => 'Calle 1 # 2-3', 'delivery_city' => 'Bogotá', 'recipient_name' => 'Ana', 'recipient_phone' => '+573004445566']);
        $this->assertSame(['delivery', 'Calle 1 # 2-3', 'Ana'], [Order::orderByDesc('id')->first()->delivery_method, Order::orderByDesc('id')->first()->delivery_address, Order::orderByDesc('id')->first()->recipient_name]);
        // A pickup order never stores an address, even if one is sent.
        $this->place($line, ['delivery_address' => 'Ignorada']);
        $this->assertNull(Order::orderByDesc('id')->first()->delivery_address);
    }

    // --- the flow -----------------------------------------------------------------------------------------------------------------------

    private function move(Order $order, string $status, array $extra = [])
    {
        return $this->post("/orders/{$order->id}/status", ['status' => $status] + $extra);
    }

    public function test_a_pickup_order_follows_its_flow_and_final_states_never_reopen(): void
    {
        $mine = $this->product($this->shop);
        $this->as();
        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]]);

        $this->move($order, 'delivered')->assertSessionHas('error'); // received cannot jump to delivered
        $this->move($order, 'received')->assertSessionHas('error'); // already there
        foreach (['confirmed', 'preparing', 'ready'] as $step) {
            $this->move($order, $step)->assertSessionHas('success');
        }
        $this->move($order, 'dispatched')->assertSessionHas('error'); // a pickup order is never dispatched
        $this->move($order, 'delivered')->assertSessionHas('success');
        $this->assertSame('delivered', $order->fresh()->status);

        foreach (['received', 'confirmed', 'preparing', 'ready', 'dispatched', 'cancelled'] as $back) {
            $this->move($order, $back, ['reason' => 'x'])->assertSessionHas('error');
        }
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(['received', 'confirmed', 'preparing', 'ready', 'delivered'], OrderStatusChange::where('order_id', $order->id)->orderBy('id')->pluck('to_status')->all());
        $this->assertNotNull(OrderStatusChange::where('order_id', $order->id)->whereNotNull('user_id')->first());
    }

    public function test_a_delivery_order_is_dispatched_and_cancelling_needs_a_reason_and_is_final(): void
    {
        $mine = $this->product($this->shop);
        $this->as();
        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]], ['delivery_method' => 'delivery', 'delivery_address' => 'Calle 1']);

        foreach (['confirmed', 'preparing', 'ready'] as $step) {
            $this->move($order, $step);
        }
        $this->move($order, 'delivered')->assertSessionHas('error'); // a delivery is handed over after being dispatched
        $this->move($order, 'dispatched')->assertSessionHas('success');
        $this->move($order, 'cancelled')->assertSessionHasErrors('reason'); // no reason: a field error, so the modal stays open
        $this->move($order, 'cancelled', ['reason' => '   '])->assertSessionHasErrors('reason');
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->move($order, 'cancelled', ['reason' => 'Nadie contestó en la dirección'])->assertSessionHas('success');

        $cancelled = $order->fresh();
        $this->assertSame(['cancelled', 'Nadie contestó en la dirección'], [$cancelled->status, $cancelled->cancel_reason]);
        $this->move($order, 'dispatched')->assertSessionHas('error');
        $this->move($order, 'confirmed')->assertSessionHas('error');
        $this->move($order, 'banana')->assertSessionHasErrors('status');
    }

    public function test_who_may_read_place_move_and_cancel(): void
    {
        $mine = $this->product($this->shop);
        $this->as();
        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]]);

        // An operator advances orders but neither places nor cancels them.
        $this->as('operador');
        $this->get('/orders')->assertOk()->assertInertia(fn ($page) => $page->where('form', null));
        $this->post('/orders', $this->payload([['product_id' => $mine->id, 'quantity' => 1]]))->assertForbidden();
        $this->put("/orders/{$order->id}", ['notes' => 'x'])->assertForbidden();
        $this->move($order, 'confirmed')->assertSessionHas('success');
        $this->move($order, 'cancelled', ['reason' => 'x'])->assertForbidden();
        $this->get("/orders/{$order->id}")->assertInertia(fn ($page) => $page->where('moves.0.status', 'preparing')->has('moves', 1)->where('canEdit', false));

        // A reader sees but cannot move.
        $this->as('lector');
        $this->get("/orders/{$order->id}")->assertOk()->assertInertia(fn ($page) => $page->has('moves', 0));
        $this->move($order, 'preparing')->assertForbidden();

        $this->as('cliente');
        $this->get('/orders')->assertForbidden();
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_two_people_moving_the_same_order_cannot_both_succeed(): void
    {
        $mine = $this->product($this->shop);
        $this->as();
        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]]);
        $service = app(OrderService::class);
        $staleA = Order::find($order->id);
        $staleB = Order::find($order->id);

        $service->transition($staleA, 'confirmed', null);
        $this->expectExceptionMessageMatches('/no puede pasar|ya está/');
        try {
            $service->transition($staleB, 'confirmed', null); // B still believes it is "received": the locked re-read refuses it
        } finally {
            $this->assertSame(2, OrderStatusChange::where('order_id', $order->id)->count());
        }
    }

    public function test_the_delivery_details_change_only_while_the_order_has_not_left(): void
    {
        $mine = $this->product($this->shop);
        $this->as();
        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]], ['delivery_method' => 'delivery', 'delivery_address' => 'Calle 1']);

        $this->put("/orders/{$order->id}", ['notes' => 'Timbre dañado', 'delivery_address' => 'Calle 2 # 5-6', 'recipient_phone' => '+573004445566'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(['Calle 2 # 5-6', 'Timbre dañado'], [$order->fresh()->delivery_address, $order->fresh()->notes]);
        $this->put("/orders/{$order->id}", ['delivery_address' => ''])->assertSessionHas('error');
        $this->put("/orders/{$order->id}", ['recipient_phone' => '3004445566'])->assertSessionHasErrors('recipient_phone');

        foreach (['confirmed', 'preparing', 'ready', 'dispatched'] as $step) {
            $this->move($order, $step);
        }
        $this->put("/orders/{$order->id}", ['delivery_address' => 'Otra'])->assertSessionHas('error');
        $this->assertSame('Calle 2 # 5-6', $order->fresh()->delivery_address);
        // The total and the lines are never editable: there is no way to send them.
        $this->put("/orders/{$order->id}", ['total' => '1'])->assertSessionHas('error');
        $this->assertSame('18500.00', $order->fresh()->total);
    }

    public function test_the_status_counters_of_the_list_are_whole_numbers(): void
    {
        // The model casts `total` to decimal:2: a count aliased `total` would come back as "1.00" and show "1.00" on the screen.
        $product = $this->product($this->shop);
        $this->as();
        $first = $this->place([['product_id' => $product->id, 'quantity' => 1]]);
        $this->place([['product_id' => $product->id, 'quantity' => 1]]);
        $this->move($first, 'cancelled', ['reason' => 'Prueba'])->assertSessionHas('success');

        $this->get('/orders')->assertInertia(fn ($page) => $page->where('counts', ['received' => 1, 'cancelled' => 1]));
    }

    // --- isolation ------------------------------------------------------------------------------------------------------------------------

    public function test_an_order_of_another_workspace_is_never_visible_or_changeable(): void
    {
        $theirs = $this->product($this->other, 'Ajena');
        $this->as('admin', $this->other);
        $foreign = $this->place([['product_id' => $theirs->id, 'quantity' => 1]]);

        $this->as();
        $this->get("/orders/{$foreign->id}")->assertNotFound();
        $this->put("/orders/{$foreign->id}", ['notes' => 'x'])->assertNotFound();
        $this->move($foreign, 'confirmed')->assertNotFound();
        $this->get('/orders')->assertInertia(fn ($page) => $page->has('orders.data', 0)->where('counts', []));
        $this->assertSame('received', $foreign->fresh()->status);
    }

    public function test_the_database_refuses_links_between_workspaces_and_duplicate_numbers(): void
    {
        $mine = $this->product($this->shop);
        $theirs = $this->product($this->other, 'Ajena');
        $this->as();
        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]]);

        $refuses = function (callable $write) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted a forbidden row.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };

        $refuses(fn () => DB::table('order_lines')->insert(['workspace_id' => $this->shop->id, 'order_id' => $order->id, 'product_id' => $theirs->id, 'product_name' => 'X', 'unit_price' => 1, 'quantity' => 1, 'line_total' => 1]));
        $refuses(fn () => DB::table('order_lines')->insert(['workspace_id' => $this->other->id, 'order_id' => $order->id, 'product_id' => null, 'product_name' => 'X', 'unit_price' => 1, 'quantity' => 1, 'line_total' => 1]));
        $refuses(fn () => DB::table('orders')->where('id', $order->id)->update(['location_id' => $this->other->locations()->create(['name' => 'Ajena'])->id]));
        $refuses(fn () => DB::table('orders')->insert(['workspace_id' => $this->shop->id, 'number' => $order->number, 'customer_name' => 'Otro', 'currency' => 'COP', 'total' => 1, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]));
        $refuses(fn () => DB::table('orders')->where('id', $order->id)->delete());
        $this->assertSame(1, OrderLine::count());
    }

    // --- notifications and schema -------------------------------------------------------------------------------------------------------

    public function test_order_events_are_announced_to_the_notifier_but_nothing_is_sent_today(): void
    {
        Http::fake();
        $this->assertFalse(app(OrderNotifier::class)->available());
        $spy = new class implements OrderNotifier
        {
            public array $events = [];

            public function available(): bool
            {
                return true;
            }

            public function notify(Order $order, string $event): void
            {
                $this->events[] = [$order->number, $event, $order->status];
                throw new \RuntimeException('a failing channel must not undo the order');
            }
        };
        $this->app->instance(OrderNotifier::class, $spy);
        $mine = $this->product($this->shop);
        $this->as();

        $order = $this->place([['product_id' => $mine->id, 'quantity' => 1]]);
        $this->move($order, 'confirmed')->assertSessionHas('success');

        $this->assertSame([[1, 'received', 'received'], [1, 'status_changed', 'confirmed']], $spy->events);
        $this->assertSame('confirmed', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_before_the_migration_the_pages_open_and_say_what_is_missing(): void
    {
        $migration = require database_path('migrations/2026_10_20_100000_create_catalog_and_orders_tables.php');
        $migration->down();
        DB::table('migrations')->where('migration', '2026_10_20_100000_create_catalog_and_orders_tables')->delete();
        $this->as();

        $this->get('/catalog')->assertOk()->assertInertia(fn ($page) => $page->where('schemaPending', true)->has('products.data', 0));
        $this->get('/orders')->assertOk()->assertInertia(fn ($page) => $page->where('schemaPending', true)->has('orders.data', 0));
        $this->get('/orders/1')->assertNotFound();
        $this->post('/catalog', ['name' => 'X', 'price' => '1'])->assertSessionHas('error');
        $this->post('/orders', ['customer_name' => 'X'])->assertSessionHas('error');
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_migration_goes_down_and_up_without_touching_what_exists(): void
    {
        $this->actAsMember($this->shop);
        $service = $this->makeService($this->shop);
        $migration = require database_path('migrations/2026_10_20_100000_create_catalog_and_orders_tables.php');

        $migration->down();
        $migration->up();

        $this->assertSame($service->name, $this->shop->services()->firstOrFail()->name);
        $this->assertSame(0, Product::count() + Order::count());
        $this->assertNotNull(Customer::query()->getModel());
        $this->assertNotNull(Location::query()->getModel());
    }

    public function test_the_navigation_offers_catalog_and_orders_only_with_the_plan_and_the_permission(): void
    {
        $this->as();
        $this->get('/catalog')->assertInertia(fn ($page) => $page->where('modules', fn ($modules) => collect($modules)->contains('orders') && collect($modules)->contains('product_catalog')));

        // Orders need the catalog: a plan with Pedidos but without Catálogo offers neither (the declared dependency).
        $this->contractModules($this->shop, ['dashboard', 'orders']);
        $this->as();
        $this->get('/orders')->assertForbidden();
        $this->get('/catalog')->assertForbidden();

        // The plan has both but the role has no permission: the page is closed for that role.
        $this->contractModules($this->shop, self::MODULES);
        $this->as('cliente');
        $this->get('/orders')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('modules'));
    }
}
