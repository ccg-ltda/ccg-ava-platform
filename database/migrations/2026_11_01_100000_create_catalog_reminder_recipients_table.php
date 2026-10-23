<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who receives each reminder of the Catálogo. Until now the only recipient was the creator; a reminder can now name one or several members of its Workspace.
 *
 *  - catalog_reminder_recipients: one row per reminder and person. `workspace_id` repeats the reminder's Workspace so the row can be listed and audited by
 *    Workspace; the application validates that the person is an enabled member of it when the reminder is saved AND again when it is sent. `sent_at` / `sent_to`
 *    record what was actually delivered to that person (the address at that moment) and make a retry skip the people who already received it; `skip_reason` is a
 *    fixed code for a person who could not be notified (no longer a member, disabled, no address...). Additive: reminders created before this table have no rows
 *    and keep being sent to their creator, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_reminder_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_reminder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to', 190)->nullable();
            $table->string('skip_reason', 40)->nullable();
            $table->timestamps();

            $table->unique(['catalog_reminder_id', 'user_id']);
            $table->index(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_reminder_recipients');
    }
};
