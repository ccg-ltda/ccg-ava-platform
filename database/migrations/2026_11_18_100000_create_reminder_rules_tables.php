<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reglas de recordatorio por servicio (RECOMENDACIONES.md): a Workspace defines ONCE «30 days after a customer had service X, remind them» and Ava schedules one reminder per
 * customer by itself from the appointments that were really done. Additive (the reminders tables already exist in the development database): three NEW tables and one column.
 *  - reminder_rules: the rule (name, wait, channel, message). `activated_at` is when it started to apply: only services done from then on count, so switching a rule on never
 *    messages the whole history, and switching it off and on again does not either.
 *  - reminder_rule_services: the services a rule covers (composite foreign key: only services of its own Workspace).
 *  - reminder_rule_events: what the rule decided for each appointment (scheduled | skipped | cancelled, with a fixed reason). It is the history of the scheduling AND the guard
 *    against duplicates: one row per rule and appointment.
 *  - reminders.reminder_rule_id: the reminder a rule scheduled. Unique with the appointment, so the same rule never schedules the same service twice for the same customer
 *    even if two workers scan at the same moment (nullable: a person's own reminder has no rule, and NULLs are distinct in a unique index).
 * Nothing here touches the Agenda tables or its notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at');
            $table->unsignedSmallInteger('delay_amount');
            $table->string('delay_unit', 8); // hours | days | weeks
            $table->string('send_time', 5)->nullable(); // HH:MM in the Workspace's zone, only for days and weeks
            $table->string('channel', 12); // email | whatsapp
            $table->string('subject', 150)->nullable();
            $table->text('body');
            $table->string('template_name', 120)->nullable();
            $table->string('template_language', 12)->nullable();
            $table->json('template_params')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('reminder_rule_services', function (Blueprint $table) {
            $table->unsignedBigInteger('reminder_rule_id');
            $table->unsignedBigInteger('service_id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->primary(['reminder_rule_id', 'service_id']);
            $table->foreign(['reminder_rule_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('reminder_rules')->cascadeOnDelete();
            $table->foreign(['service_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('services')->cascadeOnDelete();
        });

        Schema::create('reminder_rule_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reminder_rule_id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('reminder_id')->nullable();
            $table->string('status', 12); // scheduled | skipped | cancelled
            $table->string('reason', 60)->nullable(); // fixed code
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['reminder_rule_id', 'appointment_id']);
            $table->index(['workspace_id', 'reminder_rule_id', 'id']);
            $table->foreign(['reminder_rule_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('reminder_rules')->cascadeOnDelete();
            $table->foreign(['appointment_id'])->references(['id'])->on('appointments')->cascadeOnDelete();
            $table->foreign(['reminder_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('reminders');
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->unsignedBigInteger('reminder_rule_id')->nullable()->after('related_id');
            $table->unique(['reminder_rule_id', 'related_type', 'related_id']);
            $table->foreign(['reminder_rule_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('reminder_rules');
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropForeign(['reminder_rule_id', 'workspace_id']);
            $table->dropUnique(['reminder_rule_id', 'related_type', 'related_id']);
            $table->dropColumn('reminder_rule_id');
        });

        Schema::dropIfExists('reminder_rule_events');
        Schema::dropIfExists('reminder_rule_services');
        Schema::dropIfExists('reminder_rules');
    }
};
