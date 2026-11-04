<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channel through which a customer gave their marketing consent (in person, WhatsApp, phone call...). The moment is already recorded by the server (`consent_at`, set whenever the
 * consent changes); this adds WHERE it came from as a structured value instead of free text. Additive, nullable, reversible; existing customers keep their note and have no channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'consent_channel')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('consent_channel', 16)->nullable()->after('consent_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'consent_channel')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('consent_channel');
            });
        }
    }
};
