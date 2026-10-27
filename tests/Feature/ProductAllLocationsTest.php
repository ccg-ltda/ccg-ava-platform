<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Orders\InventoryService;
use App\Orders\OrderException;
use App\Orders\OrderService;
use App\Services\LocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * «Todas las sedes» of a product is COVERAGE (where it may be offered and sold), never stock: choosing it creates no quantity anywhere, a sede that never received the product
 * has nothing to sell, and the sale still checks the real stock of the sede of the order. The mark covers sedes created later, can be turned into an explicit list by
 * excluding one, and is refused for a member limited to selected sedes.
 */
class ProductAllLocationsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    /** @var array<string, Location> */
    private array $sedes = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog', 'orders', 'locations']);

        foreach (['Centro', 'Norte', 'Sur'] as $name) {
            $this->sedes[$name] = $this->workspace->locations()->create(['name' => $name, 'is_active' => true]);
        }

        $this->admin = $this->actAsMember($this->workspace);
    }

    private function create(array $data = []): Product
    {
        $this->post('/catalog', $data + ['name' => 'Crema', 'price' => '25000'])->assertSessionHasNoErrors();

        return $this->workspace->products()->where('name', $data['name'] ?? 'Crema')->firstOrFail();
    }

    private function update(Product $product, array $data): void
    {
        $this->post("/catalog/{$product->id}", $data + ['_method' => 'put', 'name' => $product->name, 'price' => '25000'])->assertSessionHasNoErrors();
    }

    private function order(Product $product, int $quantity, Location $where)
    {
        return app(OrderService::class)->create($this->workspace, ['customer_name' => 'Laura', 'delivery_method' => 'pickup', 'location_id' => $where->id, 'lines' => [['product_id' => $product->id, 'quantity' => $quantity]]], $this->admin);
    }

    public function test_covering_all_sedes_stores_a_mark_and_no_list_and_creates_no_stock_anywhere(): void
    {
        $product = $this->create(['offers_all_locations' => true, 'tracks_inventory' => true]);

        $this->assertTrue($product->offers_all_locations);
        $this->assertSame(0, $product->locations()->count(), 'no ids: the mark itself is the coverage');
        $this->assertSame(0, InventoryStock::count(), 'covering a sede is not having stock in it');
        $this->assertSame(0, InventoryMovement::count());
        $this->get('/catalog')->assertInertia(fn ($page) => $page->where('products.data.0.offersAllLocations', true)->where('products.data.0.needsLocation', false)->where('locationsLimited', false));
    }

    public function test_a_list_sent_together_with_the_mark_is_ignored_and_without_either_the_old_rule_still_applies(): void
    {
        $product = $this->create(['offers_all_locations' => true, 'location_ids' => [$this->sedes['Centro']->id]]);
        $this->assertSame(0, $product->locations()->count());

        $this->post('/catalog', ['name' => 'Sin nada', 'price' => '10'])->assertSessionHasErrors('location_ids');
        $this->post('/catalog', ['name' => 'Ajena', 'price' => '10', 'location_ids' => [999999]])->assertSessionHasErrors();
    }

    public function test_excluding_a_sede_turns_the_coverage_into_an_explicit_list_and_back(): void
    {
        $product = $this->create(['offers_all_locations' => true]);

        $this->update($product, ['location_ids' => [$this->sedes['Centro']->id, $this->sedes['Norte']->id]]); // "all" minus Sur
        $product->refresh();
        $this->assertFalse($product->offers_all_locations);
        $this->assertEqualsCanonicalizing([$this->sedes['Centro']->id, $this->sedes['Norte']->id], $product->locations()->pluck('locations.id')->all());

        $this->update($product, ['offers_all_locations' => true]);
        $this->assertTrue($product->fresh()->offers_all_locations);
        $this->assertSame(0, $product->locations()->count());
    }

    public function test_a_sede_created_later_is_covered_by_the_mark_but_not_by_an_explicit_list(): void
    {
        $all = $this->create(['name' => 'Cubierto', 'offers_all_locations' => true]);
        $some = $this->create(['name' => 'Algunas', 'location_ids' => [$this->sedes['Centro']->id]]);

        $late = $this->workspace->locations()->create(['name' => 'Nueva', 'is_active' => true]);

        $names = collect($this->get('/catalog?place='.$late->id)->viewData('page')['props']['products']['data'])->pluck('name')->all();
        $this->assertSame(['Cubierto'], $names);
        $this->assertNotNull($some);
        $this->assertNotNull($all);
    }

    public function test_an_inactive_sede_can_not_be_chosen_for_a_new_assignment(): void
    {
        $this->sedes['Sur']->update(['is_active' => false]);

        $this->post('/catalog', ['name' => 'Cerrada', 'price' => '10', 'location_ids' => [$this->sedes['Sur']->id]])->assertSessionHasErrors('location_ids');
        $this->assertSame(0, $this->workspace->products()->count());
    }

    public function test_coverage_does_not_sell_what_is_not_in_stock_the_sale_checks_the_real_stock_of_the_sede(): void
    {
        $product = $this->create(['offers_all_locations' => true, 'tracks_inventory' => true]);
        app(InventoryService::class)->receive($product, $this->sedes['Centro']->id, 5, $this->admin);

        // Norte is covered but never received the product: nothing to sell there.
        try {
            $this->order($product, 1, $this->sedes['Norte']);
            $this->fail('A covered sede without stock must not sell.');
        } catch (OrderException $exception) {
            $this->assertStringContainsString('no tiene inventario', $exception->getMessage());
        }

        // Centro has 5: 6 is refused, 2 is accepted.
        try {
            $this->order($product, 6, $this->sedes['Centro']);
            $this->fail('More than the stock must be refused.');
        } catch (OrderException $exception) {
            $this->assertStringContainsString('No hay existencias suficientes', $exception->getMessage());
        }

        $this->order($product, 2, $this->sedes['Centro']);
        $this->assertSame(1, InventoryStock::count(), 'no stock row appeared in Norte or Sur');
    }

    public function test_stock_can_be_received_in_any_active_covered_sede_and_history_survives_a_narrower_coverage(): void
    {
        $product = $this->create(['offers_all_locations' => true, 'tracks_inventory' => true]);

        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 7, 'location_id' => $this->sedes['Sur']->id])->assertSessionHasNoErrors();
        $this->assertSame(7, InventoryStock::where('location_id', $this->sedes['Sur']->id)->value('quantity'));

        // Narrowing the coverage to Centro keeps the Sur row and its movement (history is never deleted), though Sur no longer shows it.
        $this->update($product, ['location_ids' => [$this->sedes['Centro']->id]]);
        $this->assertSame(7, InventoryStock::where('location_id', $this->sedes['Sur']->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::count());
        $this->post("/catalog/{$product->id}/stock/receive", ['quantity' => 1, 'location_id' => $this->sedes['Sur']->id])->assertStatus(422);
    }

    public function test_a_member_limited_to_selected_sedes_can_not_cover_all_of_them(): void
    {
        $limited = $this->member($this->workspace, 'admin');
        $this->actingAs($limited)->withSession(['workspace_id' => $this->workspace->id]);
        LocationScope::class;
        app(LocationScope::class)->save($this->workspace, $limited, LocationScope::SELECTED, [$this->sedes['Centro']->id]);

        $this->post('/catalog', ['name' => 'Mío', 'price' => '10', 'offers_all_locations' => true])->assertSessionHasErrors('location_ids');
        $this->post('/catalog', ['name' => 'Mío', 'price' => '10', 'location_ids' => [$this->sedes['Centro']->id]])->assertSessionHasNoErrors();
        $this->get('/catalog')->assertInertia(fn ($page) => $page->where('locationsLimited', true));
    }

    public function test_the_coverage_of_a_workspace_never_reaches_another(): void
    {
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog', 'orders', 'locations']);
        $theirs = $other->products()->create(['name' => 'Ajeno', 'price' => 1, 'is_active' => true, 'offers_all_locations' => true]);

        $this->post("/catalog/{$theirs->id}", ['_method' => 'put', 'name' => 'Ajeno', 'price' => '1', 'location_ids' => [$this->sedes['Centro']->id]])->assertNotFound();
        $this->assertTrue($theirs->fresh()->offers_all_locations);
        $this->assertSame(0, Location::where('workspace_id', $other->id)->count());
    }
}
