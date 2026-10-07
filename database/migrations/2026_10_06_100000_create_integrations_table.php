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
        // One external connection of a Workspace. `type` selects the class that understands `config`
        // (config/integrations.php); `config` holds the non-secret, type-specific settings and `secrets` an
        // ENCRYPTED blob (Laravel `encrypted:array`) with every credential. Nothing is shared between Workspaces.
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('provider', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('type', 32)->default('http');
            $table->boolean('is_active')->default(true);
            $table->json('config');
            $table->longText('secrets')->nullable();
            // Result of the last connection test (a short sanitized summary; responses are never stored).
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->unsignedSmallInteger('last_test_status')->nullable();
            $table->string('last_test_message', 255)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            // Lets a chatbot's channel point at an integration AND its Workspace together (composite foreign key).
            $table->unique(['id', 'workspace_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
