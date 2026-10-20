<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Inventory of the Catálogo, per location. ADDITIVE and compatible with every existing product and order: nothing is dropped, renamed or rewritten except that the
 * free-text `products.category` is turned into a normalized catalog (below). Every row carries its Workspace and composite foreign keys make it impossible to link
 * rows of different Workspaces (the pattern of the other modules).
 *
 *  - `product_categories`: the valid categories of a Workspace. `normalized_name` (lower case, single spaces) is unique per Workspace, so «Comida», «comida » and
 *    «COMIDA» are the same category. Existing products keep their category: one category is created per distinct normalized text a Workspace already used, and
 *    `products.category_id` points to it; `products.category` stays as a copy of the category name (read by the assistant's catalog tool and old consumers).
 *  - `products`: `category_id`, `image_path` (a path on the default disk, never the file), `cost` (optional unit cost) and `tracks_inventory` (false for every existing
 *    product: until someone turns it on, a product behaves exactly as before and no stock is ever invented for it).
 *  - `inventory_stocks`: the quantity of a product in ONE location (`location_id` NULL = the Workspace's general stock, for businesses without locations): the product
 *    is the same, only the stock varies by place. `min_quantity` is the alert threshold of that location.
 *  - `inventory_movements`: the trace of every change of a stock (entry, manual adjustment, sale, reversal of a sale by a cancellation) with the quantity before and
 *    after, who did it and, for a sale, the order. A partial unique index admits ONE sale and ONE reversal per order and product: a sale can never be discounted twice.
 * PostgreSQL also refuses a negative stock by itself (SQLite cannot add the checks to an existing table; the application validates the same).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'normalized_name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('category');
            $table->string('image_path', 255)->nullable()->after('description');
            $table->decimal('cost', 12, 2)->nullable()->after('price');
            $table->boolean('tracks_inventory')->default(false)->after('is_active');
            $table->foreign(['category_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('product_categories')->restrictOnDelete();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('min_quantity')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
            $table->index(['workspace_id', 'location_id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['product_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('products')->restrictOnDelete();
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('type', 16); // entry | adjustment | sale | cancellation
            $table->integer('quantity_change');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'product_id', 'id']);
            $table->index(['workspace_id', 'created_at']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['product_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('products')->restrictOnDelete();
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['order_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('orders')->restrictOnDelete();
        });

        // One general stock per product (NULL never collides in a plain unique index), and one sale / one reversal per order and product.
        DB::statement('CREATE UNIQUE INDEX inventory_stocks_general_unique ON inventory_stocks (product_id) WHERE location_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX inventory_movements_order_unique ON inventory_movements (order_id, product_id, type) WHERE order_id IS NOT NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_known CHECK (type IN ('entry','adjustment','sale','cancellation'))");
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_cost_nonnegative CHECK (cost IS NULL OR cost >= 0)');
            DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT inventory_stocks_nonnegative CHECK (quantity >= 0 AND min_quantity >= 0)');
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_nonnegative CHECK (quantity_before >= 0 AND quantity_after >= 0)');
        }

        $this->backfillCategories();
    }

    /**
     * One category per distinct normalized text each Workspace already used; the product keeps its category (now as a link). Products without a category stay without.
     * Nothing is deleted and no product loses information: only the capitalization and spacing of the same text are unified.
     */
    private function backfillCategories(): void
    {
        $normalize = fn (string $text) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        $ids = [];

        foreach (DB::table('products')->whereNotNull('category')->orderBy('id')->get(['id', 'workspace_id', 'category']) as $product) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $product->category));

            if ($name === '') {
                continue;
            }

            $key = $product->workspace_id.'|'.$normalize($name);
            $ids[$key] ??= DB::table('product_categories')->insertGetId(['workspace_id' => $product->workspace_id, 'name' => $name, 'normalized_name' => $normalize($name), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('products')->where('id', $product->id)->update(['category_id' => $ids[$key], 'category' => DB::table('product_categories')->where('id', $ids[$key])->value('name')]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_movements_order_unique');
        DB::statement('DROP INDEX IF EXISTS inventory_stocks_general_unique');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stocks');

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id', 'workspace_id']);
            $table->dropColumn(['category_id', 'image_path', 'cost', 'tracks_inventory']);
        });
        Schema::dropIfExists('product_categories');
    }
};
