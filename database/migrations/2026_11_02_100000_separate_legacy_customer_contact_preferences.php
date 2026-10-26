<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `customers.preferred_channel` now means HOW THE CUSTOMER WANTS TO BE CONTACTED (whatsapp, sms, call, email), no longer the channel a conversation came from.
 * Rows that still hold one of the old conversation channels (web, instagram, messenger) are not a preference.
 *
 *  - Adds `legacy_preferred_channel`: where the original value is kept. Nothing reads it; it exists so the change is reversible and auditable.
 *  - up(): copies web / instagram / messenger into `legacy_preferred_channel` and sets `preferred_channel` to NULL ("Sin preferencia") ONLY on those rows.
 *    `whatsapp` and `email` are valid in both meanings and are not touched. No other column or table changes.
 *  - down(): puts the original value back on the rows that still have no preference and drops the column. A customer whose preference was chosen after up() keeps it.
 *
 * Not applied automatically: the application already shows such a value as «Sin preferencia (valor anterior: …)» without it.
 */
return new class extends Migration
{
    private const LEGACY = ['web', 'instagram', 'messenger'];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('legacy_preferred_channel', 16)->nullable()->after('preferred_channel');
        });

        DB::table('customers')->whereIn('preferred_channel', self::LEGACY)->update([
            'legacy_preferred_channel' => DB::raw('preferred_channel'),
            'preferred_channel' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('customers')->whereNotNull('legacy_preferred_channel')->whereNull('preferred_channel')->update([
            'preferred_channel' => DB::raw('legacy_preferred_channel'),
        ]);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('legacy_preferred_channel');
        });
    }
};
