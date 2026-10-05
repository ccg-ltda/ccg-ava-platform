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
        // One row per administrative change. `changes` keeps only the relevant, already redacted differences (never
        // snapshots or secrets). The user and the Workspace are referenced by id AND copied by name, so history stays
        // readable when they are renamed or the user is removed. No browser data is stored; the IP is.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workspace_code', 100)->nullable();
            $table->string('workspace_name', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 255);
            $table->string('user_email', 255);
            $table->string('action', 16);
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('resource_label', 255);
            $table->string('description', 500);
            $table->json('changes');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'resource_type']);
            $table->index(['workspace_id', 'user_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
