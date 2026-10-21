<?php

namespace App\Services;

use App\Integrations\SafeHttpTarget;
use App\Integrations\UnsafeTarget;
use App\Integrations\WebType;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\ChatbotExecution;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The server side of the public web widget. A public key names ONE chatbot channel; everything else (the chatbot, its
 * Workspace, the n8n webhook, the allowed sites) is looked up from it, never taken from the visitor. Whatever is
 * switched off along the way (the channel, the chatbot, its integration, the Workspace or its Organization) makes the
 * key not exist, so a stopped widget gives away nothing.
 */
class WebWidget
{
    public function __construct(
        private readonly SafeHttpTarget $target,
        private readonly ChannelAppearanceService $look,
        private readonly ConversationService $conversations,
        private readonly ConversationControl $control,
        private readonly ModuleAccess $modules,
        private readonly ChatbotExecutions $executions,
        private readonly SupportChannel $support,
    ) {}

    public function resolve(string $publicKey): ?ChatbotChannel
    {
        $channel = ChatbotChannel::with(['chatbot.workspace.organization', 'integration'])
            ->where('public_key', $publicKey)->whereIn('channel', array_keys(array_filter(config('chatbots.channels'), fn (array $channel) => $channel['embeddable'] ?? false)))
            ->where('is_active', true)->first();

        return $channel
            && $channel->chatbot->is_active
            && $channel->chatbot->workspace->is_active
            && $channel->chatbot->workspace->organization->is_active
            && $this->modules->allowsAll($channel->chatbot->workspace, ModuleAccess::CHANNEL_MODULES)
            && $channel->integration?->is_active
            && $this->look->destination($channel->chatbot->workspace, $channel->channel)['ready']
            && $this->look->enabled($channel->chatbot, $channel->channel)
                ? $channel
                : null;
    }

    /** Whether the page that embeds the widget (the request's Origin) may use it. No list = any site. */
    public function originAllowed(ChatbotChannel $channel, ?string $origin): bool
    {
        $allowed = $channel->integration->config['allowed_origins'] ?? [];

        return $allowed === [] || ($origin !== null && in_array(strtolower(rtrim($origin, '/')), $allowed, true));
    }

    /**
     * What the script needs to draw itself: the chatbot's identity, the client's visual settings for this channel and the
     * Workspace's light / dark mode. Only presentation: the destination of a WhatsApp button is its public link.
     */
    public function appearance(ChatbotChannel $channel, string $publicKey): array
    {
        $chatbot = $channel->chatbot;
        $settings = $chatbot->workspace->settingsOrDefault();
        $style = $this->look->publicStyle($chatbot, $channel->channel);

        return [
            'type' => $this->look->kind($channel->channel),
            'name' => $chatbot->name,
            'description' => $chatbot->description,
            'avatarUrl' => $chatbot->avatar_path ? url("/api/widget/{$publicKey}/avatar").'?v='.$chatbot->updated_at?->timestamp : null,
            'primaryColor' => $style['primaryColor'],
            'appearance' => $settings->appearance,
            'style' => $style,
            'href' => $channel->channel === 'whatsapp' ? $this->look->whatsappLink($chatbot) : null,
        ];
    }

    /**
     * Records the visitor's message and, while the AI has the conversation, sends it to the n8n webhook of the Workspace
     * and returns the reply it gives back. While a person has the conversation (or asked for one) n8n is not called and
     * there is no reply: the agent answers through `collect`. A reply prepared before control changed is dropped.
     *
     * @return array{status: int, reply?: ?string, handling?: string, pending?: bool, message?: string}
     */
    public function relay(ChatbotChannel $channel, string $sessionId, string $message, ?string $messageId = null): array
    {
        $chatbot = $channel->chatbot;
        $recorded = $this->conversations->record($chatbot, 'web', [
            'contact_id' => $sessionId, 'direction' => 'in', 'type' => 'text', 'body' => $message, 'sent_at' => now(),
            // The widget's own id of this message: a retry of the same request (a dropped connection, a double tap) is stored and answered once.
            'external_id' => $messageId !== null ? 'w:'.$messageId : null,
        ]);
        $conversation = $recorded['conversation'];

        if (! $recorded['created']) {
            return ['status' => 200, 'reply' => null, 'handling' => $conversation->handling, 'duplicate' => true];
        }

        // CCG's support chat: the team hears of a conversation once, when its first message arrives.
        if ($conversation->messages()->count() === 1) {
            $this->support->notify('new_conversation', $conversation->load('chatbot.workspace'));
        }

        if (! $conversation->aiMayReply()) {
            return ['status' => 200, 'reply' => null, 'handling' => $conversation->handling];
        }

        // The chatbot has an authorized platform workflow: the same path as WhatsApp (an execution Ava controls, with retries, memory and
        // diagnostics). The answer is not in this response: the widget collects it (`collect`) when the execution delivers it.
        if ($this->executions->available($chatbot)) {
            $this->executions->schedule($conversation->load('chatbot.workspace.organization'), $recorded['message']);

            return ['status' => 200, 'reply' => null, 'handling' => $conversation->handling, 'pending' => true];
        }

        $answer = $this->askAutomation($channel, $sessionId, $message);

        if (isset($answer['status'])) {
            // CCG's support chat never leaves a visitor facing an error: the message is already stored, so a person is asked for and the
            // visitor is told one will answer. Any other Workspace keeps the plain failure for its own widget.
            if ($this->support->owns($conversation->load('chatbot.workspace'))) {
                $this->control->requestHuman($conversation, 'El asistente no respondió.');

                return ['status' => 200, 'reply' => null, 'handling' => Conversation::PENDING];
            }

            return $answer;
        }

        $reply = mb_substr($answer['reply'], 0, (int) config('chatbots.widget.max_reply'));
        $stored = $this->storeReply($chatbot, $conversation, $reply);

        if ($stored && $answer['handoff']) {
            $this->control->requestHuman($conversation, 'Solicitado por el asistente.');
        }

        return ['status' => 200, 'reply' => $stored ? $reply : null, 'handling' => $conversation->fresh()->handling];
    }

    /**
     * What the visitor has not received yet: the messages a human agent wrote (each is marked delivered once handed over)
     * and who is answering now. The session id is the visitor's only key, so only that conversation is read.
     *
     * @return array{messages: list<array{id: int, text: string, sender: string}>, handling: string, answering: bool}
     */
    public function collect(ChatbotChannel $channel, string $sessionId): array
    {
        $conversation = $channel->chatbot->conversations()->where('channel', 'web')->where('contact_id', $sessionId)->first();

        if (! $conversation) {
            return ['messages' => [], 'handling' => Conversation::AI, 'answering' => false];
        }

        $messages = DB::transaction(function () use ($conversation) {
            // What a person wrote, and what the platform workflow answered (the legacy path answers in the response and is stored delivered).
            $waiting = $conversation->messages()->where('direction', 'out')->whereIn('sender', ['agent', 'ai'])->where('status', 'sent')->orderBy('id')->get();
            $conversation->messages()->whereIn('id', $waiting->pluck('id'))->update(['status' => 'delivered']);

            return $waiting;
        });

        return [
            'messages' => $messages->map(fn (Message $message) => ['id' => $message->id, 'text' => (string) $message->body, 'sender' => (string) $message->sender])->all(),
            'handling' => $conversation->handling,
            // Whether the platform workflow is still working on (or handing over) an answer for this visitor: the widget keeps waiting only
            // while this is true, so a failed or discarded execution does not leave it "typing" for a minute and a half.
            'answering' => ChatbotExecution::where('conversation_id', $conversation->id)
                ->where(fn ($q) => $q->whereIn('status', [ChatbotExecution::PENDING, ChatbotExecution::RUNNING])->orWhere(fn ($q) => $q->where('status', ChatbotExecution::SUCCEEDED)->where('delivery', ChatbotExecution::DELIVERY_PENDING)))
                ->exists(),
        ];
    }

    /**
     * Calls the n8n webhook. Returns `['reply' => string, 'handoff' => bool]`, or the failure to give the visitor.
     *
     * @return array{reply: string, handoff: bool}|array{status: int, message: string}
     */
    private function askAutomation(ChatbotChannel $channel, string $sessionId, string $message): array
    {
        $integration = $channel->integration;
        $url = $integration->config['webhook_url'];
        $timeout = (int) config('chatbots.widget.timeout');
        $headers = ['Accept' => 'application/json'];

        if (filled($secret = $integration->secrets['webhook_secret'] ?? null)) {
            $headers[WebType::SECRET_HEADER] = $secret;
        }

        try {
            $response = $this->target->client($this->target->resolve($url), $timeout, $headers)->asJson()->post($url, [
                'event' => 'message',
                'channel' => 'web',
                'chatbot_id' => $channel->chatbot_id,
                'session_id' => $sessionId,
                'message' => $message,
            ]);
        } catch (UnsafeTarget|ConnectionException) {
            return ['status' => 504, 'message' => 'El asistente no está disponible en este momento.'];
        } catch (Throwable) {
            return ['status' => 502, 'message' => 'El asistente no pudo responder.'];
        }

        $reply = $response->json('reply');

        if (! $response->successful() || ! is_string($reply) || trim($reply) === '') {
            return ['status' => 502, 'message' => 'El asistente no pudo responder.'];
        }

        return ['reply' => $reply, 'handoff' => $response->json('handoff') === true];
    }

    /** Stores the AI's reply unless control changed since the message was received; false when it was dropped. */
    private function storeReply(Chatbot $chatbot, Conversation $seen, string $reply): bool
    {
        return DB::transaction(function () use ($chatbot, $seen, $reply) {
            $current = Conversation::whereKey($seen->id)->lockForUpdate()->first();

            if (! $current->aiMayReply() || $current->handling_version !== $seen->handling_version) {
                return false;
            }

            $this->conversations->record($chatbot, 'web', [
                'contact_id' => $current->contact_id, 'direction' => 'out', 'type' => 'text', 'body' => $reply, 'status' => 'delivered', 'sent_at' => now(),
            ]);

            return true;
        });
    }
}
