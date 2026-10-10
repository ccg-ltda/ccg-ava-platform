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
        // Demo data is recognised by these markers, never by a name or a text: `demo_key` is the scenario of a demo
        // conversation (null on every real one), `is_demo` marks the demo chatbot and `simulated` marks every message
        // that was written by the demo generator or the simulator (nothing of it ever reached a real provider).
        Schema::table('chatbots', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_active');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('demo_key', 40)->nullable()->after('contact_name');
            $table->index(['workspace_id', 'demo_key']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('simulated')->default(false)->after('failure_reason');
        });
    }

    /**
     *Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('simulated');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'demo_key']);
            $table->dropColumn('demo_key');
        });

        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
