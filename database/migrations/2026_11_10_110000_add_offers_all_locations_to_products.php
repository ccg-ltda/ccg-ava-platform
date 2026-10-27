<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «Todas las sedes» of a product: an explicit COVERAGE mark (where the product may be offered and sold), distinct from a historical product that was simply never assigned
 * (which stays flagged «Sin sede asignada»). It creates no stock and moves none: the quantities live in `inventory_stocks`, one row per sede, and a sede without a row for a
 * product has nothing to sell. Additive and false for every product that exists; the rows of `location_product` are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('offers_all_locations')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('offers_all_locations');
        });
    }
};
