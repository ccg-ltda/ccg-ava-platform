<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Orders\InventoryService;
use App\Services\LocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * A product is offered only in the locations it is assigned to, and that comes from the persisted product-location relation, not from a filter of the screen:
 * the catalog and «Existencias por sede» list only those locations, the inventory routes refuse to write the stock of another location, taking a location off
 * a product keeps its stock rows and movements (history is never deleted), and a member limited to some locations keeps seeing only their own.
 */
class CatalogLocationAssociationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    /** @var array<string, Location> */
    private array $places = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog', 'orders', 'locations']);

        foreach (['Centro', 'Norte', 'Occidente', 'Sur'] as $name) {
            $this->places[$name] = $this->workspace->locations()->create(['name' => $name, 'is_active' => true]);
        }

        $this->admin = $this->actAsMember($this->workspace);
    }

    private function create(array $locations, array $extra = []): Product
    {
        $this->post('/catalog', $extra + ['name' => 'Crema', 'price' => '25000', 'location_ids' => collect($locations)->map(fn (string $name) => $this->places[$name]->id)->all()])->assertSessionHasNoErrors();

        return $this->workspace->products()->where('name', $extra['name'] ?? 'Crema')->firstOrFail();
    }

    private function edit(Product $product, array $locations): void
    {
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => $product->name, 'price' => '25000', 'location_ids' => collect($locations)->map(fn (string $name) => $this->places[$name]->id)->all()])->assertSessionHasNoErrors();
    }

    /** @return list<string> */
    private function offeredIn(Product $product): array
    {
        return $product->fresh()->locations()->orderBy('name')->pluck('locations.name')->all();
    }

    /** @return list<string> the locations the page lists under «Existencias por sede» for this product */
    private function stockPlaces(Product $product): array
    {
        $page = $this->get("/catalog/{$product->id}")->assertOk()->viewData('page')['props'];
        $offered = $page['product']['locationIds'];

        return collect($page['locations'])->filter(fn (array $location) => in_array($location['id'], $offered, true))->pluck('name')->sort()->values()->all();
    }

    public function test_a_product_created_for_one_location_is_offered_only_there(): void
    {
        $product = $this->create(['Centro']);

        $this->assertSame(['Centro'], $this->offeredIn($product));
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->where('products.data.0.locationIds', [$this->places['Centro']->id]));
        $this->assertSame(['Centro'], $this->stockPlaces($product));
    }

    public function test_a_product_created_for_several_locations_is_offered_in_all_of_them_and_only_them(): void
    {
        $product = $this->create(['Centro', 'Occidente']);

        $this->assertSame(['Centro', 'Occidente'], $this->offeredIn($product));
        $this->assertSame(['Centro', 'Occidente'], $this->stockPlaces($product));
    }

    public function test_editing_a_product_can_add_a_location_from_the_authorized_ones_and_nothing_else_appears(): void
    {
        $product = $this->create(['Centro']);
        $this->edit($product, ['Centro', 'Norte']);

        $this->assertSame(['Centro', 'Norte'], $this->offeredIn($product));
        $this->assertSame(['Centro', 'Norte'], $this->stockPlaces($product));
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->where('products.data.0.locationIds', [$this->places['Centro']->id, $this->places['Norte']->id]));
    }

    public function test_the_stock_view_lists_only_the_stock_of_the_locations_the_product_is_offered_in(): void
    {
        $product = $this->create(['Centro', 'Norte'], ['tracks_inventory' => true, 'stocks' => [['location_id' => $this->places['Centro']->id, 'quantity' => 8], ['location_id' => $this->places['Norte']->id, 'quantity' => 3]]]);
        $this->edit($product, ['Centro']);

        // The Norte stock row and its movements are still stored (history is never deleted)...
        $this->assertSame(3, InventoryStock::where('product_id', $product->id)->where('location_id', $this->places['Norte']->id)->value('quantity'));
        $this->assertSame(2, InventoryMovement::where('product_id', $product->id)->count());

        // ...but the product no longer shows, sums or offers it.
        $stock = $this->get('/catalog')->viewData('page')['props']['products']['data'][0]['stock'];
        $this->assertSame(['Centro'], collect($stock['stocks'])->pluck('location')->all());
        $this->assertSame(8, $stock['total']['quantity']);

        // Bringing the location back brings its stock back as it was.
        $this->edit($product, ['Centro', 'Norte']);
        $stock = $this->get('/catalog')->viewData('page')['props']['products']['data'][0]['stock'];
        $this->assertSame(11, $stock['total']['quantity']);
    }

    public function test_the_catalog_filtered_by_a_location_lists_only_the_products_offered_there(): void
    {
        $centro = $this->create(['Centro'], ['name' => 'Solo Centro']);
        $this->create(['Norte'], ['name' => 'Solo Norte']);
        $historical = $this->workspace->products()->create(['name' => 'Histórico', 'price' => 10, 'is_active' => true]); // assigned to no location: offered everywhere until assigned

        $names = fn (string $place) => collect($this->get('/catalog?place='.$this->places[$place]->id)->viewData('page')['props']['products']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Histórico', 'Solo Centro'], $names('Centro'));
        $this->assertSame(['Histórico', 'Solo Norte'], $names('Norte'));
        $this->assertSame([], array_intersect(['Solo Centro'], $names('Occidente')));
        $this->assertNotNull($centro);
        $this->assertNotNull($historical);
    }

    public function test_the_server_refuses_to_write_the_stock_of_a_location_the_product_is_not_offered_in(): void
    {
        $product = $this->create(['Centro'], ['tracks_inventory' => true, 'stocks' => [['location_id' => $this->places['Centro']->id, 'quantity' => 5]]]);
        $norte = $this->places['Norte']->id;

        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 4, 'location_id' => $norte])->assertStatus(422);
        $this->post("/catalog/{$product->id}/stock/adjust", ['quantity' => 4, 'reason' => 'Conteo', 'location_id' => $norte])->assertStatus(422);
        $this->put("/catalog/{$product->id}/stock/minimum", ['min_quantity' => 2, 'location_id' => $norte])->assertStatus(422);
        $this->assertNull(InventoryStock::where('product_id', $product->id)->where('location_id', $norte)->first(), 'nothing was created in the location');

        // In its own location the same operations work.
        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 4, 'location_id' => $this->places['Centro']->id])->assertSessionHasNoErrors();
        $this->assertSame(9, InventoryStock::where('product_id', $product->id)->where('location_id', $this->places['Centro']->id)->value('quantity'));
    }

    public function test_the_first_stock_of_a_new_product_can_only_be_set_where_it_is_offered(): void
    {
        $this->post('/catalog', ['name' => 'Crema', 'price' => '25000', 'tracks_inventory' => true, 'location_ids' => [$this->places['Centro']->id], 'stocks' => [['location_id' => $this->places['Sur']->id, 'quantity' => 2]]])->assertSessionHasErrors('stocks');
        $this->assertSame(0, $this->workspace->products()->count());
    }

    public function test_a_location_of_another_workspace_can_not_be_assigned(): void
    {
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog', 'locations']);
        $foreign = $other->locations()->create(['name' => 'Ajena', 'is_active' => true]);

        $this->post('/catalog', ['name' => 'Crema', 'price' => '25000', 'location_ids' => [$foreign->id]])->assertSessionHasErrors('location_ids.0');
        $this->assertSame(0, $this->workspace->products()->count());
    }

    public function test_a_member_limited_to_some_locations_only_offers_and_sees_their_own(): void
    {
        $product = $this->create(['Centro', 'Norte']);
        $limited = $this->member($this->workspace, 'admin');
        app(LocationScope::class)->save($this->workspace, $limited, 'selected', [$this->places['Centro']->id]);
        $this->actingAs($limited)->withSession(['workspace_id' => $this->workspace->id]);

        // The product is also offered in Norte, which they do not have: they can not change it, and they can not assign a location outside their scope.
        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Otra', 'price' => '1', 'location_ids' => [$this->places['Centro']->id]])->assertForbidden();
        $this->post('/catalog', ['name' => 'Nueva', 'price' => '1', 'location_ids' => [$this->places['Norte']->id]])->assertSessionHasErrors('location_ids');
        $this->post('/catalog', ['name' => 'Nueva', 'price' => '1', 'location_ids' => [$this->places['Centro']->id]])->assertSessionHasNoErrors();
        $this->assertSame(['Centro'], $this->workspace->products()->where('name', 'Nueva')->firstOrFail()->locations()->pluck('locations.name')->all());

        $names = collect($this->get('/catalog')->viewData('page')['props']['products']['data'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Crema', 'Nueva'], $names);
    }

    public function test_the_inventory_service_still_keeps_one_row_per_location(): void
    {
        $product = $this->create(['Centro', 'Norte'], ['tracks_inventory' => true]);
        app(InventoryService::class)->receive($product, $this->places['Centro']->id, 5, $this->admin);
        app(InventoryService::class)->receive($product, $this->places['Norte']->id, 2, $this->admin);

        $this->assertSame(2, InventoryStock::where('product_id', $product->id)->count());
    }
}
