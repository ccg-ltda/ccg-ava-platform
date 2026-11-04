<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What really went wrong when a reminder could not be sent. The attempt keeps its fixed `reason` code (what the interface translates) and now also a short `detail`: the class of the
 * error and its text with e-mail addresses masked (the same thing Catálogo keeps for its reminders). Without it a failed attempt only said «no se pudo enviar» and the real cause
 * (a transport error, a code error in the worker) was lost. Additive, nullable, reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reminder_attempts', 'detail')) {
            Schema::table('reminder_attempts', function (Blueprint $table) {
                $table->string('detail', 255)->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reminder_attempts', 'detail')) {
            Schema::table('reminder_attempts', function (Blueprint $table) {
                $table->dropColumn('detail');
            });
        }
    }
};
