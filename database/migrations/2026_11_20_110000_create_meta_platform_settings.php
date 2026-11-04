<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Meta app of the PLATFORM (one for CCG/Ava, never one per Workspace): App ID, app secret, Instagram app ID and secret, webhook verify token and Graph version, managed from
 * Configuraciones by the platform administration instead of editing `.env` for every new account. A single row; secrets are encrypted at rest. A value left empty falls back to the
 * environment variable it always came from, so nothing changes until someone saves here. The Workspaces' own connections (tokens, accounts) stay in `integrations`. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 40)->nullable();
            $table->text('app_secret')->nullable();
            $table->string('instagram_app_id', 40)->nullable();
            $table->text('instagram_app_secret')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->string('graph_version', 12)->nullable();
            $table->string('validation_status', 12)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validation_message', 255)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_platform_settings');
    }
};
