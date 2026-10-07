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
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['chatbot_id', 'channel', 'contact_id']);
            $table->unique(['id', 'workspace_id']);
            $table->index(['chatbot_id', 'channel', 'last_message_at']);
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->string('direction', 3); // in = from the contact, out = from the chatbot
            $table->string('type', 16)->default('text');
            $table->text('body')->nullable(); // the text, or the caption / transcription of a media message
            $table->string('media_mime', 100)->nullable();
            $table->string('external_id', 128)->nullable(); // the channel's id of the message: makes retries harmless
            $table->string('status', 16)->nullable(); // delivery state of an outgoing message
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['conversation_id', 'external_id']);
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
