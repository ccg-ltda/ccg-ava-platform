<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Workspace;
use App\Orders\InventoryService;
use App\Orders\OrderException;
use App\Orders\OrderService;
use App\Orders\ProductCategories;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Inventory of the Catálogo per location: valid categories, the product picture, a different stock in each location, the alert thresholds, the movement history
 * and the integration with Pedidos (the sale is discounted once, when the order is confirmed, and a cancellation returns it once).
 */
class InventoryTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Location $centro;

    private Location $norte;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog', 'orders', 'locations']);
        $this->centro = $this->workspace->locations()->create(['name' => 'Centro', 'is_active' => true]);
        $this->norte = $this->workspace->locations()->create(['name' => 'Norte', 'is_active' => true]);
        $this->admin = $this->actAsMember($this->workspace);
    }

    private function product(array $attributes = [], ?Workspace $workspace = null): Product
    {
        static $n = 0;

        return ($workspace ?? $this->workspace)->products()->create($attributes + ['name' => 'Crema '.++$n, 'sku' => 'CRE-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'price' => '25000.00', 'is_active' => true]);
    }

    private function tracked(int $centro = 10, int $norte = 0, array $attributes = []): Product
    {
        $product = $this->product(['tracks_inventory' => true] + $attributes);

        if ($centro > 0) {
            app(InventoryService::class)->receive($product, $this->centro->id, $centro, $this->admin);
        }

        if ($norte > 0) {
            app(InventoryService::class)->receive($product, $this->norte->id, $norte, $this->admin);
        }

        return $product->refresh();
    }

    private function quantity(Product $product, Location $location): ?int
    {
        return InventoryStock::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function order(Product $product, int $quantity, ?Location $location = null, array $extra = []): Order
    {
        return app(OrderService::class)->create($this->workspace, $extra + ['customer_name' => 'Laura', 'delivery_method' => 'pickup', 'location_id' => ($location ?? $this->centro)->id, 'lines' => [['product_id' => $product->id, 'quantity' => $quantity]]], $this->admin);
    }

    private function move(Order $order, string $to): Order
    {
        return app(OrderService::class)->transition($order, $to, $this->admin, $to === 'cancelled' ? 'El cliente cambió de opinión' : null);
    }

    // --- 1. categories ---------------------------------------------------------------------------------------------------------------------

    public function test_a_category_is_chosen_from_the_workspaces_valid_list_and_an_invalid_one_is_refused(): void
    {
        $category = ProductCategory::create(['workspace_id' => $this->workspace->id, 'name' => 'Cuidado facial', 'normalized_name' => 'cuidado facial']);
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $other = ProductCategory::create(['workspace_id' => $foreign->id, 'name' => 'Ajena', 'normalized_name' => 'ajena']);
        $inactive = ProductCategory::create(['workspace_id' => $this->workspace->id, 'name' => 'Vieja', 'normalized_name' => 'vieja', 'is_active' => false]);

        $this->post('/catalog', ['name' => 'Crema', 'price' => '10000', 'category_id' => $category->id])->assertSessionHasNoErrors();
        $created = $this->workspace->products()->where('name', 'Crema')->firstOrFail();
        $this->assertSame([$category->id, 'Cuidado facial'], [$created->category_id, $created->category], 'the product keeps the category and a copy of its name');

        foreach ([$other->id, $inactive->id, 999999, 'texto-libre'] as $invalid) {
            $this->post('/catalog', ['name' => 'Mala '.$invalid, 'price' => '1', 'category_id' => $invalid])->assertSessionHasErrors('category_id');
        }
        $this->assertDatabaseMissing('products', ['workspace_id' => $this->workspace->id, 'name' => 'Mala '.$other->id]);
    }

    public function test_the_same_category_written_in_another_capitalization_or_spacing_is_never_a_duplicate(): void
    {
        $this->post('/catalog/categories', ['name' => 'Cuidado facial'])->assertSessionHasNoErrors();
        $this->post('/catalog/categories', ['name' => '  cuidado   FACIAL '])->assertSessionHasNoErrors()->assertSessionHas('warning');
        $this->post('/catalog/categories', ['name' => '   '])->assertSessionHasErrors('name');

        $this->assertSame(1, ProductCategory::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame('Cuidado facial', ProductCategory::first()->name, 'the first spelling is the one kept');
        $this->assertSame('cuidado facial', ProductCategories::normalize("  Cuidado \t FACIAL "));

        // Another Workspace may have its own category with that name.
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        [$mine] = app(ProductCategories::class)->ensure($foreign, 'Cuidado facial');
        $this->assertSame($foreign->id, $mine->workspace_id);
        $this->assertSame(2, ProductCategory::count());
    }

    public function test_the_database_refuses_two_categories_with_the_same_normalized_name_in_a_workspace(): void
    {
        ProductCategory::create(['workspace_id' => $this->workspace->id, 'name' => 'Comida', 'normalized_name' => 'comida']);

        $this->expectException(UniqueConstraintViolationException::class);
        ProductCategory::create(['workspace_id' => $this->workspace->id, 'name' => 'COMIDA', 'normalized_name' => 'comida']);
    }

    public function test_existing_products_keep_their_category_when_the_migration_normalizes_them(): void
    {
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $a = $this->product(['category' => 'Comida']);
        $b = $this->product(['category' => ' comida ']);
        $c = $this->product(['category' => 'COMIDA']);
        $d = $this->product(['category' => 'Bebidas']);
        $none = $this->product();
        $theirs = $this->product(['category' => 'comida'], $foreign);

        $migration = require database_path('migrations/2026_10_23_100000_create_inventory_tables.php');
        (new ReflectionMethod($migration, 'backfillCategories'))->invoke($migration);

        $this->assertSame(2, ProductCategory::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame($a->fresh()->category_id, $b->fresh()->category_id);
        $this->assertSame($a->fresh()->category_id, $c->fresh()->category_id);
        $this->assertSame(['Comida', 'Comida', 'Comida', 'Bebidas', null], [$a->fresh()->category, $b->fresh()->category, $c->fresh()->category, $d->fresh()->category, $none->fresh()->category]);
        $this->assertNull($none->fresh()->category_id);
        $this->assertNotSame($a->fresh()->category_id, $theirs->fresh()->category_id, 'a category never crosses Workspaces');
        $this->assertSame($foreign->id, ProductCategory::find($theirs->fresh()->category_id)->workspace_id);
    }

    // --- 2. images -------------------------------------------------------------------------------------------------------------------------

    public function test_a_picture_is_stored_privately_served_to_members_and_replaced_safely(): void
    {
        $this->post('/catalog', ['name' => 'Con foto', 'price' => '1000', 'image' => UploadedFile::fake()->image('a.png', 200, 200)])->assertSessionHasNoErrors();
        $product = $this->workspace->products()->where('name', 'Con foto')->firstOrFail();
        $first = $product->image_path;

        $this->assertStringStartsWith("workspaces/{$this->workspace->id}/products/{$product->id}/", $first);
        Storage::assertExists($first);
        $this->get("/catalog/{$product->id}/image")->assertOk()->assertHeader('Cache-Control', 'no-cache, private');

        // A different picture replaces it: the old file goes only because no other product uses it.
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Con foto', 'price' => '1000', 'image' => UploadedFile::fake()->image('b.jpg', 100, 100)])->assertSessionHasNoErrors();
        $second = $product->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::assertMissing($first);
        Storage::assertExists($second);

        // A file that another product points to is never deleted.
        $twin = $this->product(['image_path' => $second]);
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Con foto', 'price' => '1000', 'remove_image' => true])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->image_path);
        Storage::assertExists($second);
        $this->assertSame($second, $twin->fresh()->image_path);
    }

    public function test_only_pictures_of_the_allowed_type_and_size_are_accepted(): void
    {
        $this->post('/catalog', ['name' => 'PDF', 'price' => '1', 'image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');
        $this->post('/catalog', ['name' => 'SVG', 'price' => '1', 'image' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertSessionHasErrors('image');
        $this->post('/catalog', ['name' => 'Grande', 'price' => '1', 'image' => UploadedFile::fake()->image('x.png')->size(3000)])->assertSessionHasErrors('image');

        $this->assertSame(0, $this->workspace->products()->count());
    }

    public function test_a_picture_is_not_served_across_workspaces_or_without_permission(): void
    {
        $this->post('/catalog', ['name' => 'Foto', 'price' => '1', 'image' => UploadedFile::fake()->image('a.png')])->assertSessionHasNoErrors();
        $product = $this->workspace->products()->firstOrFail();
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);

        $this->actAsMember($foreign);
        $this->get("/catalog/{$product->id}/image")->assertNotFound();

        $this->actAsMember($this->workspace, 'supervisor'); // no view-catalog
        $this->get("/catalog/{$product->id}/image")->assertForbidden();
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'X', 'price' => '1', 'image' => UploadedFile::fake()->image('c.png')])->assertForbidden();
    }

    // --- 3 & 4. one product, a different stock in each location; isolation --------------------------------------------------------------------

    public function test_the_same_product_has_a_different_stock_in_each_location_and_none_where_it_was_never_stocked(): void
    {
        $product = $this->tracked(10, 3);
        $third = $this->workspace->locations()->create(['name' => 'Sur', 'is_active' => true]);

        $this->assertSame([10, 3, null], [$this->quantity($product, $this->centro), $this->quantity($product, $this->norte), $this->quantity($product, $third)]);
        $this->assertSame(1, $this->workspace->products()->count(), 'the product is not duplicated per location');

        $this->get('/catalog?place='.$this->norte->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inventory.placeLabel', 'Norte')->where('products.data.0.stock.selected.quantity', 3)->where('products.data.0.stock.total.scope', 'one'));
        $this->get('/catalog?place='.$third->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.stock.selected', null)->where('products.data.0.stock.unavailableHere', true)->where('products.data.0.stock.level', 'out'));
        // «Every location» labels the total as the sum of the stocks that exist, never as available everywhere.
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inventory.placeLabel', 'Todas las sedes')->where('products.data.0.stock.total.quantity', 13)->where('products.data.0.stock.total.scope', 'all')->where('products.data.0.stock.total.places', 2));
    }

    public function test_a_product_of_another_workspace_cannot_be_stocked_read_or_linked(): void
    {
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog', 'locations']);
        $theirLocation = $foreign->locations()->create(['name' => 'Ajena', 'is_active' => true]);
        $theirs = $this->product(['tracks_inventory' => true], $foreign);

        $this->post("/catalog/{$theirs->id}/stock/receive", ['quantity' => 5, 'location_id' => $this->centro->id])->assertNotFound();
        $this->get("/catalog/{$theirs->id}")->assertNotFound();

        $mine = $this->tracked(1);
        $this->post("/catalog/{$mine->id}/stock/receive", ['quantity' => 5, 'location_id' => $theirLocation->id])->assertSessionHasErrors('location_id');
        $this->assertSame(0, InventoryStock::where('product_id', $theirs->id)->count());
        $this->assertSame(1, InventoryStock::where('product_id', $mine->id)->count());

        // The database also refuses a stock whose product and location belong to different Workspaces.
        $this->expectException(QueryException::class);
        DB::table('inventory_stocks')->insert(['workspace_id' => $this->workspace->id, 'product_id' => $mine->id, 'location_id' => $theirLocation->id, 'quantity' => 1, 'min_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    // --- 5 & 6. alerts and negatives -------------------------------------------------------------------------------------------------------

    public function test_the_stock_level_alerts_at_or_below_the_minimum_and_an_inactive_product_is_not_an_out_of_stock_one(): void
    {
        $product = $this->tracked(10);
        $this->put("/catalog/{$product->id}/stock/minimum", ['min_quantity' => 5, 'location_id' => $this->centro->id])->assertSessionHasNoErrors();
        $level = fn () => InventoryStock::where('product_id', $product->id)->first()->level();

        $this->assertSame('ok', $level());
        app(InventoryService::class)->adjust($product, $this->centro->id, 5, $this->admin, 'Conteo');
        $this->assertSame('low', $level(), 'equal to the minimum already alerts');
        app(InventoryService::class)->adjust($product, $this->centro->id, 2, $this->admin, 'Conteo');
        $this->assertSame('low', $level());
        app(InventoryService::class)->adjust($product, $this->centro->id, 0, $this->admin, 'Conteo');
        $this->assertSame('out', $level());

        // Deactivating changes nothing about the stock: they are different conditions.
        $this->post("/catalog/{$product->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame(0, $this->quantity($product, $this->centro));
        $this->assertSame(4, InventoryMovement::where('product_id', $product->id)->count(), 'deactivating wrote no movement');
    }

    public function test_quantities_cannot_be_negative_and_an_entry_needs_at_least_one_unit(): void
    {
        $product = $this->tracked(5);
        $post = fn (string $path, array $data) => $this->post("/catalog/{$product->id}/stock/{$path}", $data + ['location_id' => $this->centro->id]);

        $post('adjust', ['quantity' => -1, 'reason' => 'x'])->assertSessionHasErrors('quantity');
        $post('receive', ['quantity' => 0])->assertSessionHasErrors('quantity');
        $post('receive', ['quantity' => -3])->assertSessionHasErrors('quantity');
        $this->put("/catalog/{$product->id}/stock/minimum", ['min_quantity' => -1, 'location_id' => $this->centro->id])->assertSessionHasErrors('min_quantity');
        $post('adjust', ['quantity' => 5, 'reason' => 'x'])->assertSessionHasErrors('quantity'); // nothing to adjust

        $this->assertSame(5, $this->quantity($product, $this->centro));
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());

        $this->post('/catalog', ['name' => 'Neg', 'price' => '1', 'tracks_inventory' => true, 'stocks' => [['location_id' => $this->centro->id, 'quantity' => -4]]])->assertSessionHasErrors('stocks.0.quantity');
    }

    // --- 7. movements -----------------------------------------------------------------------------------------------------------------------

    public function test_entries_and_adjustments_leave_a_movement_with_the_quantity_before_and_after_and_who_did_it(): void
    {
        $product = $this->tracked(0);
        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 12, 'location_id' => $this->centro->id, 'reason' => 'Compra a proveedor'])->assertSessionHasNoErrors();
        $this->post("/catalog/{$product->id}/stock/adjust", ['quantity' => 9, 'location_id' => $this->centro->id, 'reason' => 'Producto dañado'])->assertSessionHasNoErrors();
        $this->post("/catalog/{$product->id}/stock/adjust", ['quantity' => 9, 'location_id' => $this->centro->id])->assertSessionHasErrors('reason');

        $rows = InventoryMovement::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertSame(['entry', 'adjustment'], $rows->pluck('type')->all());
        $this->assertSame([[0, 12, 12], [12, 9, -3]], $rows->map(fn ($m) => [$m->quantity_before, $m->quantity_after, $m->quantity_change])->all());
        $this->assertSame([$this->admin->id, $this->admin->id], $rows->pluck('user_id')->all());
        $this->assertSame(['Compra a proveedor', 'Producto dañado'], $rows->pluck('reason')->all());
        $this->assertSame($this->workspace->id, $rows->first()->workspace_id);

        $this->get("/catalog/{$product->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('movements.meta.total', 2)->where('movements.data.0.typeLabel', 'Ajuste')->where('movements.data.0.before', 12)->where('movements.data.0.after', 9)->where('movements.data.0.user', $this->admin->name));
    }

    public function test_editing_the_product_never_changes_its_stock_and_a_new_product_records_its_first_stock_as_an_entry(): void
    {
        $this->post('/catalog', ['name' => 'Nuevo', 'price' => '5000', 'tracks_inventory' => true, 'stocks' => [['location_id' => $this->centro->id, 'quantity' => 8, 'min_quantity' => 2], ['location_id' => $this->norte->id, 'quantity' => 0]]])->assertSessionHasNoErrors();
        $product = $this->workspace->products()->where('name', 'Nuevo')->firstOrFail();

        $this->assertSame([8, 0], [$this->quantity($product, $this->centro), $this->quantity($product, $this->norte)]);
        $this->assertSame(2, InventoryStock::where('product_id', $product->id)->where('location_id', $this->centro->id)->value('min_quantity'));
        $first = InventoryMovement::where('product_id', $product->id)->get();
        $this->assertCount(1, $first, 'only the stock with units has an entry');
        $this->assertSame(['entry', 'Existencia inicial', 0, 8], [$first[0]->type, $first[0]->reason, $first[0]->quantity_before, $first[0]->quantity_after]);

        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Nuevo 2', 'price' => '6000'])->assertSessionHasNoErrors();
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Nuevo 2', 'price' => '6000', 'stocks' => [['location_id' => $this->centro->id, 'quantity' => 999]]])->assertStatus(422);

        $this->assertSame(8, $this->quantity($product, $this->centro));
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());
    }

    // --- 8, 9 & 11. the sale: when it is discounted, once, and what a cancellation returns ----------------------------------------------

    public function test_the_stock_is_discounted_when_the_order_is_confirmed_and_not_before(): void
    {
        $product = $this->tracked(10);
        $order = $this->order($product, 3);

        $this->assertSame(10, $this->quantity($product, $this->centro), 'placing the order discounts nothing');
        $this->assertSame(0, InventoryMovement::where('order_id', $order->id)->count());

        $this->move($order, 'confirmed');
        $this->assertSame(7, $this->quantity($product, $this->centro));
        $sale = InventoryMovement::where('order_id', $order->id)->sole();
        $this->assertSame(['sale', -3, 10, 7, $this->centro->id, $this->admin->id, "Pedido #{$order->number}"], [$sale->type, $sale->quantity_change, $sale->quantity_before, $sale->quantity_after, $sale->location_id, $sale->user_id, $sale->reason]);

        foreach (['preparing', 'ready', 'delivered'] as $step) {
            $this->move($order->refresh(), $step);
        }
        $this->assertSame(7, $this->quantity($product, $this->centro), 'the later states never discount again');
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->count());
        $this->assertSame(10, $this->quantity($product, $this->centro) + 3 - 0, 'the other location was never touched');
    }

    public function test_a_sale_is_never_discounted_twice(): void
    {
        $product = $this->tracked(10);
        $order = $this->order($product, 4);
        $this->move($order, 'confirmed');

        // The same request repeated: the order is already confirmed, so it is refused and nothing moves.
        $this->post("/orders/{$order->id}/status", ['status' => 'confirmed'])->assertSessionHas('error');
        $this->assertSame(6, $this->quantity($product, $this->centro));

        // Even a second pass of the discount itself (a retried process) finds the sale already written.
        DB::transaction(fn () => app(InventoryService::class)->consume($order->refresh(), $this->admin));
        $this->assertSame(6, $this->quantity($product, $this->centro));
        $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', 'sale')->count());

        // And the database refuses a second sale row for the same order and product.
        $this->expectException(UniqueConstraintViolationException::class);
        InventoryMovement::create(['workspace_id' => $this->workspace->id, 'product_id' => $product->id, 'location_id' => $this->centro->id, 'type' => 'sale', 'quantity_change' => -4, 'quantity_before' => 6, 'quantity_after' => 2, 'order_id' => $order->id]);
    }

    public function test_a_sale_that_exceeds_the_stock_is_refused_and_the_order_stays_as_it_was(): void
    {
        $product = $this->tracked(5);
        $first = $this->order($product, 3);
        $second = $this->order($product, 3); // each one fits on its own when it is placed

        $this->move($first, 'confirmed');
        $this->assertSame(2, $this->quantity($product, $this->centro));

        try {
            $this->move($second, 'confirmed');
            $this->fail('the second sale must be refused');
        } catch (OrderException $exception) {
            $this->assertStringContainsString('hay 2 y el pedido pide 3', $exception->getMessage());
        }

        $this->assertSame('received', $second->fresh()->status, 'a refused confirmation leaves the order untouched');
        $this->assertSame(2, $this->quantity($product, $this->centro), 'the stock never goes negative');
        $this->assertSame(0, InventoryMovement::where('order_id', $second->id)->count());
        $this->assertSame(0, DB::table('order_status_changes')->where('order_id', $second->id)->where('to_status', 'confirmed')->count());

        // In the screen the person sees the reason.
        $this->post("/orders/{$second->id}/status", ['status' => 'confirmed'])->assertSessionHas('error');
    }

    public function test_an_order_cannot_be_placed_for_more_than_there_is_or_where_the_product_has_no_stock(): void
    {
        $product = $this->tracked(2);

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('No hay existencias suficientes');
        try {
            $this->order($product, 3);
        } finally {
            $this->assertSame(0, Order::count());
        }
    }

    public function test_a_tracked_product_with_no_stock_in_the_orders_location_is_not_available_there(): void
    {
        $product = $this->tracked(10, 0);

        try {
            $this->order($product, 1, $this->norte);
            $this->fail('no stock in Norte');
        } catch (OrderException $exception) {
            $this->assertStringContainsString('no tiene inventario en la sede «Norte»', $exception->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame('received', $this->order($product, 1, $this->centro)->status);
    }

    public function test_cancelling_after_the_sale_returns_exactly_what_was_discounted_once_and_cancelling_before_returns_nothing(): void
    {
        $product = $this->tracked(10);
        $early = $this->order($product, 2);
        $this->move($early, 'cancelled'); // never confirmed: nothing was discounted, nothing to return
        $this->assertSame(10, $this->quantity($product, $this->centro));
        $this->assertSame(0, InventoryMovement::where('order_id', $early->id)->count());

        $order = $this->order($product, 4);
        $this->move($order, 'confirmed');
        $this->move($order->refresh(), 'preparing');
        $this->assertSame(6, $this->quantity($product, $this->centro));

        $this->move($order->refresh(), 'cancelled');
        $this->assertSame(10, $this->quantity($product, $this->centro));
        $movements = InventoryMovement::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['sale', 'cancellation'], $movements->pluck('type')->all());
        $this->assertSame([-4, 4, 6, 10], [$movements[0]->quantity_change, $movements[1]->quantity_change, $movements[1]->quantity_before, $movements[1]->quantity_after]);

        // A cancelled order is final: it cannot be cancelled (or returned) again, and a reversal row cannot be duplicated.
        try {
            $this->move($order->refresh(), 'cancelled');
            $this->fail('final state');
        } catch (OrderException) {
            $this->assertSame(10, $this->quantity($product, $this->centro));
        }
        DB::transaction(fn () => app(InventoryService::class)->restore($order->refresh(), $this->admin));
        $this->assertSame(10, $this->quantity($product, $this->centro));
        $this->assertSame(2, InventoryMovement::where('order_id', $order->id)->count());
    }

    public function test_a_delivered_order_is_final_so_nothing_is_returned_from_it(): void
    {
        $product = $this->tracked(10);
        $order = $this->order($product, 2);
        foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $step) {
            $this->move($order->refresh(), $step);
        }

        $this->expectException(OrderException::class);
        try {
            $this->move($order->refresh(), 'cancelled');
        } finally {
            $this->assertSame(8, $this->quantity($product, $this->centro), 'there is no returns flow in the order states: a delivered sale stays discounted');
        }
    }

    // --- 12 & 15. history is preserved; products without stock control ------------------------------------------------------------------

    public function test_orders_placed_before_the_control_keep_their_prices_totals_and_lines_and_nothing_is_invented_for_them(): void
    {
        $product = $this->product(['price' => '19900.50']);
        $old = $this->order($product, 2, null, ['location_id' => null]);
        $delivered = $this->order($product, 1, null, ['location_id' => null]);
        foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $step) {
            $this->move($delivered->refresh(), $step);
        }
        $snapshot = fn (Order $o) => [$o->total, $o->status, $o->lines()->get(['product_name', 'product_sku', 'unit_price', 'quantity', 'line_total'])->toArray()];
        $before = [$snapshot($old->refresh()), $snapshot($delivered->refresh())];

        // The product is configured afterwards (control on, new price): the past stays as it was and no movement exists for it.
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => $product->name, 'price' => '30000', 'tracks_inventory' => true])->assertSessionHasNoErrors();
        $this->assertSame($before, [$snapshot($old->refresh()), $snapshot($delivered->refresh())]);
        $this->assertSame(0, InventoryMovement::count(), 'no history is invented for orders that already existed');
        $this->assertSame(0, InventoryStock::count(), 'no quantity is invented for the product');
    }

    public function test_a_product_without_stock_control_sells_exactly_as_before_and_is_not_out_of_stock(): void
    {
        $product = $this->product();
        $order = $this->order($product, 50);

        foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $step) {
            $this->move($order->refresh(), $step);
        }

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(0, InventoryMovement::count());
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.tracksInventory', false)->where('products.data.0.stock.tracked', false)->where('products.data.0.stock.level', null)->where('inventory.summary.tracked', 0));
    }

    public function test_turning_the_control_on_without_any_stock_leaves_it_pending_and_blocks_the_sale_until_there_is_stock(): void
    {
        $product = $this->product(['tracks_inventory' => true]);

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('no tiene inventario');
        $this->order($product, 1);
    }

    // --- 13. permissions --------------------------------------------------------------------------------------------------------------------

    public function test_stock_figures_and_movements_need_view_inventory_and_changes_need_adjust_inventory(): void
    {
        $product = $this->tracked(5);
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-dashboard', 'view-catalog', 'create-products', 'edit-products']);
        Role::findOrCreate('inventarista', 'web')->syncPermissions(['view-dashboard', 'view-catalog', 'view-inventory']);

        $this->actAsMember($this->workspace, 'lector');
        $this->get('/catalog')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('inventory.canView', false)->where('inventory.summary', null)->where('products.data.0.stock', null));
        $this->get("/catalog/{$product->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('movements', null)->where('product.stock', null));
        $this->get('/catalog?availability=out&place='.$this->centro->id)->assertInertia(fn (AssertableInertia $page) => $page->where('filters.availability', '')->where('filters.place', ''));
        // The product form cannot turn stock control on or off, nor send stock, without the permission.
        $this->post('/catalog', ['name' => 'X', 'price' => '1', 'tracks_inventory' => true])->assertForbidden();
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => $product->name, 'price' => '25000', 'tracks_inventory' => false])->assertForbidden();
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => $product->name, 'price' => '25000', 'is_active' => false])->assertForbidden();

        $this->actAsMember($this->workspace, 'inventarista');
        $this->get("/catalog/{$product->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('movements.meta.total', 1)->where('inventory.canAdjust', false));
        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 1, 'location_id' => $this->centro->id])->assertForbidden();
        $this->post("/catalog/{$product->id}/stock/adjust", ['quantity' => 1, 'location_id' => $this->centro->id, 'reason' => 'x'])->assertForbidden();
        $this->put("/catalog/{$product->id}/stock/minimum", ['min_quantity' => 1, 'location_id' => $this->centro->id])->assertForbidden();
        $this->assertSame(5, $this->quantity($product, $this->centro));
    }

    public function test_the_module_of_the_catalog_gates_every_inventory_route(): void
    {
        $product = $this->tracked(5);
        $this->contractModules($this->workspace, ['dashboard', 'orders']);

        $this->get("/catalog/{$product->id}")->assertForbidden();
        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 1, 'location_id' => $this->centro->id])->assertForbidden();
        $this->assertSame(5, $this->quantity($product, $this->centro));
    }

    // --- 14. filters ------------------------------------------------------------------------------------------------------------------------

    public function test_the_list_filters_by_place_category_state_availability_and_search(): void
    {
        [$cat] = app(ProductCategories::class)->ensure($this->workspace, 'Cuidado facial');
        [$bebidas] = app(ProductCategories::class)->ensure($this->workspace, 'Bebidas');
        $ok = $this->tracked(20, 0, ['name' => 'Alfa', 'sku' => 'AL-1', 'category_id' => $cat->id, 'category' => $cat->name]);
        $low = $this->tracked(3, 9, ['name' => 'Beta', 'sku' => 'BE-1', 'category_id' => $bebidas->id, 'category' => $bebidas->name]);
        InventoryStock::where('product_id', $low->id)->where('location_id', $this->centro->id)->update(['min_quantity' => 5]);
        $out = $this->tracked(0, 4, ['name' => 'Gamma', 'sku' => 'GA-1', 'category_id' => $cat->id, 'category' => $cat->name]);
        $inactive = $this->tracked(7, 0, ['name' => 'Delta', 'sku' => 'DE-1', 'is_active' => false]);
        $plain = $this->product(['name' => 'Epsilon', 'sku' => 'EP-1']);
        $names = fn (string $query) => collect($this->get("/catalog?{$query}")->assertOk()->viewData('page')['props']['products']['data'])->pluck('name')->sort()->values()->all();
        $centro = 'place='.$this->centro->id;

        $this->assertSame(['Alfa', 'Beta', 'Delta', 'Epsilon', 'Gamma'], $names(''));
        $this->assertSame(['Beta'], $names("{$centro}&availability=low"), 'at or below the minimum of the consulted location');
        $this->assertSame(['Gamma'], $names("{$centro}&availability=out"), 'nothing on hand in the consulted location');
        $this->assertSame(['Alfa', 'Delta'], $names("{$centro}&availability=ok"));
        $this->assertSame(['Epsilon'], $names('availability=untracked'));
        $this->assertSame(['Beta'], $names('availability=low'), 'with every location: some location is low');
        $this->assertSame([], $names('availability=out'), 'with every location: Gamma has units in Norte, so it is not out');
        $this->assertSame(['Alfa', 'Gamma'], $names("category={$cat->id}"));
        $this->assertSame(['Delta'], $names('status=inactive'));
        $this->assertSame(['Gamma'], $names('search=ga-1'));
        $this->assertSame(['Beta'], $names('search=BET'));
        $this->assertSame(['Gamma'], $names("place={$this->norte->id}&availability=ok&category={$cat->id}"));
        $this->assertNotContains($plain->name, $names("{$centro}&availability=out"), 'a product without stock control is not «out of stock»');
        $this->assertNotContains($inactive->name, $names("{$centro}&availability=out"));

        $this->get("/catalog?{$centro}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inventory.summary.tracked', 3)->where('inventory.summary.low', 1)->where('inventory.summary.out', 1)->where('inventory.summary.units', 23)); // active tracked: 20 + 3 + 0
    }

    public function test_without_locations_the_general_stock_is_used_and_orders_without_a_location_draw_from_it(): void
    {
        $shop = $this->bookingWorkspace('SIN_SEDES', ['dashboard', 'product_catalog', 'orders']);
        $this->actAsMember($shop);
        $product = $this->product(['tracks_inventory' => true], $shop);

        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 6])->assertSessionHasNoErrors();
        $stock = InventoryStock::where('product_id', $product->id)->sole();
        $this->assertNull($stock->location_id);

        $order = app(OrderService::class)->create($shop, ['customer_name' => 'Laura', 'delivery_method' => 'pickup', 'lines' => [['product_id' => $product->id, 'quantity' => 4]]], null);
        app(OrderService::class)->transition($order, 'confirmed', null);
        $this->assertSame(2, $stock->fresh()->quantity);

        // Two general stocks of the same product cannot exist (partial unique index).
        $this->expectException(UniqueConstraintViolationException::class);
        InventoryStock::create(['workspace_id' => $shop->id, 'product_id' => $product->id, 'location_id' => null, 'quantity' => 1, 'min_quantity' => 0]);
    }

    public function test_deactivating_reports_how_many_open_orders_still_include_the_product_without_touching_them(): void
    {
        $product = $this->tracked(10);
        $open = $this->order($product, 1);
        $done = $this->order($product, 1);
        foreach (['confirmed', 'preparing', 'ready', 'delivered'] as $step) {
            $this->move($done->refresh(), $step);
        }

        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->where('products.data.0.openOrders', 1));
        $this->post("/catalog/{$product->id}/deactivate")->assertSessionHas('success');

        $this->assertSame('received', $open->fresh()->status);
        $this->assertSame('delivered', $done->fresh()->status);
        $this->assertSame($open->total, $open->fresh()->total);
    }

    // --- 10. reports -------------------------------------------------------------------------------------------------------------------------

    public function test_the_reports_count_stock_per_location_low_and_out_products_and_the_movements_of_the_period(): void
    {
        [$cat] = app(ProductCategories::class)->ensure($this->workspace, 'Cuidado facial');
        $ok = $this->tracked(20, 6, ['category_id' => $cat->id, 'category' => $cat->name]);
        $low = $this->tracked(3, 0);
        InventoryStock::where('product_id', $low->id)->update(['min_quantity' => 5]);
        $out = $this->tracked(3, 2);
        app(InventoryService::class)->adjust($out, $this->centro->id, 0, $this->admin, 'Conteo');
        $inactive = $this->tracked(9, 0, ['is_active' => false]);
        $order = $this->order($ok, 4);
        $this->move($order, 'confirmed');
        $this->contractModules($this->workspace, ['dashboard', 'reports', 'product_catalog', 'orders', 'locations']);

        $props = $this->get('/reports?period=30d')->assertOk()->viewData('page')['props'];
        $inventory = $props['operations']['inventory']['data'];

        $this->assertTrue($props['sources']['inventory']);
        $this->assertSame(['tracked' => 3, 'units' => 16 + 6 + 3 + 2, 'low' => 1, 'out' => 1, 'entries' => 20 + 6 + 3 + 3 + 2 + 9, 'sold' => 4], $inventory['kpis'], 'only active products with stock control count for the alerts and the units');
        $this->assertSame(['Centro' => 19, 'Norte' => 8], collect($inventory['byLocation'])->pluck('value', 'label')->all(), 'each location is counted on its own');
        $this->assertSame(['Cuidado facial' => 22, 'Sin categoría' => 5], collect($inventory['byCategory'])->pluck('value', 'label')->all(), 'by the normalized category');
        $this->assertContains($low->name, array_column($inventory['attention'], 'name'));
        $this->assertContains($out->name, array_column($inventory['attention'], 'name'));
        $this->assertNotContains($inactive->name, array_column($inventory['attention'], 'name'));
        $this->assertSame(4, collect($inventory['movements'])->firstWhere('key', 'sale')['value']);
    }

    public function test_the_inventory_report_needs_the_catalog_module_and_view_inventory_and_reads_no_inventory_table_without_them(): void
    {
        $this->tracked(5);
        $this->contractModules($this->workspace, ['dashboard', 'reports', 'orders']); // no product_catalog

        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = $this->get('/reports')->assertOk()->viewData('page')['props'];
        $tables = collect(DB::getQueryLog())->flatMap(fn (array $query) => preg_match_all('/(?:from|join)\s+"([a-z_]+)"/i', $query['query'], $matches) ? $matches[1] : [])->unique()->all();
        DB::disableQueryLog();

        $this->assertFalse($props['sources']['inventory']);
        $this->assertNull($props['operations']['inventory']);
        $this->assertSame([], array_values(array_intersect($tables, ['inventory_stocks', 'inventory_movements', 'product_categories'])), 'no inventory table is requested without the module');

        $this->contractModules($this->workspace, ['dashboard', 'reports', 'product_catalog']);
        Role::findOrCreate('analista', 'web')->syncPermissions(['view-dashboard', 'view-catalog']);
        $this->actAsMember($this->workspace, 'analista');
        $this->assertFalse($this->get('/reports')->viewData('page')['props']['sources']['inventory'], 'the module is not enough: the role needs view-inventory');
    }
}
