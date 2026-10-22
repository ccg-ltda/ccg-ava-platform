<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two additive, reversible tables. Nothing existing is read, rewritten or dropped.
 *
 *  - email_templates: the GLOBAL HTML template of the platform's emails (key `global`). It is DATA (text), never code: it is validated against a whitelist when it
 *    is saved and again when it is used, and a missing or invalid row falls back to the default template that ships with the application. Only the platform
 *    administration of the administrative Workspace edits it.
 *  - catalog_reminders: a reminder written by a member of a Workspace, scheduled for a date and time, that is emailed to THE CREATOR (the recipient is never a
 *    column the client sends: it is the creator's current account at the moment of sending). It carries its own state machine (scheduled -> queued -> sending ->
 *    sent / failed / uncertain / cancelled / skipped), the attempts made and a short sanitized reason, so support can see what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->longText('html');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('catalog_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 160);
            $table->text('body');
            $table->timestamp('scheduled_at'); // UTC; the form works in the Workspace's time zone
            $table->string('status', 12)->default('scheduled'); // scheduled | queued | sending | sent | failed | uncertain | cancelled | skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to', 190)->nullable(); // the creator's address at the moment it was sent (their own data, for support)
            $table->string('reason', 255)->nullable(); // fixed code or a short sanitized description of why it did not go out
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['workspace_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_reminders');
        Schema::dropIfExists('email_templates');
    }
};
