<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Outbound webhooks of a Workspace (RECOMENDACIONES.md §78). Additive: two NEW tables, nothing existing changes.
 *  - webhook_endpoints: where a Workspace wants its events sent. The signing secret is encrypted at rest and never leaves the server after it is shown once.
 *  - webhook_deliveries: one row per (endpoint, event): the exact JSON that is signed and sent, its state and attempts. Retention is bounded (`webhooks:prune`).
 * Composite foreign key: a delivery can only belong to an endpoint of ITS OWN Workspace. `down()` drops both (the history is lost; no business data is touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('url', 2048);
            $table->text('secret'); // encrypted
            $table->string('secret_hint', 8)->nullable();
            $table->text('previous_secret')->nullable(); // encrypted; signs the second header until `previous_secret_expires_at`
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->json('events'); // list of catalog event names
            $table->boolean('is_active')->default(true);
            $table->string('disabled_reason', 20)->nullable(); // failures | manual
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->unique(['id', 'workspace_id']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique(); // X-Ava-Delivery
            $table->unsignedBigInteger('webhook_endpoint_id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->uuid('event_id'); // X-Ava-Event-Id: the same for every endpoint, the consumer's deduplication key
            $table->string('status', 12)->default('pending'); // pending | sending | delivered | failed (will retry) | abandoned
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 40)->nullable(); // normalized code, never provider text
            $table->longText('payload'); // the exact JSON body that is signed
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign(['webhook_endpoint_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('webhook_endpoints')->cascadeOnDelete();
            $table->unique(['webhook_endpoint_id', 'event_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['workspace_id', 'webhook_endpoint_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
