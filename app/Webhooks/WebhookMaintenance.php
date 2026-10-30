<?php

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Throwable;

/**
 * The safety net of the queue (RECOMENDACIONES.md §78): a job lost with the queue (a Redis flush, a worker killed mid-attempt) never loses an event. `sweep()` re-dispatches
 * every delivery that is due and frees the ones stuck in `sending`; the claim in WebhookSender makes a duplicated job harmless. `prune()` keeps the history bounded.
 */
class WebhookMaintenance
{
    private const BATCH = 200;

    /** @return array{redispatched: int, released: int} */
    public function sweep(): array
    {
        if (! Webhooks::ready()) {
            return ['redispatched' => 0, 'released' => 0];
        }

        // An attempt that never reported back (the worker died): it counts as failed and goes through the normal retry schedule.
        $stale = WebhookDelivery::query()->where('status', WebhookDelivery::SENDING)
            ->where('last_attempt_at', '<', now()->subMinutes((int) config('webhooks.limits.stale_sending_minutes')))->pluck('id');
        $released = WebhookDelivery::query()->whereIn('id', $stale)->where('status', WebhookDelivery::SENDING)
            ->update(['status' => WebhookDelivery::FAILED, 'error' => 'attempt_lost', 'next_attempt_at' => now()]);

        $redispatched = 0;

        // Due for more than 30 s (a job queued a moment ago still has its chance), plus the attempts just released.
        WebhookDelivery::query()->whereIn('status', [WebhookDelivery::PENDING, WebhookDelivery::FAILED])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()->subSeconds(30))->orWhereIn('id', $stale))
            ->orderBy('id')->limit(self::BATCH)->pluck('id')->each(function (int $id) use (&$redispatched) {
                try {
                    DeliverWebhook::dispatch($id);
                    $redispatched++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return ['redispatched' => $redispatched, 'released' => $released];
    }

    /** Removes FINISHED deliveries (delivered or abandoned) older than the retention window. A pending, sending or failed one is never pruned: the sweeper owns it until it finishes. */
    public function prune(): int
    {
        if (! Webhooks::ready()) {
            return 0;
        }

        $days = (int) config('webhooks.limits.retention_days');

        return WebhookDelivery::query()->whereIn('status', [WebhookDelivery::DELIVERED, WebhookDelivery::ABANDONED])->where('created_at', '<', now()->subDays($days))->delete();
    }
}
