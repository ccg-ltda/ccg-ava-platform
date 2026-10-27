<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two additive changes, nothing existing is rewritten:
 *  - conversations: who closed a support session, when and why (`closed_at`, `closed_by_user_id`, `closed_reason`: client | agent | inactivity). A closed session keeps its
 *    messages and files; its `contact_id` is archived by the application so the person's next request opens a NEW conversation.
 *  - messages: when the channel confirmed delivery and reading (`delivered_at`, `read_at`), so the inbox can show the right time for each state instead of only the send time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_reason', 12)->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'read_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn(['closed_at', 'closed_reason']);
        });
    }
};
