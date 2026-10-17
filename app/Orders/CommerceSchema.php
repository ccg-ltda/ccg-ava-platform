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

    private ?bool $linked = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('products') && Schema::hasTable('location_product') && Schema::hasTable('orders') && Schema::hasTable('order_lines') && Schema::hasTable('order_status_changes');
    }

    /**
     * Whether orders can be tied to the conversation they came from (migration `link_orders_to_conversations`). The assistant's order tools need it
     * (a contact only sees the orders of their own conversation, and a retried call must not place a second order); without it they answer that they
     * are not ready instead of failing on a missing column, and the orders typed in the admin keep working.
     */
    public function conversationLinkReady(): bool
    {
        return $this->linked ??= $this->ready() && Schema::hasColumn('orders', 'conversation_id') && Schema::hasColumn('orders', 'idempotency_key') && Schema::hasColumn('orders', 'idempotency_fingerprint');
    }
}
