<?php

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single entry point for an outbound webhook event (RECOMENDACIONES.md §78). Business code calls `publish()` at the place where the fact becomes true; the event is
 * queued only AFTER the surrounding database transaction commits (a rolled-back booking never reaches a consumer), one delivery row per active endpoint that subscribed.
 *
 * It can never break the business operation it reports: every failure is reported to the log and swallowed, and a database without the webhook tables (not migrated
 * yet) makes the call a no-op. Delivery is AT LEAST ONCE: a consumer deduplicates by `X-Ava-Event-Id` (the same on every retry and on every endpoint).
 */
class Webhooks
{
    private static bool $ready = false;

    /** Whether the migration that creates the tables ran. Only «yes» is remembered, so applying the migration is noticed without a restart. */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || (Schema::hasTable('webhook_endpoints') && Schema::hasTable('webhook_deliveries'));
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    /** @return array<string, array<string, mixed>> */
    public static function catalog(): array
    {
        return (array) config('webhooks.events', []);
    }

    /** @param  array<string, mixed>  $data  identifiers, states and dates only: never names, contact data or message text */
    public static function publish(Workspace|int $workspace, string $event, array $data): void
    {
        if (! isset(self::catalog()[$event]) || ! self::ready()) {
            return;
        }

        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        DB::afterCommit(function () use ($workspaceId, $event, $data): void {
            try {
                $endpoints = WebhookEndpoint::query()->where('workspace_id', $workspaceId)->where('is_active', true)->get()
                    ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event));

                if ($endpoints->isEmpty()) {
                    return;
                }

                $eventId = (string) Str::uuid();
                $body = self::body($workspaceId, $event, $eventId, $data);

                foreach ($endpoints as $endpoint) {
                    self::enqueue($endpoint, $event, $eventId, $body);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** The «Enviar prueba» event: only to ONE endpoint, and it is not part of the catalog a person can subscribe to. */
    public static function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $event = (string) config('webhooks.test_event');
        $eventId = (string) Str::uuid();

        return self::enqueue($endpoint, $event, $eventId, self::body($endpoint->workspace_id, $event, $eventId, ['message' => 'Prueba de conexión de Ava.', 'webhook_id' => $endpoint->id]));
    }

    /** @param  array<string, mixed>  $data */
    public static function body(int $workspaceId, string $event, string $eventId, array $data): string
    {
        $json = json_encode([
            'id' => $eventId,
            'type' => $event,
            'api_version' => config('webhooks.api_version'),
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'workspace_id' => $workspaceId,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($json) > (int) config('webhooks.limits.max_payload_bytes')) {
            throw new \LengthException('Webhook payload too large.');
        }

        return $json;
    }

    private static function enqueue(WebhookEndpoint $endpoint, string $event, string $eventId, string $body): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'public_id' => (string) Str::uuid(),
            'webhook_endpoint_id' => $endpoint->id,
            'workspace_id' => $endpoint->workspace_id,
            'event' => $event,
            'event_id' => $eventId,
            'status' => WebhookDelivery::PENDING,
            'next_attempt_at' => now(),
            'payload' => $body,
        ]);

        try {
            DeliverWebhook::dispatch($delivery->id);
        } catch (Throwable $e) {
            // The row is already there: `webhooks:sweep` picks it up, so a queue outage delays the event but never loses it.
            report($e);
        }

        return $delivery;
    }
}
