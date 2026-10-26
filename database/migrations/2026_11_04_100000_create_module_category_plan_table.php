<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plan can be offered under several commercial solutions (module categories): «Pro» under Salud, E-commerce, Delivery... without duplicating the plan.
 *
 *  - `module_category_plan`: one row per plan and solution. UNIQUE (plan_id, module_category_id): a plan appears once per solution, however many times the
 *    relation is edited. Both keys are RESTRICT: plans and categories are never deleted by the application (they are deactivated), and the database would refuse.
 *  - Data: every plan that has `plans.module_category_id` gets one row for that solution. Nothing is merged, renamed or re-priced and no plan, module, Workspace
 *    or subscription is touched, so applying this changes nothing that is visible or contracted. A plan without a solution (custom/quote) gets no row.
 *  - `plans.module_category_id` is NOT dropped. It stays as the plan's PRIMARY solution (the first one), kept in step by the application, so the previous release
 *    can still read the plan after a rollback. Nothing reads it for the offer any more.
 *
 * Reversal: down() first writes back the primary solution into `plans.module_category_id` for plans whose column is empty (an earlier rollback of the code may
 * have left it so) and then drops the table. The solutions beyond the first of a multi-solution plan are lost (the old schema holds one): restore them from the
 * backup taken before applying this migration if they matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_category_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('module_category_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'module_category_id']);
            $table->index('module_category_id');
        });

        if (Schema::hasColumn('plans', 'module_category_id')) {
            DB::table('plans')->whereNotNull('module_category_id')->orderBy('id')->select(['id', 'module_category_id'])->each(function (object $plan) {
                DB::table('module_category_plan')->insertOrIgnore([
                    'plan_id' => $plan->id, 'module_category_id' => $plan->module_category_id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'module_category_id')) {
            DB::table('plans')->whereNull('module_category_id')->orderBy('id')->select('id')->each(function (object $plan) {
                $first = DB::table('module_category_plan')->where('plan_id', $plan->id)->orderBy('id')->value('module_category_id');

                if ($first !== null) {
                    DB::table('plans')->where('id', $plan->id)->update(['module_category_id' => $first]);
                }
            });
        }

        Schema::dropIfExists('module_category_plan');
    }
};
