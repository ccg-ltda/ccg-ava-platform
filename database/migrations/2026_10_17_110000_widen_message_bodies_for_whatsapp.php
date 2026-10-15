<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The body of a WhatsApp template may have 1024 characters (config/campaigns.php `body_max`); the columns were created for 1000, so on
 * PostgreSQL a valid body of 1001-1024 characters would be refused by the database. Widening a varchar never rewrites or loses a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', fn (Blueprint $table) => $table->string('body', 1024)->change());
        Schema::table('campaigns', fn (Blueprint $table) => $table->string('body', 1024)->change());
    }

    public function down(): void
    {
        // Narrowing could cut a saved message: it is deliberately not reversed.
    }
};
