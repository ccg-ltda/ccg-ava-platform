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
        // How the button / widget of ONE channel of ONE chatbot looks (colors, size, position, texts...). It is kept apart
        // from `chatbot_channels` on purpose: that table says whether the channel works and which account it uses (and can
        // only exist once the channel was switched on), while this one is only presentation and can be prepared before.
        // `settings` holds just the keys the channel's appearance schema allows (App\Services\ChannelAppearanceService);
        // a missing key falls back to its default. No credentials ever live here. The composite foreign key makes it
        // impossible to attach an appearance to a chatbot of another Workspace.
        Schema::create('chatbot_channel_appearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chatbot_id');
            $table->string('channel', 32);
            $table->json('settings');
            $table->timestamps();

            $table->unique(['chatbot_id', 'channel']);
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_channel_appearances');
    }
};
