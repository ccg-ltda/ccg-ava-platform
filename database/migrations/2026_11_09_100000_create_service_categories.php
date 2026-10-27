<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Valid categories of the services of a Workspace (same model as the product categories). Additive: `services.category_id` is new and nullable, and the text
 * column `services.category` stays as the copy of the name that reports and old rows keep. Existing free-text categories become categories of their Workspace
 * (the same text in another capitalization or spacing becomes ONE category); nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'normalized_name']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('category')->constrained('service_categories')->nullOnDelete();
        });

        $now = now();

        DB::table('services')->whereNotNull('category')->where('category', '!=', '')->orderBy('id')->get(['id', 'workspace_id', 'category'])->each(function ($service) use ($now) {
            $clean = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $service->category)), 0, 80);
            $normalized = mb_strtolower($clean);

            if ($clean === '') {
                return;
            }

            $category = DB::table('service_categories')->where('workspace_id', $service->workspace_id)->where('normalized_name', $normalized)->first();
            $id = $category->id ?? DB::table('service_categories')->insertGetId(['workspace_id' => $service->workspace_id, 'name' => $clean, 'normalized_name' => $normalized, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('services')->where('id', $service->id)->update(['category_id' => $id, 'category' => $category->name ?? $clean]);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('service_categories');
    }
};
