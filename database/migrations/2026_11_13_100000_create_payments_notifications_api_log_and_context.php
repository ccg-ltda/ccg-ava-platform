<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive only (nothing existing is altered except new nullable columns):
 *  - payment_provider_settings: operational credentials of the payment provider, one row per provider and environment, secrets encrypted by the model.
 *  - workspace_notifications (+ reads): the common persisted notifications of every Workspace; read state is per user.
 *  - api_call_logs: redacted history of the outbound calls to external APIs.
 *  - conversations.context: the structured, persisted conversational state the workflow resumes from.
 *  - workspace_settings.conversation_autoclose_*: the inactivity close of a Workspace (null = legacy behavior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('environment', 12); // sandbox | production
            $table->boolean('is_active')->default(false); // the environment in use; at most one row per provider (enforced by the service)
            $table->string('public_key', 120)->nullable();
            $table->text('private_key')->nullable(); // encrypted
            $table->text('events_secret')->nullable(); // encrypted
            $table->text('integrity_secret')->nullable(); // encrypted
            $table->string('validation_status', 12)->nullable(); // ok | failed | null = never validated
            $table->timestamp('validated_at')->nullable();
            $table->string('validation_message', 255)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'environment']);
        });

        Schema::create('workspace_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = every member allowed by `permission`
            $table->string('permission', 60)->nullable();
            $table->string('type', 40);
            $table->string('title', 140);
            $table->string('body', 255)->nullable();
            $table->string('resource_type', 32)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('action_url', 255)->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'dedupe_key']);
            $table->index(['workspace_id', 'id']);
        });

        Schema::create('workspace_notification_reads', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained('workspace_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->primary(['notification_id', 'user_id']);
        });

        Schema::create('api_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete(); // null = a platform-level call
            $table->string('integration', 32);
            $table->string('operation', 64);
            $table->string('method', 8);
            $table->string('host', 120);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status_code')->nullable(); // null = no HTTP response (timeout, connection failure)
            $table->string('outcome', 16); // success | http_error | timeout | connection_error
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('correlation_id', 64);
            $table->string('error_class', 120)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->text('request_headers')->nullable();
            $table->text('request_body')->nullable();
            $table->text('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('resource_type', 32)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'id']);
            $table->index(['integration', 'created_at']);
            $table->index('correlation_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->json('context')->nullable();
        });

        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->boolean('conversation_autoclose_enabled')->nullable();
            $table->unsignedSmallInteger('conversation_autoclose_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', fn (Blueprint $table) => $table->dropColumn(['conversation_autoclose_enabled', 'conversation_autoclose_minutes']));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('context'));
        Schema::dropIfExists('api_call_logs');
        Schema::dropIfExists('workspace_notification_reads');
        Schema::dropIfExists('workspace_notifications');
        Schema::dropIfExists('payment_provider_settings');
    }
};
