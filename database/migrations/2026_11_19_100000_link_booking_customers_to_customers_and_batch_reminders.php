<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Clientes as the central record of the people of a Workspace (RECOMENDACIONES.md). Additive and non destructive: three nullable columns, no data is moved, rewritten or deleted.
 *  - booking_customers.customer_id: the Clientes card a person of Agenda was tied to (when staff picked or created it from the agenda). A booking person keeps its own name, phone and
 *    email (history never changes); the link only says WHICH card it is. Composite foreign key: only a card of the SAME Workspace. Existing rows stay NULL: nothing is matched in bulk.
 *  - appointments.completed_at: the moment staff marked the appointment as done (before this column the only moment was its own start). Reminder rules use it as the real event.
 *  - reminders.batch_key: groups the individual reminders that one manual send created for several customers (one message each, never a shared one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_customers', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('workspace_id');
            $table->index(['workspace_id', 'customer_id']);
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->restrictOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->string('batch_key', 40)->nullable();
            $table->index(['workspace_id', 'batch_key']);
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'batch_key']);
            $table->dropColumn('batch_key');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });

        Schema::table('booking_customers', function (Blueprint $table) {
            $table->dropForeign(['customer_id', 'workspace_id']);
            $table->dropIndex(['workspace_id', 'customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
