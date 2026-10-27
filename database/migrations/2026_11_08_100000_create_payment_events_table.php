<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* History of the events a payment provider (Wompi) sent and what was done with each. Additive: a new table, nothing existing is touched. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('environment', 12);
            $table->string('transaction_id', 80);
            $table->string('reference', 80);
            $table->string('event_status', 20)->nullable(); // what the event said
            $table->unsignedBigInteger('event_timestamp')->default(0);
            $table->string('status', 20)->nullable(); // what the provider API said when asked
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('signup_request_id')->nullable()->constrained('signup_requests')->nullOnDelete();
            // confirmed | not_approved | rejected | lookup_failed | provisioning_failed | unknown_reference | reference_mismatch
            $table->string('outcome', 30)->nullable();
            $table->timestamps();

            $table->index(['provider', 'transaction_id']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
