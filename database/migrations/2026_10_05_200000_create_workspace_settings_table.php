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
        // Preferences of one Workspace (Configuraciones). At most one row per Workspace; no row = defaults.
        // The name stays in `workspaces`; the allowed values of each option live in config/workspace.php.
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('DOP');
            $table->string('timezone', 64)->default('America/Santo_Domingo');
            $table->string('date_format', 8)->default('dmy');
            $table->string('time_format', 8)->default('12h');
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#1d4ed8');
            $table->string('appearance', 8)->default('light');
            $table->string('tax_country', 2)->default('DO');
            $table->boolean('tax_enabled')->default(true);
            $table->string('tax_name', 40)->default('ITBIS');
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
