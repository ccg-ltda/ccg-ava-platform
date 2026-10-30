<?php

namespace App\Webhooks;

use App\Audit\AuditLogger;
use App\Models\InboundEndpoint;
use App\Models\InboundEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes the INBOUND endpoints of ONE Workspace (the URLs the customer's system calls; the receiving side is InboundWebhooks). Every query starts from the Workspace of
 * the request, so an endpoint of another Workspace can not be reached whatever id the browser sends. The secret is generated here and returned ONCE (create, rotate) and has nothing
 * to do with the signing secret of the outbound webhooks (WebhookService): the two directions never share a secret.
 */
class InboundWebhookService
{
    public const MAX_ENDPOINTS = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{enabled: bool, endpoints: list<array<string, mixed>>, maxEndpoints: int, graceHours: int, toleranceSeconds: int} */
    public function overview(Workspace $workspace): array
    {
        $base = ['enabled' => InboundWebhooks::ready(), 'endpoints' => [], 'maxEndpoints' => self::MAX_ENDPOINTS, 'graceHours' => (int) config('webhooks.limits.secret_grace_hours'), 'toleranceSeconds' => 300];

        if (! $base['enabled']) {
            return $base;
        }

        $settings = $workspace->settingsOrDefault();
        $when = fn ($date) => $date ? $settings->formatDate($date).' '.$settings->formatTime($date) : null;

        $base['endpoints'] = $workspace->inboundEndpoints()->orderBy('name')->get()->map(fn (InboundEndpoint $endpoint) => [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => route('webhooks.inbound.receive', $endpoint->public_key),
            'isActive' => $endpoint->is_active,
            'secretHint' => $endpoint->secret_hint,
            'graceUntil' => $endpoint->previousSecretIsValid() ? $when($endpoint->previous_secret_expires_at) : null,
            'received' => $endpoint->received_count,
            'rejected' => $endpoint->rejected_count,
            'lastReceivedAt' => $when($endpoint->last_received_at),
            'createdAt' => $when($endpoint->created_at),
        ])->all();

        return $base;
    }

    /** @return array{endpoint: InboundEndpoint, secret: string} */
    public function create(Workspace $workspace, string $name, ?User $by): array
    {
        $this->assertReady();

        if ($workspace->inboundEndpoints()->count() >= self::MAX_ENDPOINTS) {
            throw ValidationException::withMessages(['name' => 'Alcanzaste el máximo de endpoints entrantes de este Workspace. Elimina uno que ya no uses.']);
        }

        $secret = $this->newSecret();
        $endpoint = $workspace->inboundEndpoints()->create(['name' => $name, 'public_key' => bin2hex(random_bytes(24)), 'secret' => $secret, 'secret_hint' => substr($secret, -4), 'is_active' => true, 'created_by_user_id' => $by?->id]);

        $this->audit->record('created', 'inbound_endpoint', $endpoint->id, $endpoint->name, [], $workspace);

        return ['endpoint' => $endpoint->refresh(), 'secret' => $secret];
    }

    public function rename(InboundEndpoint $endpoint, string $name): void
    {
        $before = $endpoint->name;
        $endpoint->forceFill(['name' => $name])->save();

        $before !== $name && $this->audit->record('updated', 'inbound_endpoint', $endpoint->id, $endpoint->name, [AuditLogger::change('name', $before, $name)], $endpoint->workspace);
    }

    public function setActive(InboundEndpoint $endpoint, bool $active): void
    {
        $before = $endpoint->is_active;
        $endpoint->forceFill(['is_active' => $active])->save();

        $this->audit->record('updated', 'inbound_endpoint', $endpoint->id, $endpoint->name, [AuditLogger::change('estado', $before ? 'activo' : 'inactivo', $active ? 'activo' : 'inactivo')], $endpoint->workspace);
    }

    /** The new secret signs from now on; the previous one is ALSO accepted for the grace period so the sender can change it without losing events. Returned once. */
    public function rotate(InboundEndpoint $endpoint): string
    {
        $secret = $this->newSecret();
        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret, 'previous_secret_expires_at' => now()->addHours((int) config('webhooks.limits.secret_grace_hours')),
            'secret' => $secret, 'secret_hint' => substr($secret, -4), 'secret_rotated_at' => now(),
        ])->save();

        $this->audit->record('rotated', 'inbound_endpoint', $endpoint->id, $endpoint->name, [], $endpoint->workspace);

        return $secret;
    }

    public function destroy(InboundEndpoint $endpoint): void
    {
        $this->audit->record('deleted', 'inbound_endpoint', $endpoint->id, $endpoint->name, [], $endpoint->workspace);
        $endpoint->delete();
    }

    /** One page of what an endpoint accepted (type, size, time; never the body). @return array{events: list<array<string, mixed>>, meta: array<string, int>} */
    public function events(InboundEndpoint $endpoint, int $page = 1): array
    {
        $settings = $endpoint->workspace->settingsOrDefault();
        $paginator = InboundEvent::query()->where('inbound_endpoint_id', $endpoint->id)->where('workspace_id', $endpoint->workspace_id)->latest('id')->paginate(15, ['*'], 'page', max(1, $page));

        return [
            'events' => $paginator->getCollection()->map(fn (InboundEvent $event) => [
                'id' => $event->id, 'eventId' => $event->event_id, 'type' => $event->event_type, 'bytes' => $event->bytes, 'at' => $settings->formatDate($event->created_at).' '.$settings->formatTime($event->created_at),
            ])->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'from' => (int) $paginator->firstItem(), 'to' => (int) $paginator->lastItem(), 'total' => $paginator->total()],
        ];
    }

    private function assertReady(): void
    {
        if (! InboundWebhooks::ready()) {
            throw ValidationException::withMessages(['name' => 'Los endpoints entrantes todavía no están disponibles en este entorno (falta aplicar la migración).']);
        }
    }

    private function newSecret(): string
    {
        return InboundWebhooks::SECRET_PREFIX.Str::random(40);
    }
}
