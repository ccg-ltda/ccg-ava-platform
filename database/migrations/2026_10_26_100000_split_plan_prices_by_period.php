<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One offer, two prices. A plan (under its solution) carries a monthly price and a yearly total price in the same currency, instead of ONE price with ONE
 * period: the administrator no longer creates a duplicate plan per periodicity. Each period is sold only if its own price is configured.
 *
 *  - plans.price_monthly_cents / price_yearly_cents: minor units, null = that period is not sold.
 *  - The previous single price (price_cents + billing_period, from `create_public_signup_tables`) is COPIED to the matching new column and then dropped.
 *    Nothing is invented: a plan without a price keeps both columns null.
 *  - signup_requests keeps its own price_cents / currency / billing_period: they are the snapshot of what the customer chose and saw, and they never
 *    change when the plan does.
 *
 * Run it deliberately in the authorized environment, AFTER `2026_10_25_100000` (`make dev-sync` locally, `make prod-migrate`). `down()` restores the single
 * price (the monthly one if both exist; the yearly total is lost, as it has no place in the old shape).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_monthly_cents')->nullable()->after('module_category_id');
            $table->unsignedBigInteger('price_yearly_cents')->nullable()->after('price_monthly_cents');
        });

        DB::table('plans')->where('billing_period', 'monthly')->whereNotNull('price_cents')->update(['price_monthly_cents' => DB::raw('price_cents')]);
        DB::table('plans')->where('billing_period', 'yearly')->whereNotNull('price_cents')->update(['price_yearly_cents' => DB::raw('price_cents')]);

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_cents', 'billing_period']);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->nullable()->after('module_category_id');
            $table->string('billing_period', 10)->nullable()->after('currency');
        });

        DB::table('plans')->whereNotNull('price_yearly_cents')->update(['price_cents' => DB::raw('price_yearly_cents'), 'billing_period' => 'yearly']);
        DB::table('plans')->whereNotNull('price_monthly_cents')->update(['price_cents' => DB::raw('price_monthly_cents'), 'billing_period' => 'monthly']);

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price_monthly_cents', 'price_yearly_cents']);
        });
    }
};
