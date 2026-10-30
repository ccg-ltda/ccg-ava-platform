<?php

namespace App\Webhooks;

use App\Models\InboundEndpoint;
use App\Models\InboundEvent;
use App\Services\ModuleAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receives ONE signed event on an inbound endpoint (RECOMENDACIONES.md §79.3). What it does, and what it deliberately does not:
 *  - the endpoint is found by its `public_key` (48 random hex characters in the URL: an identifier, not the credential). An unknown, inactive or plan-less endpoint is the SAME 404, so
 *    the URL reveals nothing; the Workspace comes from the endpoint, never from the request;
 *  - the credential is the signature: HMAC-SHA256 of "{timestamp}.{body}" with the endpoint secret (or the previous one during the grace period after a rotation), compared in constant
 *    time, with a 5-minute window against replays of an old capture. A bad signature is a 401 that says nothing about why;
 *  - `X-Ava-Event-Id` is required and unique per endpoint: a repeat is acknowledged (200) and NOT recorded again;
 *  - the body must be a JSON object (the sender's `type`, if any, is kept as metadata); it is NEVER stored and NEVER executed: an inbound event can not invoke any action of Ava. Today
 *    receiving means «accepted and recorded in the history». Acting on an event is a separate, explicit feature that does not exist yet.
 */
class InboundWebhooks
{
    private static bool $ready = false;

    public const SECRET_PREFIX = 'whin_';

    public function __construct(private readonly WebhookSigner $signer, private readonly ModuleAccess $modules) {}

    public static function ready(): bool
    {
        return self::$ready = self::$ready || (Schema::hasTable('inbound_endpoints') && Schema::hasTable('inbound_events'));
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    /** @return array{0: int, 1: array<string, mixed>} HTTP status and JSON answer */
    public function receive(string $key, string $body, ?string $timestamp, ?string $signature, ?string $eventId): array
    {
        $endpoint = self::ready() ? InboundEndpoint::query()->with('workspace.organization')->where('public_key', $key)->first() : null;

        if ($endpoint === null || ! $endpoint->is_active || ! $this->available($endpoint)) {
            return [404, ['error' => 'not_found']];
        }

        if (! $this->signed($endpoint, (string) $timestamp, (string) $signature, $body)) {
            InboundEndpoint::query()->whereKey($endpoint->id)->increment('rejected_count');

            return [401, ['error' => 'invalid_signature']];
        }

        if ($eventId === null || ! preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $eventId)) {
            return [422, ['error' => 'event_id_required', 'message' => 'Envía X-Ava-Event-Id (8 a 64 caracteres: letras, números, . _ : -).']];
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || array_is_list($payload) && $payload !== []) {
            return [422, ['error' => 'invalid_body', 'message' => 'El cuerpo debe ser un objeto JSON.']];
        }

        $type = isset($payload['type']) && is_string($payload['type']) ? mb_substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $payload['type']), 0, 60) : null;

        try {
            DB::transaction(function () use ($endpoint, $eventId, $type, $body) {
                InboundEvent::create(['inbound_endpoint_id' => $endpoint->id, 'workspace_id' => $endpoint->workspace_id, 'event_id' => $eventId, 'event_type' => $type ?: null, 'bytes' => strlen($body)]);
                InboundEndpoint::query()->whereKey($endpoint->id)->update(['received_count' => DB::raw('received_count + 1'), 'last_received_at' => now()]);
            });
        } catch (QueryException $e) {
            // The unique key (endpoint, event id): the same event again is acknowledged, not recorded twice. Any other database error is a real error.
            if (InboundEvent::query()->where('inbound_endpoint_id', $endpoint->id)->where('event_id', $eventId)->exists()) {
                return [200, ['status' => 'duplicate', 'id' => $eventId]];
            }

            throw $e;
        }

        return [202, ['status' => 'accepted', 'id' => $eventId]];
    }

    /** The Workspace and its organization are active and its plan includes Integraciones: otherwise the URL does not exist (the same rule as the public widget). */
    private function available(InboundEndpoint $endpoint): bool
    {
        $workspace = $endpoint->workspace;

        return $workspace !== null && $workspace->is_active && ($workspace->organization?->is_active ?? true) && $this->modules->allows($workspace, 'integrations');
    }

    private function signed(InboundEndpoint $endpoint, string $timestamp, string $signature, string $body): bool
    {
        if ($this->signer->verify($endpoint->secret, $timestamp, $signature, $body)) {
            return true;
        }

        return $endpoint->previousSecretIsValid() && $this->signer->verify($endpoint->previous_secret, $timestamp, $signature, $body);
    }
}
