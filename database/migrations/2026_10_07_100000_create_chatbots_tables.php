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
        // A chatbot of a Workspace: identity (name, description, avatar) and behavior (instructions).
        // `profile_saved_at`: when the identity was saved for the first time; until then the General tab asks for it,
        // afterwards it shows the chatbot as its customers see it.
        // The automation (n8n) that answers for a chatbot reads its configuration from Ava with a token: only the SHA-256
        // of the token is stored (it is shown once, when generated) plus its last characters so the user can tell which
        // token is in use. `agent_last_seen_at` is the last time n8n read the configuration or reported a message: the
        // only real evidence Ava has that n8n is wired to the chatbot.
        Schema::create('chatbots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('avatar_path')->nullable();
            $table->timestamp('profile_saved_at')->nullable();
            $table->string('agent_token_hash', 64)->nullable()->unique();
            $table->string('agent_token_hint', 8)->nullable();
            $table->timestamp('agent_token_created_at')->nullable();
            $table->timestamp('agent_last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->unique(['id', 'workspace_id']);
        });

        // A channel a chatbot answers through (key from config/chatbots.php). It carries no credentials: while active it
        // points at the Workspace's own integration for that channel. The composite foreign keys make it impossible
        // to link a chatbot to an integration (or a chatbot's channel to a chatbot) of another Workspace, and
        // `integration_id` is unique because one account (one WhatsApp number) can only answer through one chatbot.
        // `public_key` names an embeddable channel (the web widget) in the public script: it names the channel of ONE
        // chatbot and grants nothing but talking to it. It is not a secret and survives deactivating the channel, so a
        // script already installed keeps pointing at the same chatbot.
        Schema::create('chatbot_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chatbot_id');
            $table->string('channel', 32);
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('public_key', 40)->nullable()->unique();
            $table->timestamps();

            $table->unique(['chatbot_id', 'channel']);
            $table->unique('integration_id');
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
            $table->foreign(['integration_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('integrations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_channels');
        Schema::dropIfExists('chatbots');
    }
};
