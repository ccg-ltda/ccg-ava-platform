<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Two facts the log could not say until now. `actor`: who did it, a person (`user`, the only kind there was) or an
        // automatic process of Ava (`system`: an assistant asking for a person, a failed execution, the platform console);
        // an automatic event has no `user_id` and names its source in `user_name`. `outcome`: whether the operation
        // `succeeded` (every existing row) or `failed` (a connection test that did not pass, an execution that failed).
        // Additive with defaults, so every existing row keeps its meaning. It is an `add_*` migration (not an edit of the
        // initial `create_audit_logs_table`) because the development database already holds audit history.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor', 8)->default('user');
            $table->string('outcome', 8)->default('success');

            $table->index(['workspace_id', 'actor']);
            $table->index(['workspace_id', 'outcome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'actor']);
            $table->dropIndex(['workspace_id', 'outcome']);
            $table->dropColumn(['actor', 'outcome']);
        });
    }
};
