<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which locations (sedes) a member of a Workspace works with. Additive and reversible; it writes NO data about existing members: every membership keeps working
 * with ALL the locations of its Workspace (the default value), exactly as before.
 *
 *  - workspace_user.location_scope: `all` (default) or `selected`.
 *  - workspace_user_locations: the locations of a `selected` member. Composite foreign keys tie each row to the same Workspace as its location and its membership,
 *    so a member can never be linked to a location of another Workspace, and removing the membership or the location removes the link. `selected` with NO rows
 *    means "no location" (never "all"): see App\Services\LocationScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->string('location_scope', 10)->default('all');
        });

        Schema::create('workspace_user_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('location_id');
            $table->timestamps();

            $table->unique(['user_id', 'location_id']);
            $table->index(['workspace_id', 'user_id']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user_locations');

        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn('location_scope');
        });
    }
};
