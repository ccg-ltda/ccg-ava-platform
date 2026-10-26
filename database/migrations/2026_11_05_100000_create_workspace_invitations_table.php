<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to a Workspace (a customer contracted outside the public sign-up, or any new member the platform administration brings in).
 *
 *  - `workspace_invitations`: nothing is created for the person until they accept. `token_hash` is the SHA-256 of a 64-character random token that travels only
 *    in the e-mailed link (never stored, never shown); it is unique, expires at `expires_at`, works once (`accepted_at`) and dies when `revoked_at` is set. The
 *    invitation names ONE Workspace and ONE role, so the link can not be used to enter another Workspace or to get another role. `invited_by` is the person who
 *    issued it; `accepted_user_id` the account that was created by accepting it.
 *
 * Additive: no existing table or row changes. down() drops the table (pending invitations stop working; accounts already created are not touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('name', 255)->nullable();
            $table->string('role', 255);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
