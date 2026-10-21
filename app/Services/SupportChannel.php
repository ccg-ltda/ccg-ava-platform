<?php

namespace App\Services;

use App\Jobs\SendSupportNotification;
use App\Models\ChatbotChannel;
use App\Models\Conversation;

/**
 * CCG's own public support chat: which web channel it is and whether it is safe to offer. The destination is never taken from a visitor: the
 * only input is the configured public key (config/support.php), and it counts only while the channel it names is a live WEB channel of a chatbot
 * of the ADMINISTRATIVE Workspace (identified by its stable code, never by an id or a name). A key that names a channel of any other Workspace is
 * refused and reported, so a typo can never send CCG's visitors to a client. The visitor needs no account and no membership: the Workspace is only
 * where the conversations land, and who may read them is decided by the roles of that Workspace (Conversaciones).
 */
class SupportChannel
{
    public function __construct(private readonly OperationalAlert $alerts) {}

    /** The configured public key, or null when none is set. */
    public function configuredKey(): ?string
    {
        $key = config('support.public_chat_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Why the configured key cannot be used, or null when it can (or when none is configured, which is not an error).
     * A fixed code, never data of the channel.
     */
    public function problem(): ?string
    {
        if (($key = $this->configuredKey()) === null) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            return 'invalid_key';
        }

        $channel = ChatbotChannel::with('chatbot.workspace')->where('public_key', $key)->first();

        if (! $channel || $channel->channel !== 'web') {
            return 'unknown_channel';
        }

        if (! $channel->chatbot->workspace->isAdministrative()) {
            return 'not_administrative_workspace';
        }

        // Resolved lazily: WebWidget itself needs the conversation services that notify through this class.
        return app(WebWidget::class)->resolve($key) === null ? 'channel_not_live' : null;
    }

    /** The key to put in the public pages: only when it is configured AND valid (otherwise nothing is offered and the problem is reported). */
    public function publicKey(): ?string
    {
        $key = $this->configuredKey();

        if ($key === null) {
            return null;
        }

        if ($problem = $this->problem()) {
            $this->alerts->raise('support_chat_misconfigured', ['problem' => $problem]);

            return null;
        }

        return $key;
    }

    /** Whether the conversation arrived through a web channel of the administrative Workspace (CCG's support). */
    public function owns(Conversation $conversation): bool
    {
        return $conversation->channel === 'web' && $conversation->chatbot->workspace->isAdministrative();
    }

    /**
     * Alerts CCG's team (queued, from the server) when a conversation of the support chat arrives or asks for a person. Does nothing for any other
     * Workspace, and nothing leaves Ava until `config/support.php` has the webhook of the platform.
     */
    public function notify(string $event, Conversation $conversation): void
    {
        if (! $this->owns($conversation) || ! filled(config('support.notify_webhook_url'))) {
            return;
        }

        SendSupportNotification::dispatch($event, $conversation->id, (int) $conversation->handling_version)->afterCommit();
    }
}
