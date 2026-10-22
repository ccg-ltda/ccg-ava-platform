<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A solution (module category) is ACTIVE (usable by the administration) and, separately, PUBLIC (offered in the public sign-up). Additive and reversible.
 * The value of existing rows keeps today's public offer exactly: a solution that has at least one active, public plan is marked public (it is what the sign-up
 * offers now, so applying this migration changes nothing visible); every other row, and every new one, starts private (nothing becomes public by itself).
 * No row is deleted and no plan, module, Workspace or subscription is touched. `down()` drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_categories', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('is_active');
        });

        if (Schema::hasColumn('plans', 'is_public') && Schema::hasColumn('plans', 'module_category_id')) {
            DB::table('module_categories')->whereIn('id', DB::table('plans')->where('is_active', true)->where('is_public', true)->whereNotNull('module_category_id')->select('module_category_id'))->update(['is_public' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('module_categories', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
