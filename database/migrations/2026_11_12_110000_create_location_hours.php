<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The opening hours of a sede, by day of the week (1 = Monday … 7 = Sunday, like `schedule_rules`): ONE interval of attention per open day; a day with no row is CLOSED for
 * a sede that has rows. A sede with no rows at all has no hours configured: nothing is assumed for it (the agenda keeps its working-day scale and the server asks only for the
 * professional's own hours, exactly as before this table existed). Additive; existing sedes and appointments are untouched and receive no invented hours. The composite foreign key
 * makes the database itself refuse hours that point to a sede of another Workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->unsignedTinyInteger('weekday'); // 1-7
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->unique(['location_id', 'weekday']);
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_hours');
    }
};
