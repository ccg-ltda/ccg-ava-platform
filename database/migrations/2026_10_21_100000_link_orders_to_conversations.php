<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and compatible with every existing order: the three columns are nullable and every order that exists keeps them NULL (nothing is filled in).
 *  - `conversation_id`: the conversation an order was placed from by the assistant (Ava Chat). It lets a contact see ONLY the orders of their own
 *    conversation and nothing else. The composite foreign key makes the database refuse a link to a conversation of another Workspace; `restrict`
 *    means a conversation with orders is never deleted by a cascade (orders are never deleted).
 *  - `idempotency_key`: the digest that identifies one order REQUEST from the assistant (conversation + the caller's own key: the running execution or an
 *    `Idempotency-Key` header), so a retried tool call returns the order that already exists instead of placing a second one. Unique per Workspace;
 *    NULL for orders typed in the admin. The raw key the caller sent is never stored.
 *  - `idempotency_fingerprint`: the digest of the CONTENT of that request. The same key with the same content is a retry (the existing order is
 *    returned); the same key with another content is a conflict (refused with 409, nothing is created), so a key can never be reused to place a different order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('customer_id');
            $table->string('idempotency_key', 64)->nullable()->after('conversation_id');
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['conversation_id']);
            $table->foreign(['conversation_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['conversation_id', 'workspace_id']);
            $table->dropUnique(['workspace_id', 'idempotency_key']);
            $table->dropIndex(['conversation_id']);
            $table->dropColumn(['conversation_id', 'idempotency_key', 'idempotency_fingerprint']);
        });
    }
};
