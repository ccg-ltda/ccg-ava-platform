<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * «Todos los servicios» of a sede: an explicit flag instead of a list of every service (a new service is then covered without reassigning anything, and no
 * fictitious id exists). Additive and false for every sede that exists: nothing changes for them until someone chooses it. The rows of `location_service`
 * are untouched and keep their meaning while the flag is off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('offers_all_services')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('offers_all_services');
        });
    }
};
