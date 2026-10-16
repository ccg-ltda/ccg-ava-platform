<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Catálogo de productos and Pedidos (E-commerce and Delivery). ADDITIVE: new tables only, nothing existing is touched.
 * Every row carries its Workspace and composite foreign keys make it impossible to link rows of different Workspaces (the pattern of
 * bookings and customers). Nothing used by an order is ever deleted: foreign keys from orders restrict, and products and locations are only
 * deactivated. An order keeps what it was bought as (`product_name`, `product_sku`, `unit_price`, `currency`, `location_name`), so editing the
 * catalog never rewrites a past order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('name', 160);
            $table->string('description', 2000)->nullable();
            $table->string('category', 80)->nullable();
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'sku']); // a NULL sku never collides
            $table->index(['workspace_id', 'is_active', 'name']);
        });

        // Which locations offer a product. A product without rows here is offered in every location (same rule as services).
        Schema::create('location_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');

            $table->unique(['location_id', 'product_id']);
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->cascadeOnDelete();
            $table->foreign(['product_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // the order number the business and the customer see: 1, 2, 3... per Workspace
            $table->unsignedBigInteger('customer_id')->nullable(); // the Clientes record, when the module exists and the order was tied to it
            $table->string('customer_name', 120); // contact needed to operate the order, as it was when placed
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('delivery_method', 10)->default('pickup'); // pickup | delivery
            $table->string('delivery_address', 255)->nullable();
            $table->string('delivery_city', 120)->nullable();
            $table->string('delivery_reference', 255)->nullable();
            $table->string('recipient_name', 120)->nullable(); // only when the person who receives is not the buyer
            $table->string('recipient_phone', 32)->nullable();
            $table->unsignedBigInteger('location_id')->nullable(); // the point of operation, when the Workspace uses locations
            $table->string('location_name', 120)->nullable();
            $table->string('currency', 3);
            $table->decimal('total', 14, 2);
            $table->string('status', 16)->default('received');
            $table->timestamp('status_changed_at')->useCurrent();
            $table->string('notes', 500)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->restrictOnDelete();
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name', 160);
            $table->string('product_sku', 64)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 14, 2);

            $table->index('order_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['order_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('orders')->restrictOnDelete();
            $table->foreign(['product_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('products')->restrictOnDelete();
        });

        // The trace of an order: every state it went through, who moved it and why.
        Schema::create('order_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('order_id');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['order_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('orders')->restrictOnDelete();
        });

        // PostgreSQL also refuses impossible values by itself (SQLite cannot add them to an existing table; the application validates them anyway).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_nonnegative CHECK (price >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_nonnegative CHECK (total >= 0)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_known CHECK (status IN ('received','confirmed','preparing','ready','dispatched','delivered','cancelled'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_method_known CHECK (delivery_method IN ('pickup','delivery'))");
            DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_quantity_positive CHECK (quantity > 0 AND unit_price >= 0)');
        }
    }

    public function down(): void
    {
        foreach (['order_status_changes', 'order_lines', 'orders', 'location_product', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
