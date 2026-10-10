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
        // Which workflow of the platform's catalog (config/n8n.php) answers for a chatbot. The ONLY source of truth of the
        // assignment: it is a column of the chatbot itself, so it can only ever belong to that chatbot's own Workspace, a
        // chatbot has at most one, and it disappears with the chatbot. The key is checked against the catalog when it is
        // written (and again when it is resolved), because a catalog that lives in code cannot be a foreign key. Not
        // mass assignable: only App\Services\ChatbotWorkflows sets it.
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('workflow_key', 64)->nullable();
        });

        // Targets of the composite foreign keys of an execution: they make the database itself refuse an execution whose
        // message is not in its conversation, or whose conversation is not of its chatbot, or any of them of another Workspace.
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['id', 'chatbot_id', 'workspace_id']);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->unique(['id', 'conversation_id', 'workspace_id']);
        });

        // One run of a chatbot's workflow for ONE inbound message. `message_id` is unique, so a repeated event can never
        // create a second one, and every state change of an execution is a conditional update on this row. It holds no
        // message text and no secret: only identifiers, the workflow that ran, fixed codes and times. The AI's reply is an
        // ordinary outgoing message (`reply_message_id`, unique: one reply per execution) whose own delivery state
        // stays in `messages.status`; `delivery` is the state of handing THAT reply to the channel (see ChatbotExecutions).
        Schema::create('chatbot_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chatbot_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id')->unique();
            // What ran, copied when the execution was queued so it can be audited even if the catalog changes later.
            $table->string('workflow_key', 64);
            $table->string('n8n_workflow_id', 64);
            $table->string('workflow_version', 32)->nullable();
            // The conversation's control version when it was queued: a different one at any later step means control changed.
            $table->unsignedInteger('handling_version');
            $table->string('status', 16)->default('pending'); // pending, running, succeeded, discarded, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('outcome_code', 32)->nullable();
            // What happened with control: replied, handoff_applied, handoff_skipped, late_dropped, superseded.
            $table->string('control_result', 24)->nullable();
            $table->unsignedBigInteger('reply_message_id')->nullable()->unique();
            // null = no reply; pending = stored, not handed over; sending = handed over, outcome not recorded yet;
            // accepted = the channel accepted it; failed = refused for sure; uncertain = may or may not have gone out.
            $table->string('delivery', 16)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['delivery', 'updated_at']);
            $table->foreign(['chatbot_id', 'workspace_id'], 'ce_chatbot_fk')->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
            $table->foreign(['conversation_id', 'chatbot_id', 'workspace_id'], 'ce_conversation_fk')->references(['id', 'chatbot_id', 'workspace_id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['message_id', 'conversation_id', 'workspace_id'], 'ce_message_fk')->references(['id', 'conversation_id', 'workspace_id'])->on('messages')->cascadeOnDelete();
            $table->foreign(['reply_message_id', 'conversation_id', 'workspace_id'], 'ce_reply_fk')->references(['id', 'conversation_id', 'workspace_id'])->on('messages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_executions');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['id', 'conversation_id', 'workspace_id']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['id', 'chatbot_id', 'workspace_id']);
        });
        Schema::table('chatbots', function (Blueprint $table) {
            $table->dropColumn('workflow_key');
        });
    }
};
