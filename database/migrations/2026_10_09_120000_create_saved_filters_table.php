<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Search criteria a user saved under a name to reuse them later (module filters). A saved filter belongs to ONE
        // user inside ONE Workspace and to ONE module (`scope`, defined in config/saved_filters.php): the same name can
        // exist in another module, Workspace or user. `criteria` holds only the filter values (never results or data of
        // the records). Additive: no existing table is touched.
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32);
            $table->string('name', 60);
            $table->json('criteria');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id', 'scope', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
