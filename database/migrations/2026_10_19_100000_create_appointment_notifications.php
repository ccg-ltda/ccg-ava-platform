<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Confirmations and reminders of appointments, one shared capability of Servicios y Reservas (Salud, salons, any business with bookings).
 * ADDITIVE and compatible with every existing row: nothing is sent, planned or filled in for what already exists.
 *  - `booking_settings`: the per-Workspace configuration. Everything is OFF/empty by default (no anticipation, no text, no template is
 *    assumed for anybody); a Workspace without these values behaves exactly as before.
 *  - `appointments.customer_confirmed_at`: the customer said they will come. It is NOT `status` (the business accepting a booking is a
 *    different thing) and it is cleared when the appointment is moved. NULL for every historical appointment.
 *  - `appointment_notifications`: one row per message Ava plans to send. The unique key (appointment, kind, offset, version) makes a
 *    duplicate impossible even if two schedulers or two workers run at once; `version` is the appointment's `reschedule_count` when it
 *    was planned, so moving an appointment retires the old plan and allows the new one. History is kept (nothing cascades).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_settings', function (Blueprint $table) {
            $table->boolean('notify_received')->default(false);
            $table->boolean('notify_confirmed')->default(false);
            $table->boolean('reminders_enabled')->default(false);
            $table->json('reminder_offsets')->nullable(); // minutes before the start, e.g. [1440, 120]
            $table->unsignedBigInteger('notify_integration_id')->nullable(); // WhatsApp account to use when the Workspace has several
            $table->json('notify_messages')->nullable(); // per kind: {text?, template?: {name, language, params[]}}

            $table->foreign(['notify_integration_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('integrations')->restrictOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->string('customer_confirmation_source', 16)->nullable(); // assistant | link | staff
        });

        Schema::create('appointment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appointment_id');
            $table->string('kind', 16); // received | confirmed | reminder
            $table->unsignedInteger('offset_minutes')->default(0); // reminders: minutes before the start; 0 for the others
            $table->unsignedSmallInteger('version')->default(0); // the appointment's reschedule_count when planned
            $table->string('channel', 16)->default('whatsapp');
            $table->string('mode', 16)->nullable(); // text | template (what was actually used)
            $table->timestamp('scheduled_for');
            // scheduled | queued | sending | sent | delivered | read | failed | uncertain | skipped | cancelled
            $table->string('status', 16)->default('scheduled');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('reason', 160)->nullable(); // a FIXED code or fixed sentence of Ava (never a provider's own text, never personal data)
            $table->string('external_id', 128)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'kind', 'offset_minutes', 'version'], 'appointment_notifications_plan_unique');
            $table->unique(['workspace_id', 'external_id']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['workspace_id', 'appointment_id']);
            $table->foreign(['appointment_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('appointments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notifications');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['customer_confirmed_at', 'customer_confirmation_source']);
        });

        Schema::table('booking_settings', function (Blueprint $table) {
            $table->dropForeign(['notify_integration_id', 'workspace_id']);
            $table->dropColumn(['notify_received', 'notify_confirmed', 'reminders_enabled', 'reminder_offsets', 'notify_integration_id', 'notify_messages']);
        });
    }
};
