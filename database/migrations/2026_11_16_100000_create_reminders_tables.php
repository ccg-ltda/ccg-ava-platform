<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recordatorios (RECOMENDACIONES.md §79.2): commercial reminders and follow-ups a Workspace schedules for its customers (e.g. «invite them to book again 15 days after the
 * service»). Independent of Agenda: its own tables, no foreign key to appointments or orders (an optional soft reference, validated against the SAME Workspace by the service
 * and re-checked when it is sent). Additive: two NEW tables, nothing existing changes. `down()` drops both (the scheduling history is lost; no business data is touched).
 *  - reminders: one scheduled message to ONE customer through ONE channel. `send_at` is UTC; `timezone` is how the person typed it. `status` is the lifecycle
 *    (scheduled | processing | sent | failed | cancelled | skipped | uncertain); `reason` is a fixed code, never provider text. `idempotency_key` makes a repeated
 *    request (a retry of the UI or of the API) return the same reminder instead of creating a second one.
 *  - reminder_attempts: one row per try (outcome + fixed reason), the history shown to the team.
 * Composite foreign keys: a reminder can only point at a customer of ITS OWN Workspace, and an attempt only at a reminder of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->string('name', 120);
            $table->string('purpose', 255)->nullable();
            $table->string('related_type', 12)->nullable(); // appointment | order | service
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('channel', 12); // email | whatsapp
            $table->string('subject', 150)->nullable(); // email only
            $table->text('body'); // with {{variables}} of config/campaigns.php, rendered per customer when it is sent
            $table->string('template_name', 120)->nullable(); // whatsapp only: an approved template (a follow-up is always outside the 24 h window)
            $table->string('template_language', 12)->nullable();
            $table->json('template_params')->nullable(); // ordered list of variable keys
            $table->timestamp('send_at'); // UTC
            $table->string('timezone', 64);
            $table->string('status', 12)->default('scheduled');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('reason', 60)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('idempotency_key', 64);
            $table->string('source', 8)->default('user'); // user | api
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['workspace_id', 'status', 'send_at']);
            $table->index(['workspace_id', 'customer_id']);
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('reminder_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reminder_id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('outcome', 12); // sent | retry | failed | skipped | cancelled | uncertain
            $table->string('reason', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reminder_id', 'id']);
            $table->foreign(['reminder_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('reminders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_attempts');
        Schema::dropIfExists('reminders');
    }
};
