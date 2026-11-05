<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per distinct event of a payment provider. Wompi repeats events (and Ava asks for a retry with a 503 when it cannot verify one), and each delivery used to add another row.
 * The key is what identifies an event: provider, environment, transaction, the status the event claimed and the provider's own timestamp (the same in every redelivery). A later event
 * about the same transaction (another status or timestamp) is a different row, so the history of a transaction is kept. `WompiEvents` reuses the row of a repeated event.
 *
 * Additive and safe to apply on a table that has no duplicates (check first: group by these five columns having count(*) > 1). If there are any, this migration fails instead of deleting data:
 * resolve them by hand (keep the row with the latest outcome) and run it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->unique(['provider', 'environment', 'transaction_id', 'event_status', 'event_timestamp'], 'payment_events_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropUnique('payment_events_event_unique');
        });
    }
};
