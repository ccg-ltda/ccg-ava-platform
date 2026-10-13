<?php

use App\Services\ModuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Commercial catalog: module categories, modules, plans, the modules of each plan and the plan of each Workspace.
 * Additive on purpose (the development database already has Workspaces): nothing is dropped or rewritten, and every
 * existing client Workspace is given the default plan with every shared module so no one loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Organizational groups of modules. `key` is the stable technical identity (created once, never edited).
        Schema::create('module_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A capability a plan can include. Being implemented is decided by config/modules.php, never by this table.
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->foreignId('module_category_id')->constrained('module_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Commercial plans created by CCG. `key` only identifies the system plan (null for the ones created in the UI).
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable()->unique();
            $table->string('name')->unique();
            $table->string('description', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('module_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'module_id']);
        });

        // One plan per Workspace. Null = the default plan; the administrative Workspace ignores it.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
        });

        $catalog = app(ModuleCatalog::class);
        $catalog->sync();
        $plan = $catalog->ensureDefaultPlan();

        DB::table('workspaces')->whereNull('plan_id')
            ->where('code', '!=', strtoupper(trim((string) config('workspace.admin_code'))))
            ->update(['plan_id' => $plan->id]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
        Schema::dropIfExists('module_plan');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('module_categories');
    }
};
