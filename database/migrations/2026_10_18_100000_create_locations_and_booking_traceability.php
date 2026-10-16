<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sedes (locations) and the traceability of an appointment. ADDITIVE and compatible with every existing row:
 *  - `locations` is a new table; nothing points to it by force. `schedule_rules.location_id` NULL keeps meaning "every location" (all the
 *    schedules that exist today), a service with no `location_service` rows is offered in every location, and `appointments.location_id`
 *    stays NULL for every historical appointment.
 *  - An appointment keeps what it was when it was made: `location_name`, `price_amount` + `price_currency` (NULL = nothing was recorded,
 *    which is true for every historical row: the current price of a service is NEVER copied onto the past) and `origin_channel`, the
 *    channel of the conversation the booking came from (whatsapp, web...). The only backfill is that last one, and only where the
 *    appointment already points to a conversation of its own Workspace, so the origin is accredited by data that exists.
 * No cascade can erase history: locations are never deleted, and the foreign keys from appointments restrict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'name']);
        });

        // Which services a location offers. A service without rows here is offered in every location.
        Schema::create('location_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('service_id');

            $table->unique(['location_id', 'service_id']);
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->cascadeOnDelete();
            $table->foreign(['service_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('services')->cascadeOnDelete();
        });

        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('professional_id');
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('resource_id');
            $table->string('location_name', 120)->nullable()->after('professional_name');
            $table->decimal('price_amount', 10, 2)->nullable()->after('location_name');
            $table->string('price_currency', 3)->nullable()->after('price_amount');
            $table->string('origin_channel', 16)->nullable()->after('channel');

            $table->index(['workspace_id', 'location_id']);
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
        });

        DB::table('appointments')->where('channel', 'chat')->whereNotNull('conversation_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $channel = DB::table('conversations')->where('id', $row->conversation_id)->where('workspace_id', $row->workspace_id)->value('channel');

                if ($channel) {
                    DB::table('appointments')->where('id', $row->id)->update(['origin_channel' => $channel]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['location_id', 'workspace_id']);
            $table->dropIndex(['workspace_id', 'location_id']);
            $table->dropColumn(['location_id', 'location_name', 'price_amount', 'price_currency', 'origin_channel']);
        });

        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->dropForeign(['location_id', 'workspace_id']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('location_service');
        Schema::dropIfExists('locations');
    }
};
