<?php

namespace App\Jobs;

use App\Integrations\SafeHttpTarget;
use App\Integrations\UnsafeTarget;
use App\Integrations\WebType;
use App\Observability\ApiCallRecorder;
use App\Services\OperationalAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * Tells CCG's team (an n8n webhook of the platform, `config/support.php`) that a support conversation arrived or asked for a person. Called by the
 * server only; the payload holds identifiers and the link to the conversation, never message text, contact data or credentials. Each event has a
 * stable id (`X-Ava-Event-Id`) so the receiving flow can ignore a repeat, and a failure is retried and finally reported (OperationalAlert): the
 * conversation itself is already in the inbox, so a lost alert never loses the visitor's message.
 */
class SendSupportNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $event, public readonly int $conversationId, public readonly int $handlingVersion) {}

    /** Stable for the same event of the same conversation state: repeating the dispatch repeats the id, not the alert. */
    public function eventId(): string
    {
        return "support:{$this->event}:{$this->conversationId}:{$this->handlingVersion}";
    }

    public function handle(SafeHttpTarget $target): void
    {
        $url = config('support.notify_webhook_url');

        if (! is_string($url) || trim($url) === '') {
            return; // not configured: nothing leaves Ava (the diagnosis says so)
        }

        $headers = ['Accept' => 'application/json', 'X-Ava-Event-Id' => $this->eventId()];

        if (filled($secret = config('support.notify_secret'))) {
            $headers[WebType::SECRET_HEADER] = $secret;
        }

        try {
            $pinned = $target->resolve($url);
            // A platform call (no Workspace): metadata only, no headers (the secret) and no body.
            $response = app(ApiCallRecorder::class)->call('support_notify', 'notify', 'POST', strtok($url, '?') ?: $url, fn () => $target->client($pinned, (int) config('support.notify_timeout'), $headers)->asJson()->post($url, [
                'event' => $this->event,
                'event_id' => $this->eventId(),
                'conversation_id' => $this->conversationId,
                'url' => route('conversations.index', ['conversation' => $this->conversationId]),
                'at' => now()->toIso8601String(),
            ]), [], null, ['resource_type' => 'conversation', 'resource_id' => $this->conversationId], false);
        } catch (UnsafeTarget|ConnectionException $e) {
            throw new RuntimeException('support_notification_unreachable');
        }

        if (! $response->successful()) {
            throw new RuntimeException('support_notification_rejected_'.$response->status());
        }
    }

    public function failed(Throwable $e): void
    {
        app(OperationalAlert::class)->raise('support_notification_failed', ['event' => $this->event, 'conversation_id' => $this->conversationId, 'reason' => $e->getMessage()]);
    }
}
