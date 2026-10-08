<?php

namespace App\Services;

use App\Integrations\SafeHttpTarget;
use App\Integrations\UnsafeTarget;
use App\Integrations\WebType;
use App\Models\ChatbotChannel;
use Illuminate\Http\Client\ConnectionException;
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
     * Sends the visitor's message to the n8n webhook of the Workspace and returns the reply it gives back.
     *
     * @return array{status: int, reply?: string, message?: string}
     */
    public function relay(ChatbotChannel $channel, string $sessionId, string $message): array
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

        return ['status' => 200, 'reply' => mb_substr($reply, 0, (int) config('chatbots.widget.max_reply'))];
    }
}
