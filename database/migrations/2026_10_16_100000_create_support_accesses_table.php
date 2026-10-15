<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Temporary, justified access of a platform administrator to the PRIVATE data of ANOTHER Workspace (see App\Privacy\SupportAccess).
 * Additive. A row is a grant: who, to which Workspace, to which kind of data, why, until when. It never stores what was read.
 * Rows are not deleted: they expire or are revoked, so the history of who was allowed to look at what stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete(); // the Workspace whose data may be read
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // who may read it
            $table->string('data_type', 24); // see config/privacy.php `support_access.types`
            $table->string('reason', 500); // mandatory justification written by the requester
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'workspace_id', 'data_type', 'expires_at'], 'support_accesses_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_accesses');
    }
};
