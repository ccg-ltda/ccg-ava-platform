<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trace of the business tools Ava runs for an assistant (booking and order tools): one row per call, so whoever takes a conversation over can see what
 * the assistant asked Ava to do and what Ava really did. It is NOT the audit log (that one records administrative changes, is shown to administrators and
 * is exported) and it is not the assistant's reasoning: it holds only operational facts.
 *  - `status` separates what happened: `started` (the call arrived and nothing is known yet: it is also what stays when the process died before the
 *    outcome could be written), `succeeded` (Ava answered 2xx AFTER the operation was persisted), `rejected` (Ava refused it: validation, state, not found...),
 *    `failed` (Ava could not do it) and `uncertain` (the outcome is unknown, e.g. an external agenda that did not answer).
 *  - `notification` says what Ava planned to tell the customer, never that it was sent: `none`, `planned` (rows exist in the module's own notification table) or
 *    `unavailable` (no channel is connected). Delivery is recorded where it happens (appointment_notifications), not here.
 *  - `idempotency_ref` is the digest of the call's idempotency key (never the raw key); it lets a `started` row be reconciled against the operation it was about.
 * No tokens, no message bodies, no names, phones, addresses or notes, and no free text: `reason` is a fixed code. Additive: a new table, nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chatbot_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('execution_ref', 36)->nullable(); // the correlation id of the workflow execution that made the call (run-scoped token only)
            $table->string('tool', 40); // route name without the "agent." prefix, e.g. booking.book, orders.store
            $table->string('kind', 5); // read | write
            $table->string('auth', 9); // execution (run-scoped token) | chatbot (long-lived token)
            $table->string('status', 10)->default('started');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('idempotency_ref', 64)->nullable();
            $table->string('resource_type', 16)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->boolean('replayed')->default(false);
            $table->string('result_state', 24)->nullable();
            $table->string('notification', 12)->default('none');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['conversation_id', 'id']);
            $table->index(['workspace_id', 'tool', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('idempotency_ref');
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->restrictOnDelete();
            $table->foreign(['conversation_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
