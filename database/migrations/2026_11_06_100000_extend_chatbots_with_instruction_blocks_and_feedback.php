<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The instructions of an assistant in two modes (Single: one complete prompt / Modular: three independent blocks), with versions and a reviewed feedback flow.
 *
 *  - `chatbots.instructions_mode` (`single` by default): which mode is active. `chatbots.instructions` is NOT touched and NOT migrated into a block: it IS the Single prompt, so
 *    the effective text of every existing assistant is byte-for-byte the one it has today. Ava does not guess how an existing prompt splits into blocks.
 *  - `chatbots.instructions_identity | instructions_knowledge | instructions_limits`: the Modular blocks 1 (identity and communication), 2 (memory and knowledge) and
 *    3 (behavior, rules and handoff; the column name is older than its final name). They are never sent while the mode is Single, and never deleted when the mode changes.
 *  - `chatbots.instructions_version` (starts at 1), `instructions_synced_version` / `instructions_synced_at`: the version n8n has CONFIRMED it received (it read the
 *    configuration, or an execution that carried it was accepted). «Saved in Zonno» and «synchronized» are different facts.
 *  - `chatbot_instruction_versions`: a full snapshot per version (blocks + the legacy text), who changed it, which blocks changed and whether it came from a feedback.
 *    Version 1 of every existing assistant is its current text (source `migration`).
 *  - `chatbot_feedback`: a person flags a reply of the assistant and proposes a change to ONE block. Nothing changes until a person with permission approves it; an
 *    approval of a proposal made on an older version of the block is refused (`stale`).
 * All composite keys carry the Workspace, so the database itself refuses to cross Workspaces. Reversible: down() drops the tables and the columns and leaves
 * `chatbots.instructions` exactly as it is (the blocks written after this migration are lost; the versions table keeps them until down()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->text('instructions_identity')->nullable()->after('instructions');
            $table->text('instructions_limits')->nullable()->after('instructions_identity');
            $table->text('instructions_knowledge')->nullable()->after('instructions_limits');
            $table->string('instructions_mode', 8)->default('single')->after('instructions_knowledge'); // single | modular
            $table->unsignedInteger('instructions_version')->default(1)->after('instructions_mode');
            $table->unsignedInteger('instructions_synced_version')->nullable()->after('instructions_version');
            $table->timestamp('instructions_synced_at')->nullable()->after('instructions_synced_version');
        });

        Schema::create('chatbot_instruction_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('chatbot_id');
            $table->unsignedInteger('version');
            $table->text('identity')->nullable();
            $table->text('limits')->nullable();
            $table->text('knowledge')->nullable();
            $table->text('legacy')->nullable(); // the Single prompt (chatbots.instructions)
            $table->string('mode', 8)->default('single'); // the mode that was active in this version
            $table->json('changed_blocks')->nullable(); // block keys, or `mode`
            $table->string('source', 16)->default('manual'); // migration | manual | feedback
            $table->unsignedBigInteger('feedback_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['chatbot_id', 'version']);
            $table->index(['workspace_id', 'chatbot_id']);
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
        });

        Schema::create('chatbot_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('chatbot_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->text('reason');
            $table->text('desired_behavior')->nullable();
            $table->string('target_block', 12); // identity | knowledge | limits | legacy (the Single prompt); always a block of the mode that was active
            $table->text('proposed_text'); // the complete new content of that block, written or edited by a person
            $table->unsignedInteger('base_version');
            $table->string('base_hash', 40); // sha1 of the block when the proposal was made
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | stale
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('applied_version')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'chatbot_id', 'status']);
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
            $table->foreign(['conversation_id', 'chatbot_id', 'workspace_id'])->references(['id', 'chatbot_id', 'workspace_id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['message_id', 'conversation_id', 'workspace_id'])->references(['id', 'conversation_id', 'workspace_id'])->on('messages')->cascadeOnDelete();
        });

        DB::table('chatbots')->orderBy('id')->select(['id', 'workspace_id', 'instructions'])->each(function (object $chatbot) {
            DB::table('chatbot_instruction_versions')->insert([
                'workspace_id' => $chatbot->workspace_id, 'chatbot_id' => $chatbot->id, 'version' => 1, 'legacy' => $chatbot->instructions,
                'changed_blocks' => json_encode([]), 'source' => 'migration', 'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_feedback');
        Schema::dropIfExists('chatbot_instruction_versions');

        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn(['instructions_identity', 'instructions_limits', 'instructions_knowledge', 'instructions_mode', 'instructions_version', 'instructions_synced_version', 'instructions_synced_at']);
        });
    }
};
