<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Workspace;
use App\Orders\InventoryService;
use App\Orders\ProductCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Administration of the catalog: the «Categorías» tab (create, rename, search, deactivate, isolation, permissions), the category selector of the product form,
 * the stock control switch and the per-sede history filter. Inventory itself is covered by InventoryTest.
 */
class CatalogAdministrationTest extends TestCase
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

    private function category(string $name, ?Workspace $workspace = null, bool $active = true): ProductCategory
    {
        return ProductCategory::create(['workspace_id' => ($workspace ?? $this->workspace)->id, 'name' => $name, 'normalized_name' => ProductCategories::normalize($name), 'is_active' => $active]);
    }

    private function product(array $attributes = []): Product
    {
        static $n = 0;

        return $this->workspace->products()->create($attributes + ['name' => 'Producto '.++$n, 'sku' => 'P-'.++$n, 'price' => '8000.00', 'is_active' => true]);
    }

    // --- categories ------------------------------------------------------------------------------------------------------------------------

    public function test_a_category_is_created_trimmed_and_listed_in_the_administration_tab(): void
    {
        $this->post('/catalog/categories', ['name' => '  Cuidado   facial '])->assertSessionHasNoErrors()->assertSessionHas('success');

        $category = ProductCategory::where('workspace_id', $this->workspace->id)->firstOrFail();
        $this->assertSame('Cuidado facial', $category->name);
        $this->assertSame('cuidado facial', $category->normalized_name);

        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->has('categoryAdmin.data', 1)->where('categoryAdmin.data.0.name', 'Cuidado facial')->where('categoryAdmin.data.0.products', 0)->where('categoryAdmin.canCreate', true)->where('categoryAdmin.canEdit', true));
    }

    public function test_an_empty_or_repeated_name_is_refused_with_a_clear_message(): void
    {
        $this->category('Cuidado facial');

        $this->post('/catalog/categories', ['name' => ''])->assertSessionHasErrors(['name' => 'Escribe el nombre de la categoría.']);
        $this->post('/catalog/categories', ['name' => '   '])->assertSessionHasErrors('name');
        $this->post('/catalog/categories', ['name' => 'CUIDADO  FACIAL'])->assertSessionHasErrors('name');
        $this->post('/catalog/categories', ['name' => str_repeat('a', 81)])->assertSessionHasErrors('name');
        $this->assertSame(1, ProductCategory::count());
    }

    public function test_the_same_name_may_exist_in_another_workspace(): void
    {
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $this->category('Cuidado facial', $foreign);

        $this->post('/catalog/categories', ['name' => 'Cuidado facial'])->assertSessionHasNoErrors();
        $this->assertSame(2, ProductCategory::count());
    }

    public function test_a_rename_keeps_the_products_linked_and_refuses_a_duplicate(): void
    {
        $facial = $this->category('Facial');
        $this->category('Corporal');
        $product = $this->product(['category_id' => $facial->id, 'category' => 'Facial']);

        $this->put("/catalog/categories/{$facial->id}", ['name' => '  Rostro '])->assertSessionHasNoErrors();
        $this->assertSame('Rostro', $facial->fresh()->name);
        $this->assertSame('rostro', $facial->fresh()->normalized_name);
        $this->assertSame($facial->id, $product->fresh()->category_id, 'the product keeps its category');
        $this->assertSame('Rostro', $product->fresh()->category, 'and the copy of the name follows');

        $this->put("/catalog/categories/{$facial->id}", ['name' => 'CORPORAL'])->assertSessionHasErrors(['name' => 'Ya existe una categoría con ese nombre.']);
        $this->put("/catalog/categories/{$facial->id}", ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame('Rostro', $facial->fresh()->name);

        // Renaming with only a different capitalization is the same category, not a duplicate of itself.
        $this->put("/catalog/categories/{$facial->id}", ['name' => 'ROSTRO'])->assertSessionHasNoErrors();
        $this->assertSame('ROSTRO', $facial->fresh()->name);
    }

    public function test_a_category_of_another_workspace_or_a_missing_one_is_not_found(): void
    {
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $other = $this->category('Ajena', $foreign);

        $this->put("/catalog/categories/{$other->id}", ['name' => 'Mía'])->assertNotFound();
        $this->post("/catalog/categories/{$other->id}/deactivate")->assertNotFound();
        $this->post("/catalog/categories/{$other->id}/activate")->assertNotFound();
        $this->put('/catalog/categories/999999', ['name' => 'Nada'])->assertNotFound();
        $this->assertSame('Ajena', $other->fresh()->name);
        $this->assertTrue($other->fresh()->is_active);

        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->has('categoryAdmin.data', 0));
    }

    public function test_deactivating_hides_the_category_from_new_products_but_keeps_it_on_the_ones_that_have_it(): void
    {
        $facial = $this->category('Facial');
        $product = $this->product(['category_id' => $facial->id, 'category' => 'Facial']);

        $this->post("/catalog/categories/{$facial->id}/deactivate")->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertFalse($facial->fresh()->is_active);
        $this->assertSame($facial->id, $product->fresh()->category_id, 'nothing is deleted or reassigned');

        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->has('categories', 0)->where('categoryAdmin.data.0.isActive', false)->where('categoryAdmin.data.0.products', 1));
        $this->post('/catalog', ['name' => 'Nuevo', 'price' => '1000', 'category_id' => $facial->id])->assertSessionHasErrors('category_id');

        $this->post("/catalog/categories/{$facial->id}/activate")->assertSessionHas('success');
        $this->assertTrue($facial->fresh()->is_active);
    }

    public function test_the_administration_list_searches_by_name_ignoring_case_and_spaces(): void
    {
        $this->category('Cuidado facial');
        $this->category('Maquillaje');

        $this->get('/catalog?category_search='.urlencode('  FACIAL '))->assertInertia(fn (AssertableInertia $page) => $page->has('categoryAdmin.data', 1)->where('categoryAdmin.data.0.name', 'Cuidado facial')->where('categoryAdmin.search', 'FACIAL'));
        $this->get('/catalog?category_search=zzz')->assertInertia(fn (AssertableInertia $page) => $page->has('categoryAdmin.data', 0));
    }

    public function test_the_product_form_only_offers_active_categories_of_the_workspace_and_validates_by_id(): void
    {
        $mine = $this->category('Mía');
        $this->category('Vieja', null, false);
        $foreign = $this->category('Ajena', $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']));

        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->has('categories', 1)->where('categories.0.name', 'Mía'));

        $this->post('/catalog', ['name' => 'Libre', 'price' => '1000', 'category' => 'Texto libre'])->assertSessionHasErrors('category_id');
        $this->assertDatabaseMissing('products', ['workspace_id' => $this->workspace->id, 'name' => 'Libre']);
        $this->assertSame(1, ProductCategory::where('workspace_id', $this->workspace->id)->where('is_active', true)->count());

        $this->post('/catalog', ['name' => 'Ajeno', 'price' => '1000', 'category_id' => $foreign->id])->assertSessionHasErrors('category_id');
        $this->post('/catalog', ['name' => 'Bueno', 'price' => '1000', 'category_id' => $mine->id])->assertSessionHasNoErrors();
    }

    public function test_the_old_inline_creation_route_is_gone_and_categories_follow_the_catalog_permissions(): void
    {
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-dashboard', 'view-catalog']);
        Role::findOrCreate('editor', 'web')->syncPermissions(['view-dashboard', 'view-catalog', 'edit-products']);
        $category = $this->category('Facial');

        $this->actAsMember($this->workspace, 'lector');
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->where('categoryAdmin.canCreate', false)->where('categoryAdmin.canEdit', false));
        $this->post('/catalog/categories', ['name' => 'Nueva'])->assertForbidden();
        $this->put("/catalog/categories/{$category->id}", ['name' => 'Otra'])->assertForbidden();
        $this->post("/catalog/categories/{$category->id}/deactivate")->assertForbidden();

        $this->actAsMember($this->workspace, 'editor');
        $this->post('/catalog/categories', ['name' => 'Nueva'])->assertSessionHasNoErrors();
        $this->put("/catalog/categories/{$category->id}", ['name' => 'Otra'])->assertSessionHasNoErrors();
        $this->assertSame('Otra', $category->fresh()->name);
    }

    public function test_the_category_routes_need_the_catalog_module(): void
    {
        $category = $this->category('Facial');
        $this->contractModules($this->workspace, ['dashboard', 'orders']);

        $this->post('/catalog/categories', ['name' => 'Nueva'])->assertForbidden();
        $this->put("/catalog/categories/{$category->id}", ['name' => 'Otra'])->assertForbidden();
        $this->assertSame('Facial', $category->fresh()->name);
    }

    // --- stock control switch ------------------------------------------------------------------------------------------------------------------

    public function test_turning_the_control_on_never_invents_stock_and_off_keeps_quantities_and_history(): void
    {
        $product = $this->product();

        $this->put("/catalog/{$product->id}/stock/control", ['tracks_inventory' => true])->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertTrue($product->fresh()->tracks_inventory);
        $this->assertSame(0, InventoryStock::where('product_id', $product->id)->count());
        $this->assertSame(0, InventoryMovement::where('product_id', $product->id)->count());

        app(InventoryService::class)->receive($product->fresh(), $this->centro->id, 7, $this->admin);

        $this->put("/catalog/{$product->id}/stock/control", ['tracks_inventory' => false])->assertSessionHas('success');
        $this->assertFalse($product->fresh()->tracks_inventory);
        $this->assertSame(7, InventoryStock::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());
    }

    public function test_the_control_switch_is_validated_scoped_to_the_workspace_and_needs_adjust_inventory(): void
    {
        $product = $this->product();
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $theirs = $foreign->products()->create(['name' => 'Ajeno', 'price' => '1.00', 'is_active' => true]);

        $this->put("/catalog/{$product->id}/stock/control", ['tracks_inventory' => 'quizá'])->assertSessionHasErrors('tracks_inventory');
        $this->put("/catalog/{$theirs->id}/stock/control", ['tracks_inventory' => true])->assertNotFound();
        $this->assertFalse($theirs->fresh()->tracks_inventory);

        Role::findOrCreate('editor', 'web')->syncPermissions(['view-dashboard', 'view-catalog', 'edit-products']);
        $this->actAsMember($this->workspace, 'editor');
        $this->put("/catalog/{$product->id}/stock/control", ['tracks_inventory' => true])->assertForbidden();
        $this->assertFalse($product->fresh()->tracks_inventory);
    }

    // --- stock per sede in the catalog -------------------------------------------------------------------------------------------------------------

    public function test_the_list_tells_configured_zero_and_unconfigured_apart_per_sede_without_summing(): void
    {
        $product = $this->product(['name' => 'AAA controlado', 'tracks_inventory' => true]);
        $service = app(InventoryService::class);
        $service->receive($product, $this->centro->id, 5, $this->admin);
        $service->receive($product, $this->norte->id, 3, $this->admin);
        $service->adjust($product, $this->norte->id, 0, $this->admin, 'Conteo');
        $untracked = $this->product(['name' => 'ZZZ libre']);

        $this->get('/catalog?place='.$this->centro->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.stock.selected.quantity', 5)->where('products.data.0.stock.total.scope', 'one'));

        // A sede with control on and no row is «not available here», not zero.
        $other = $this->workspace->locations()->create(['name' => 'Sur', 'is_active' => true]);
        $this->get('/catalog?place='.$other->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.stock.unavailableHere', true)->where('products.data.0.stock.selected', null));

        // Configured zero is a quantity of 0 (agotado), distinct from the missing row.
        $this->get('/catalog?place='.$this->norte->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.data.0.stock.selected.quantity', 0)->where('products.data.0.stock.level', 'out')->where('products.data.0.stock.unavailableHere', false));

        // Every sede: one row per sede with its own quantity, and the untracked product has no stock at all.
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data.0.stock.stocks', 2)->where('products.data.0.stock.tracked', true)->where('products.data.1.stock.tracked', false)->where('products.data.1.id', $untracked->id));
    }

    public function test_the_history_of_a_product_filters_by_sede(): void
    {
        $product = $this->product(['tracks_inventory' => true]);
        $service = app(InventoryService::class);
        $service->receive($product, $this->centro->id, 5, $this->admin);
        $service->receive($product, $this->norte->id, 3, $this->admin);
        $service->receive($product, $this->norte->id, 2, $this->admin);

        $this->get("/catalog/{$product->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('movements.meta.total', 3)->where('movementPlace', ''));
        $this->get("/catalog/{$product->id}?movement_place={$this->norte->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('movements.meta.total', 2)->where('movementPlace', (string) $this->norte->id));

        // A sede that is not this Workspace's (or any junk) is the whole history, never another sede's data.
        $foreign = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $theirs = $foreign->locations()->create(['name' => 'Ajena', 'is_active' => true]);
        $this->get("/catalog/{$product->id}?movement_place={$theirs->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('movements.meta.total', 3)->where('movementPlace', ''));
        $this->get("/catalog/{$product->id}?movement_place=abc")->assertInertia(fn (AssertableInertia $page) => $page->where('movementPlace', ''));
    }

    // --- price ----------------------------------------------------------------------------------------------------------------------------------

    public function test_the_price_reaches_the_server_as_a_plain_number_and_is_stored_as_is(): void
    {
        $this->post('/catalog', ['name' => 'Caro', 'price' => '1500000', 'cost' => '800000.5'])->assertSessionHasNoErrors();
        $product = $this->workspace->products()->where('name', 'Caro')->firstOrFail();

        $this->assertEquals(1500000, $product->price);
        $this->assertEquals(800000.5, $product->cost);

        // Separators are never part of the stored value: the form sends the plain number and the server refuses anything else.
        $this->post('/catalog', ['name' => 'Con puntos', 'price' => '1.500.000'])->assertSessionHasErrors('price');
        $this->post('/catalog', ['name' => 'Negativo', 'price' => '-5'])->assertSessionHasErrors('price');
        $this->post('/catalog', ['name' => 'Tres decimales', 'price' => '10.123'])->assertSessionHasErrors('price');
    }

    public function test_editing_a_price_never_touches_the_stock(): void
    {
        $product = $this->product(['tracks_inventory' => true]);
        app(InventoryService::class)->receive($product, $this->centro->id, 4, $this->admin);

        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => $product->name, 'price' => '9000', 'tracks_inventory' => true])->assertSessionHasNoErrors();

        $this->assertEquals(9000, $product->fresh()->price);
        $this->assertSame(4, InventoryStock::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());
    }

    // --- Brownie: control on, no stock row anywhere ---------------------------------------------------------------------------------------------

    public function test_a_tracked_product_without_any_stock_row_is_unconfigured_never_out_of_stock(): void
    {
        $brownie = $this->product(['name' => 'Brownie', 'tracks_inventory' => true]);
        $agotado = $this->product(['name' => 'Agotado', 'tracks_inventory' => true]);
        $this->product(['name' => 'Libre']);
        $service = app(InventoryService::class);
        $service->receive($agotado, $this->centro->id, 2, $this->admin);
        $service->adjust($agotado, $this->centro->id, 0, $this->admin, 'Conteo');

        $names = fn (string $query) => collect($this->get("/catalog?{$query}")->assertOk()->viewData('page')['props']['products']['data'])->pluck('name')->sort()->values()->all();

        // Summary: Brownie is tracked and unconfigured, NOT out; the configured zero is the only «agotado»; a sede without a row is not a configured zero.
        $this->get('/catalog')->assertInertia(fn (AssertableInertia $page) => $page->where('inventory.summary.tracked', 2)->where('inventory.summary.out', 1)->where('inventory.summary.unconfigured', 1)->where('inventory.summary.units', 0));
        $this->get('/catalog?place='.$this->centro->id)->assertInertia(fn (AssertableInertia $page) => $page->where('inventory.summary.out', 1)->where('inventory.summary.unconfigured', 1));
        $this->get('/catalog?place='.$this->norte->id)->assertInertia(fn (AssertableInertia $page) => $page->where('inventory.summary.out', 0)->where('inventory.summary.unconfigured', 2));

        $this->assertSame(['Agotado'], $names('availability=out'));
        $this->assertSame(['Brownie'], $names('availability=unconfigured'));
        $this->assertSame(['Libre'], $names('availability=untracked'));
        $this->assertSame(['Agotado', 'Brownie'], $names('place='.$this->norte->id.'&availability=unconfigured'));

        // The row of the list: no level and no stock rows for Brownie; the configured zero keeps its «out» level; the free product has no stock at all.
        $rows = collect($this->get('/catalog')->viewData('page')['props']['products']['data'])->keyBy('name');
        $this->assertSame([true, [], null], [$rows['Brownie']['stock']['tracked'], $rows['Brownie']['stock']['stocks'], $rows['Brownie']['stock']['level']]);
        $this->assertSame('out', $rows['Agotado']['stock']['level']);
        $this->assertFalse($rows['Libre']['stock']['tracked']);

        // Looking at it changes nothing: the control stays on and no stock or movement appears.
        $this->assertTrue($brownie->fresh()->tracks_inventory);
        $this->assertSame(0, InventoryStock::where('product_id', $brownie->id)->count());
        $this->assertSame(0, InventoryMovement::where('product_id', $brownie->id)->count());
    }

    public function test_the_operations_report_keeps_unconfigured_apart_from_out_of_stock(): void
    {
        $this->product(['name' => 'Brownie', 'tracks_inventory' => true]);
        $agotado = $this->product(['name' => 'Agotado', 'tracks_inventory' => true]);
        $service = app(InventoryService::class);
        $service->receive($agotado, $this->centro->id, 2, $this->admin);
        $service->adjust($agotado, $this->centro->id, 0, $this->admin, 'Conteo');
        $this->contractModules($this->workspace, ['dashboard', 'reports', 'product_catalog', 'orders', 'locations']);

        $kpis = $this->get('/reports?period=30d')->assertOk()->viewData('page')['props']['operations']['inventory']['data']['kpis'];

        $this->assertSame([2, 1, 1], [$kpis['tracked'], $kpis['out'], $kpis['unconfigured']]);
    }
}
