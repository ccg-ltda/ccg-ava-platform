<?php

namespace App\Orders;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the database already has the catalog and orders tables (migration `create_catalog_and_orders_tables`). The code can be deployed before
 * a migration is applied: the pages that read then open and say what is missing instead of failing on a missing table. Checked once per request.
 */
class CommerceSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('products') && Schema::hasTable('location_product') && Schema::hasTable('orders') && Schema::hasTable('order_lines') && Schema::hasTable('order_status_changes');
    }
}
