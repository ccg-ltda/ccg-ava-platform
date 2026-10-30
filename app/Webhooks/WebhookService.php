<?php

namespace App\Webhooks;

use App\Audit\AuditLogger;
use App\Integrations\UnsafeTarget;
use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes the webhook endpoints of ONE Workspace. Every query starts from the Workspace of the request, so an endpoint, a delivery or a secret of another
 * Workspace can not be reached from here whatever id the browser sends. The secret is generated here and returned ONCE (create, rotate); nothing else ever returns it,
 * and neither the audit log nor the delivery history stores it or any payload.
 */
class WebhookService
{
    public const SECRET_PREFIX = 'whsec_';

    public function __construct(private readonly AuditLogger $audit, private readonly WebhookSender $sender) {}

    /** @return array{enabled: bool, endpoints: list<array<string, mixed>>, events: list<array<string, mixed>>, limits: array<string, int>} */
    public function overview(Workspace $workspace): array
    {
        $catalog = collect(Webhooks::catalog())->map(fn (array $event, string $key) => ['key' => $key, 'group' => $event['group'], 'label' => $event['label'], 'description' => $event['description']])->values()->all();

        if (! Webhooks::ready()) {
            return ['enabled' => false, 'endpoints' => [], 'events' => $catalog, 'limits' => $this->limits()];
        }

        $settings = $workspace->settingsOrDefault();
        $when = fn ($date) => $date ? $settings->formatDate($date).' '.$settings->formatTime($date) : null;

        $endpoints = $workspace->webhookEndpoints()->orderBy('name')->get()->map(fn (WebhookEndpoint $endpoint) => [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => array_values((array) $endpoint->events),
            'isActive' => $endpoint->is_active,
            'disabledReason' => $endpoint->disabled_reason,
            'secretHint' => $endpoint->secret_hint,
            'secretRotatedAt' => $when($endpoint->secret_rotated_at),
            'graceUntil' => $endpoint->previousSecretIsValid() ? $when($endpoint->previous_secret_expires_at) : null,
            'consecutiveFailures' => $endpoint->consecutive_failures,
            'lastSuccessAt' => $when($endpoint->last_success_at),
            'lastFailureAt' => $when($endpoint->last_failure_at),
            'createdAt' => $when($endpoint->created_at),
        ])->all();

        return ['enabled' => true, 'endpoints' => $endpoints, 'events' => $catalog, 'limits' => $this->limits()];
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return ['maxEndpoints' => (int) config('webhooks.limits.max_endpoints'), 'timeoutSeconds' => (int) config('webhooks.limits.timeout_seconds'), 'retentionDays' => (int) config('webhooks.limits.retention_days'), 'attempts' => count((array) config('webhooks.limits.backoff_seconds')) + 1, 'graceHours' => (int) config('webhooks.limits.secret_grace_hours')];
    }

    /** @param  array{name: string, url: string, events: list<string>}  $data  @return array{endpoint: WebhookEndpoint, secret: string} */
    public function create(Workspace $workspace, array $data, ?User $by): array
    {
        $this->assertReady();

        if ($workspace->webhookEndpoints()->count() >= (int) config('webhooks.limits.max_endpoints')) {
            throw ValidationException::withMessages(['name' => 'Alcanzaste el máximo de webhooks de este Workspace. Elimina uno que ya no uses.']);
        }

        $this->assertDestination($data['url']);
        $secret = $this->newSecret();

        $endpoint = $workspace->webhookEndpoints()->create([
            'name' => $data['name'], 'url' => $data['url'], 'events' => $this->events($data['events']), 'secret' => $secret, 'secret_hint' => substr($secret, -4),
            'is_active' => true, 'created_by_user_id' => $by?->id,
        ]);

        $this->audit->record('created', 'webhook_endpoint', $endpoint->id, $endpoint->name, [
            AuditLogger::change('url', null, $this->host($endpoint->url)), AuditLogger::change('events', null, (string) count($endpoint->events)),
        ], $workspace);

        return ['endpoint' => $endpoint->refresh(), 'secret' => $secret];
    }

    /** @param  array{name: string, url: string, events: list<string>}  $data */
    public function update(WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        $this->assertReady();

        if ($data['url'] !== $endpoint->url) {
            $this->assertDestination($data['url']);
        }

        $before = ['name' => $endpoint->name, 'url' => $this->host($endpoint->url), 'events' => implode(',', (array) $endpoint->events)];
        $endpoint->forceFill(['name' => $data['name'], 'url' => $data['url'], 'events' => $this->events($data['events'])])->save();

        $this->audit->record('updated', 'webhook_endpoint', $endpoint->id, $endpoint->name, array_values(array_filter([
            $before['name'] !== $endpoint->name ? AuditLogger::change('name', $before['name'], $endpoint->name) : null,
            $before['url'] !== $this->host($endpoint->url) ? AuditLogger::change('url', $before['url'], $this->host($endpoint->url)) : null,
            $before['events'] !== implode(',', $endpoint->events) ? AuditLogger::change('events', $before['events'], implode(',', $endpoint->events)) : null,
        ])), $endpoint->workspace);

        return $endpoint;
    }

    public function setActive(WebhookEndpoint $endpoint, bool $active): void
    {
        $before = $endpoint->is_active;

        if ($active) {
            $this->assertDestination($endpoint->url);
        }

        $endpoint->forceFill(['is_active' => $active, 'disabled_reason' => $active ? null : 'manual', 'consecutive_failures' => $active ? 0 : $endpoint->consecutive_failures])->save();

        $this->audit->record('updated', 'webhook_endpoint', $endpoint->id, $endpoint->name, [
            AuditLogger::change('estado', $before ? 'activo' : 'inactivo', $active ? 'activo' : 'inactivo'),
        ], $endpoint->workspace);
    }

    /** The new secret signs from now on; the previous one keeps signing a second header for `secret_grace_hours`. Returned once. */
    public function rotate(WebhookEndpoint $endpoint): string
    {
        $secret = $this->newSecret();

        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => now()->addHours((int) config('webhooks.limits.secret_grace_hours')),
            'secret' => $secret, 'secret_hint' => substr($secret, -4), 'secret_rotated_at' => now(),
        ])->save();

        $this->audit->record('rotated', 'webhook_endpoint', $endpoint->id, $endpoint->name, [], $endpoint->workspace);

        return $secret;
    }

    public function destroy(WebhookEndpoint $endpoint): void
    {
        $this->audit->record('deleted', 'webhook_endpoint', $endpoint->id, $endpoint->name, [AuditLogger::change('url', $this->host($endpoint->url), null)], $endpoint->workspace);
        $endpoint->delete();
    }

    public function sendTest(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $this->assertDestination($endpoint->url);

        return Webhooks::test($endpoint);
    }

    /** The delivery history of one endpoint, newest first. Never the body that was sent and never a secret. @return list<array<string, mixed>> */
    public function deliveries(WebhookEndpoint $endpoint, int $limit = 50): array
    {
        $workspace = $endpoint->workspace;
        $settings = $workspace->settingsOrDefault();
        $when = fn ($date) => $date ? $settings->formatDate($date).' '.$settings->formatTime($date) : null;

        return $endpoint->deliveries()->latest('id')->limit($limit)->get()->map(fn (WebhookDelivery $delivery) => [
            'id' => $delivery->id,
            'deliveryId' => $delivery->public_id,
            'eventId' => $delivery->event_id,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'responseStatus' => $delivery->response_status,
            'durationMs' => $delivery->duration_ms,
            'error' => $delivery->error,
            'createdAt' => $when($delivery->created_at),
            'lastAttemptAt' => $when($delivery->last_attempt_at),
            'nextAttemptAt' => $delivery->status === WebhookDelivery::FAILED ? $when($delivery->next_attempt_at) : null,
            'canRetry' => in_array($delivery->status, [WebhookDelivery::FAILED, WebhookDelivery::ABANDONED], true),
        ])->all();
    }

    /** A failed or abandoned delivery gets a fresh set of attempts with the SAME event id, so the consumer can still deduplicate. */
    public function retry(WebhookDelivery $delivery): void
    {
        if (! in_array($delivery->status, [WebhookDelivery::FAILED, WebhookDelivery::ABANDONED], true)) {
            throw ValidationException::withMessages(['delivery' => 'Solo se reintentan las entregas fallidas.']);
        }

        $endpoint = $delivery->endpoint;
        $this->assertDestination($endpoint->url);

        $delivery->forceFill(['status' => WebhookDelivery::PENDING, 'attempts' => 0, 'next_attempt_at' => now(), 'error' => null])->save();
        $this->audit->record('retried', 'webhook_endpoint', $endpoint->id, $endpoint->name, [AuditLogger::change('evento', null, $delivery->event)], $endpoint->workspace);

        DeliverWebhook::dispatch($delivery->id);
    }

    private function assertReady(): void
    {
        if (! Webhooks::ready()) {
            throw ValidationException::withMessages(['name' => 'Los webhooks todavía no están disponibles en este entorno (falta aplicar la migración).']);
        }
    }

    private function assertDestination(string $url): void
    {
        try {
            $this->sender->assertSafe($url);
        } catch (UnsafeTarget $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }

    /** @param  list<string>  $events  @return list<string> only events of the catalog, in catalog order, without repeats */
    private function events(array $events): array
    {
        return array_values(array_intersect(array_keys(Webhooks::catalog()), $events));
    }

    private function newSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(40);
    }

    /** What the audit log keeps of a URL: the host only (a path or a query string may hold a key). */
    private function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '');
    }
}
