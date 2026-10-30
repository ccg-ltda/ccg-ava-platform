<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lifecycle of the access tokens automations (n8n) use to read a chatbot (S8 of RECOMENDACIONES.md §68, decision of §75).
 * Additive: a NEW table; `chatbots.agent_token_hash / hint / created_at` stay as a mirror of the CURRENT token (the interface and older code read them), so nothing
 * existing changes shape. Only the SHA-256 of a token is stored, never the token. Several tokens of one chatbot can be valid at once: that overlap is what lets a
 * token be replaced without interrupting n8n (the old one is retired only after the new one has been used).
 *
 * Existing tokens are adopted (`adoptLegacy`) with an explicit rule, not a fictitious date: `expires_at` = the later of (created + 90 days) and (now + 30 days), so
 * a token already older than 90 days is not cut off by the deployment but has a 30-day window, announced by notifications, to be rotated. No token is revoked here.
 *
 * `down()` drops the ledger (its history and the expiry dates are lost); the mirror columns keep the current token working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chatbot_id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Composite key: a token can only belong to a chatbot of ITS OWN Workspace (chatbots has unique(id, workspace_id)), like the other tables of this project.
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('hint', 8)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('superseded_at')->nullable(); // a replacement was issued: valid until the replacement is used (or it expires / is revoked)
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 20)->nullable(); // revoked | replaced | replaced_unused | rotated
            $table->boolean('legacy')->default(false);

            $table->index(['chatbot_id', 'revoked_at']);
            $table->index(['workspace_id', 'expires_at']);
        });

        $this->adoptLegacy();
    }

    /** Gives every chatbot that already has a token a ledger row (idempotent: a token already in the ledger is skipped). */
    public function adoptLegacy(): int
    {
        $adopted = 0;
        $days = (int) config('chatbots.agent_token.days', 90);
        $grace = (int) config('chatbots.agent_token.legacy_grace_days', 30);

        foreach (DB::table('chatbots')->whereNotNull('agent_token_hash')->get(['id', 'workspace_id', 'agent_token_hash', 'agent_token_hint', 'agent_token_created_at', 'agent_last_seen_at']) as $chatbot) {
            if (DB::table('agent_tokens')->where('token_hash', $chatbot->agent_token_hash)->exists()) {
                continue;
            }

            $created = $chatbot->agent_token_created_at ? Carbon::parse($chatbot->agent_token_created_at) : now();
            $expires = $created->copy()->addDays($days)->max(now()->addDays($grace));

            DB::table('agent_tokens')->insert([
                'chatbot_id' => $chatbot->id, 'workspace_id' => $chatbot->workspace_id, 'token_hash' => $chatbot->agent_token_hash, 'hint' => $chatbot->agent_token_hint,
                'created_at' => $created, 'expires_at' => $expires, 'first_used_at' => $chatbot->agent_last_seen_at, 'last_used_at' => $chatbot->agent_last_seen_at, 'legacy' => true,
            ]);
            $adopted++;
        }

        return $adopted;
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tokens');
    }
};
