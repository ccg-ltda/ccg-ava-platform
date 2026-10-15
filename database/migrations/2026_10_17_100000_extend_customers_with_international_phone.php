<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. `phone` (what was typed) and `phone_normalized` (digits, the historical identity) are NOT touched: every existing customer
 * keeps both exactly as they are. `phone_e164` is a separate value that exists ONLY when the original phone is a verified international
 * number ("+573001112233"); it is NULL for every historical row until a person runs the controlled review (`customers:review-phones`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_e164', 20)->nullable()->after('phone_normalized');
            // A verified international number identifies ONE customer per Workspace (null never collides).
            $table->unique(['workspace_id', 'phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'phone_e164']);
            $table->dropColumn('phone_e164');
        });
    }
};
