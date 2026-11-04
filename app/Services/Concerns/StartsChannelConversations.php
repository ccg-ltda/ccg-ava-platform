<?php

namespace App\Services\Concerns;

use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Services\ChatbotExecutions;
use App\Services\ModuleAccess;
use App\Services\WorkspaceChannels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What every provider webhook (WhatsApp, Instagram, Messenger) does once it knows WHICH integration an event is about: find the chatbot that answers for it through the Workspace's
 * own records (never through anything the event says about a Workspace), only while everything above it is switched on, and start the chatbot's execution for a NEW message.
 */
trait StartsChannelConversations
{
    /** The chatbot of the integration's channel, only while the chatbot, its Workspace, its organization and the plan's channel modules are all active; null otherwise. */
    private function chatbotForIntegration(Integration $integration, string $channel): ?Chatbot
    {
        $row = ChatbotChannel::with('chatbot.workspace.organization')->where('integration_id', $integration->id)->where('channel', $channel)->where('is_active', true)->first();
        $chatbot = $row?->chatbot;

        return $chatbot && $chatbot->is_active && $chatbot->workspace->is_active && $chatbot->workspace->organization->is_active
            && app(ModuleAccess::class)->allowsAll($chatbot->workspace, ModuleAccess::CHANNEL_MODULES)
            && app(WorkspaceChannels::class)->allows($chatbot->workspace, $channel) ? $chatbot : null;
    }

    private function startExecution(Conversation $conversation, Message $message): void
    {
        try {
            app(ChatbotExecutions::class)->schedule($conversation->load('chatbot.workspace.organization'), $message);
        } catch (Throwable $e) {
            // The message is already stored: a problem starting the workflow must not make Meta resend the event. Only the class is logged: the exception text may carry the message itself.
            Log::error('chatbot_execution_schedule_failed', ['exception' => $e::class, 'conversation' => $conversation->id]);
        }
    }
}
