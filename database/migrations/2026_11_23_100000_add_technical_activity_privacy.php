<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy controls of the technical activity (`agent_tool_calls`):
 *  - `workspace_settings.activity_store_bodies`: whether the request and response BODIES (and headers / query) of the calls of this Workspace are stored. Default true (the
 *    technical panel is useful); a Workspace with sensitive data turns it off and keeps only the metadata (tool, method, endpoint, status, duration).
 *  - `agent_tool_calls.bodies_purged_at`: when the retention job removed the bodies of a call whose metadata is kept longer; the screen says so instead of showing nothing.
 * Additive and nullable / defaulted: existing rows and code keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->boolean('activity_store_bodies')->default(true);
        });

        Schema::table('agent_tool_calls', function (Blueprint $table) {
            $table->timestamp('bodies_purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool_calls', function (Blueprint $table) {
            $table->dropColumn('bodies_purged_at');
        });

        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn('activity_store_bodies');
        });
    }
};
