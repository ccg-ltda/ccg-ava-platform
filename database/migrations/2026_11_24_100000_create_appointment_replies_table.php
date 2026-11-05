<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer answered with the buttons of a WhatsApp reminder (Confirmar, Reprogramar, Cancelar): one row per answer, so Agenda and the history of reminders can show
 * what happened and a repeated webhook cannot apply an answer twice (`workspace_id` + `external_id`, the id Meta gave to the customer's message, is unique).
 * Additive: it never touches an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('notification_id')->nullable(); // the reminder whose button was pressed
            $table->string('action', 16); // confirm | reschedule | cancel
            $table->string('result', 24); // applied | already_done | closed | expired | not_authorized
            $table->string('channel', 16)->default('whatsapp');
            $table->string('external_id', 128); // Meta's id of the customer's message: the repeat guard
            $table->timestamp('responded_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'external_id']);
            $table->index(['workspace_id', 'appointment_id']);
            $table->foreign(['appointment_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('appointments')->restrictOnDelete();
            // No foreign key to appointment_notifications on purpose: that table belongs to an earlier migration whose rollback must not be blocked by a later table, and the reply keeps its own
            // appointment (the composite key above), so a missing notification row loses nothing but the link to the reminder.
            $table->index('notification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_replies');
    }
};
