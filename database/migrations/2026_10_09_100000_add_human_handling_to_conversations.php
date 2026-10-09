<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Who answers a conversation: the AI (default, what every existing conversation was), or a human agent. The
        // state is explicit and persistent; `handling_version` grows with every change of control so an automatic reply
        // prepared before a change can be told apart from one prepared after it.
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('handling', 16)->default('ai')->after('contact_name'); // ai | pending | human | resolved
            $table->unsignedInteger('handling_version')->default(0)->after('handling');
            $table->foreignId('assigned_user_id')->nullable()->after('handling_version')->constrained('users')->nullOnDelete();
            $table->string('handoff_reason', 255)->nullable()->after('assigned_user_id');
            $table->timestamp('handoff_requested_at')->nullable()->after('handoff_reason');
            $table->timestamp('resolved_at')->nullable()->after('handoff_requested_at');

            $table->index(['workspace_id', 'handling', 'last_message_at']);
        });

        // Who wrote a message: the contact, the AI (the automation) or a human agent (with the user who sent it).
        Schema::table('messages', function (Blueprint $table) {
            $table->string('sender', 8)->nullable()->after('direction'); // contact | ai | agent
            $table->foreignId('sender_user_id')->nullable()->after('sender')->constrained('users')->nullOnDelete();
            $table->string('failure_reason', 255)->nullable()->after('status');
        });

        DB::table('messages')->where('direction', 'in')->update(['sender' => 'contact']);
        DB::table('messages')->where('direction', 'out')->update(['sender' => 'ai']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_user_id');
            $table->dropColumn(['sender', 'failure_reason']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'handling', 'last_message_at']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['handling', 'handling_version', 'handoff_reason', 'handoff_requested_at', 'resolved_at']);
        });
    }
};
