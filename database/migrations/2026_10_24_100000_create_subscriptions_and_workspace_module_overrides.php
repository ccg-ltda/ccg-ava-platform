<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two additive tables; nothing existing is altered, dropped or rewritten.
 *  - `subscriptions`: the contract of a client Workspace with a plan (state + payment state). A Workspace WITHOUT a row keeps working exactly as before
 *    (access by its plan), so no existing Workspace changes behavior when this migration runs.
 *  - `workspace_module_overrides`: authorized exceptions of ONE Workspace over its plan (grant a module the plan lacks, or revoke one it includes). The plan
 *    is never edited by an exception and no other Workspace sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // pending | active | grace | suspended | cancelled | expired (App\Models\Subscription)
            $table->string('status', 20)->default('pending');
            // none | pending | confirmed | failed (only a verified payment event may set `confirmed`)
            $table->string('payment_status', 20)->default('pending');
            // Reference of the confirmed payment given by the (future) payment provider: makes repeated notifications idempotent.
            $table->string('payment_reference', 120)->nullable()->unique();
            // True when registering this subscription deactivated a brand-new customer's Workspace: only then does its FIRST activation switch the Workspace on.
            $table->boolean('activates_workspace')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_module_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->string('mode', 10); // grant | revoke
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_module_overrides');
        Schema::dropIfExists('subscriptions');
    }
};
