<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The communication channels a plan includes, independent of its modules. `plans.channels` is a JSON list of channel keys (config/chatbots.php channels plus `email`).
 * NULL means «not configured»: the plan keeps behaving as before (every channel the platform offers), so no existing plan or Workspace changes until an administrator saves
 * the list. Additive and reversible; nothing is read or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'channels')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->json('channels')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'channels')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('channels');
            });
        }
    }
};
