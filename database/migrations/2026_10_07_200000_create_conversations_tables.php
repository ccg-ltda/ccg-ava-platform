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
        // What the automation reports about a channel's conversations (today WhatsApp). Ava does not talk to the
        // channel: n8n does, and tells Ava each message so the Workspace can read the history. A conversation is one
        // contact of one chatbot on one channel; every row carries its Workspace and the composite foreign keys make
        // it impossible to attach a conversation to a chatbot, or a message to a conversation, of another Workspace.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chatbot_id');
            $table->string('channel', 32);
            $table->string('contact_id', 64);
            $table->string('contact_name', 100)->nullable();
            // Demo data is recognised by this marker, never by a name or a text: the scenario of a demo conversation (null on every real one).
            $table->string('demo_key', 40)->nullable();
            // Who answers: the AI (default), or a human agent. The state is explicit and persistent; `handling_version` grows
            // with every change of control so an automatic reply prepared before a change can be told apart from one after it.
            $table->string('handling', 16)->default('ai'); // ai | pending | human | resolved
            $table->unsignedInteger('handling_version')->default(0);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handoff_reason', 255)->nullable();
            $table->timestamp('handoff_requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['chatbot_id', 'channel', 'contact_id']);
            $table->unique(['id', 'workspace_id']);
            // Target of the composite foreign keys of a chatbot execution (see add_chatbot_executions).
            $table->unique(['id', 'chatbot_id', 'workspace_id']);
            $table->index(['chatbot_id', 'channel', 'last_message_at']);
            $table->index(['workspace_id', 'demo_key']);
            $table->index(['workspace_id', 'handling', 'last_message_at']);
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->string('direction', 3); // in = from the contact, out = from the chatbot
            $table->string('sender', 8)->nullable(); // contact | ai | agent
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16)->default('text');
            $table->text('body')->nullable(); // the text, or the caption / transcription of a media message
            $table->string('media_mime', 100)->nullable();
            $table->string('external_id', 128)->nullable(); // the channel's id of the message: makes retries harmless
            $table->string('status', 16)->nullable(); // delivery state of an outgoing message
            $table->string('failure_reason', 255)->nullable();
            $table->boolean('simulated')->default(false); // written by the demo generator or simulator: never reached a provider
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'external_id']);
            // Target of the composite foreign keys of a chatbot execution (see add_chatbot_executions).
            $table->unique(['id', 'conversation_id', 'workspace_id']);
            $table->index(['conversation_id', 'sent_at']);
            $table->foreign(['conversation_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('conversations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
