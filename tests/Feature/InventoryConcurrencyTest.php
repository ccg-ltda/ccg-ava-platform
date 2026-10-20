<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * What the database itself guarantees about stock, whatever the code does: a quantity can never be negative and one order can never discount the same product
 * twice. The application serializes two sales of the same stock with a row lock (InventoryService), and these constraints are the last barrier if anything slipped
 * through. PostgreSQL has the checks (SQLite cannot add them to an existing table), so these tests run ONLY on PostgreSQL and say so when skipped. Run them with
 * `DB_CONNECTION=pgsql DB_DATABASE=<a disposable *_test database> php artisan test --filter=InventoryConcurrencyTest`.
 */
class InventoryConcurrencyTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The non-negative stock checks are PostgreSQL constraints: run this test against PostgreSQL.');
        }

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog', 'orders', 'locations']);
        $this->location = $this->workspace->locations()->create(['name' => 'Centro', 'is_active' => true]);
        $this->product = $this->workspace->products()->create(['name' => 'Crema', 'price' => '10.00', 'tracks_inventory' => true]);
    }

    private function refused(callable $write): bool
    {
        try {
            DB::transaction($write);

            return false;
        } catch (QueryException $exception) {
            return in_array($exception->getCode(), ['23514', '23505'], true); // check_violation, unique_violation
        }
    }

    public function test_the_database_refuses_a_negative_stock_or_minimum(): void
    {
        $row = ['workspace_id' => $this->workspace->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id, 'min_quantity' => 0, 'created_at' => now(), 'updated_at' => now()];

        $this->assertTrue($this->refused(fn () => DB::table('inventory_stocks')->insert($row + ['quantity' => -1])), 'negative quantity');
        $this->assertTrue($this->refused(fn () => DB::table('inventory_stocks')->insert(['quantity' => 1, 'min_quantity' => -2] + $row)), 'negative minimum');
        DB::table('inventory_stocks')->insert($row + ['quantity' => 1]);
        $this->assertTrue($this->refused(fn () => DB::table('inventory_stocks')->update(['quantity' => DB::raw('quantity - 2')])), 'a discount that would leave less than zero');
        $this->assertSame(1, (int) DB::table('inventory_stocks')->value('quantity'));
    }

    public function test_the_database_refuses_a_second_sale_of_the_same_order_and_product(): void
    {
        DB::table('inventory_stocks')->insert(['workspace_id' => $this->workspace->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10, 'min_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $order = DB::table('orders')->insertGetId(['workspace_id' => $this->workspace->id, 'number' => 1, 'customer_name' => 'Laura', 'currency' => 'COP', 'total' => '10.00', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(), 'status_changed_at' => now()]);
        $sale = ['workspace_id' => $this->workspace->id, 'product_id' => $this->product->id, 'location_id' => $this->location->id, 'type' => 'sale', 'quantity_change' => -1, 'quantity_before' => 10, 'quantity_after' => 9, 'order_id' => $order, 'created_at' => now()];

        DB::table('inventory_movements')->insert($sale);

        $this->assertTrue($this->refused(fn () => DB::table('inventory_movements')->insert($sale)), 'the same sale twice');
        $this->assertTrue($this->refused(fn () => DB::table('inventory_movements')->insert(['type' => 'unknown'] + $sale)), 'an unknown type');
        $this->assertSame(1, DB::table('inventory_movements')->count());
    }
}
