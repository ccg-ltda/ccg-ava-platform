<?php

namespace App\Webhooks;

use App\Integrations\SafeHttpTarget;
use App\Integrations\UnsafeTarget;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Notifications;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes ONE delivery attempt and records what happened (RECOMENDACIONES.md §78). The attempt is claimed with a single conditional UPDATE, so two workers can never send
 * the same attempt. The destination is validated again right now (a hostname that was public when saved may resolve to an internal address today), the connection is pinned to
 * the validated address, redirects are never followed, and the request carries only headers Ava chooses. Nothing the destination answers is stored except the status code.
 *
 * Retries follow `webhooks.limits.backoff_seconds`; after the last one the delivery is `abandoned` and counts as a failure of the endpoint, which is switched off (and the team
 * notified) after `disable_after_failures` abandoned deliveries in a row. Any 2xx resets the count.
 */
class WebhookSender
{
    public function __construct(private readonly SafeHttpTarget $target, private readonly WebhookSigner $signer) {}

    /** The destination as it is judged both when it is saved and before each attempt: HTTPS (HTTP only where private hosts are allowed on purpose, e.g. local development), public addresses. */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeTarget('La URL no es válida.');
        }

        if (strtolower($parts['scheme']) !== 'https' && ! config('integrations.allow_private_hosts')) {
            throw new UnsafeTarget('El webhook debe usar una dirección https.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (! config('integrations.allow_private_hosts') && ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local'))) {
            throw new UnsafeTarget('Por seguridad no se permite conectar con direcciones internas o reservadas.');
        }

        if (isset($parts['fragment'])) {
            throw new UnsafeTarget('La URL no puede incluir un fragmento (#).');
        }

        return $this->target->resolve($url);
    }

    public function attempt(int $deliveryId): void
    {
        $now = now();

        $claimed = WebhookDelivery::query()->whereKey($deliveryId)
            ->whereIn('status', [WebhookDelivery::PENDING, WebhookDelivery::FAILED])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->update(['status' => WebhookDelivery::SENDING, 'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => $now, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = WebhookDelivery::query()->with('endpoint')->find($deliveryId);
        $endpoint = $delivery?->endpoint;

        if ($endpoint === null || ! $endpoint->is_active) {
            $delivery?->forceFill(['status' => WebhookDelivery::ABANDONED, 'error' => 'endpoint_disabled', 'next_attempt_at' => null])->save();

            return;
        }

        $started = microtime(true);
        $status = null;
        $error = null;

        try {
            $pinned = $this->assertSafe($endpoint->url);
            $timestamp = now()->timestamp;
            $headers = $this->headers($delivery) + $this->signer->headers($endpoint, $timestamp, $delivery->payload);

            $response = $this->target->client($pinned, (int) config('webhooks.limits.timeout_seconds'), $headers)
                ->withBody($delivery->payload, 'application/json')
                ->post($endpoint->url);

            $status = $response->status();
            $error = $response->successful() ? null : ($status >= 300 && $status < 400 ? 'redirect_not_followed' : "http_{$status}");
        } catch (UnsafeTarget) {
            $error = 'unsafe_target';
        } catch (ConnectionException $e) {
            $error = $this->connectionError($e->getMessage());
        } catch (Throwable $e) {
            $error = str_contains($e->getMessage(), 'Response too large') ? 'response_too_large' : 'connection';
        }

        $this->finish($delivery, $endpoint, $status, $error, (int) round((microtime(true) - $started) * 1000));
    }

    /** @return array<string, string> */
    private function headers(WebhookDelivery $delivery): array
    {
        return [
            'User-Agent' => 'Ava-Webhooks/1',
            'Accept' => 'application/json, text/plain, */*',
            'X-Ava-Event' => $delivery->event,
            'X-Ava-Event-Id' => $delivery->event_id,
            'X-Ava-Delivery' => $delivery->public_id,
            'X-Ava-Delivery-Attempt' => (string) $delivery->attempts,
        ];
    }

    private function connectionError(string $message): string
    {
        return match (true) {
            str_contains($message, 'cURL error 28'), stripos($message, 'timed out') !== false => 'timeout',
            str_contains($message, 'cURL error 60'), str_contains($message, 'cURL error 35'), str_contains($message, 'cURL error 51'), stripos($message, 'SSL') !== false => 'tls',
            str_contains($message, 'cURL error 6'), stripos($message, 'resolve host') !== false => 'dns',
            default => 'connection',
        };
    }

    private function finish(WebhookDelivery $delivery, WebhookEndpoint $endpoint, ?int $status, ?string $error, int $ms): void
    {
        $base = ['response_status' => $status, 'duration_ms' => $ms, 'error' => $error];

        if ($error === null) {
            $delivery->forceFill($base + ['status' => WebhookDelivery::DELIVERED, 'delivered_at' => now(), 'next_attempt_at' => null])->save();
            WebhookEndpoint::query()->whereKey($endpoint->id)->update(['consecutive_failures' => 0, 'last_success_at' => now()]);

            return;
        }

        $backoff = array_values((array) config('webhooks.limits.backoff_seconds'));
        $retryIn = $backoff[$delivery->attempts - 1] ?? null;

        // The destination being unsafe will not fix itself within seconds, and the sweeper would only repeat the refusal: it is abandoned at once.
        if ($retryIn === null || $error === 'unsafe_target') {
            $delivery->forceFill($base + ['status' => WebhookDelivery::ABANDONED, 'next_attempt_at' => null])->save();
            $this->abandoned($endpoint);

            return;
        }

        $delivery->forceFill($base + ['status' => WebhookDelivery::FAILED, 'next_attempt_at' => now()->addSeconds((int) $retryIn)])->save();

        if (config('queue.default') !== 'sync') {
            try {
                DeliverWebhook::dispatch($delivery->id)->delay((int) $retryIn + 1); // +1 s: `next_attempt_at` is stored with whole-second precision
            } catch (Throwable $e) {
                report($e); // `webhooks:sweep` re-dispatches it when it is due
            }
        }
    }

    /** The retries ran out for one delivery: count it against the endpoint, and switch the endpoint off after too many in a row. */
    private function abandoned(WebhookEndpoint $endpoint): void
    {
        WebhookEndpoint::query()->whereKey($endpoint->id)->update(['consecutive_failures' => DB::raw('consecutive_failures + 1'), 'last_failure_at' => now()]);
        $endpoint->refresh();

        if (! $endpoint->is_active || $endpoint->consecutive_failures < (int) config('webhooks.limits.disable_after_failures')) {
            return;
        }

        $endpoint->forceFill(['is_active' => false, 'disabled_reason' => 'failures'])->save();

        if ($endpoint->workspace && Notifications::ready()) {
            app(Notifications::class)->notify(
                $endpoint->workspace, 'webhook_disabled', "El webhook «{$endpoint->name}» se desactivó",
                'Falló demasiadas veces seguidas. Corrige el destino y actívalo de nuevo; puedes reintentar las entregas fallidas.',
                '/integrations', 'manage-webhooks', null, 'webhook_endpoint', $endpoint->id, 'webhook:'.$endpoint->id.':disabled:'.now()->format('YmdHis'),
            );
        }
    }
}
