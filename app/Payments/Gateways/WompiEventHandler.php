<?php

namespace App\Payments\Gateways;

use App\Models\PaymentTransaction;
use App\Services\OperationalAlert;
use Illuminate\Support\Facades\Cache;

/**
 * A Wompi `transaction.updated` event for ONE Workspace connection. Order matters, and every step can end the work:
 *  1. The address token names the connection (WompiEventToken); an unknown one is a 404. A Workspace's events secret is the only thing that can authenticate its events.
 *  2. The checksum (events secret, per Wompi's documentation) must match, else 400 and an alert. An event of the other environment is ignored.
 *  3. The transaction is found INSIDE that connection and Workspace by the reference Ava sent; an unknown reference is acknowledged and alerted, never created.
 *  4. The event is only a hint: the transaction is read again from Wompi's API and ITS answer decides (PaymentService compares reference, amount and currency). If Wompi cannot be
 *     asked, the answer is 503 so Wompi retries, and the payment is left «in verification», never rejected.
 *  5. The same event (id, status, timestamp) is applied once; events out of order are harmless because each one triggers a fresh read.
 * Wompi only needs HTTP 200; anything else makes it retry (30 minutes, 3 hours, 24 hours). Independent of the platform's own Wompi handler (App\Payments\WompiEvents).
 */
class WompiEventHandler
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const NOT_FOUND = 'not_found';

    public const RETRY = 'retry';

    public function __construct(private readonly WompiClient $client, private readonly PaymentService $payments, private readonly OperationalAlert $alerts) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(string $token, array $payload, ?string $headerChecksum): string
    {
        $integration = WompiEventToken::resolve($token);

        if (! $integration) {
            return self::NOT_FOUND;
        }

        if (! WompiEventSignature::valid($payload, (string) ($integration->secrets['events_secret'] ?? ''), $headerChecksum)) {
            $this->alertOnce('wompi_workspace_event_invalid_signature', $integration->id);

            return self::INVALID;
        }

        $expected = ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'prod' : 'test';

        if (isset($payload['environment']) && $payload['environment'] !== $expected) {
            $this->alertOnce('wompi_workspace_event_wrong_environment', $integration->id);

            return self::OK;
        }

        if (($payload['event'] ?? null) !== 'transaction.updated') {
            return self::OK;
        }

        $event = $payload['data']['transaction'] ?? [];
        $id = (string) ($event['id'] ?? '');
        $reference = $event['reference'] ?? null;

        if ($id === '' || ! is_string($reference) || strlen($id) > 80) {
            return self::INVALID;
        }

        $transaction = PaymentTransaction::where('workspace_id', $integration->workspace_id)->where('integration_id', $integration->id)->where('provider_reference', $reference)->first();

        if (! $transaction) {
            $this->alertOnce('wompi_workspace_event_unknown_reference', $integration->id);

            return self::OK;
        }

        $response = $this->client->transaction($integration, $id);
        $result = $response['ok'] ? WompiAdapter::resultFrom($response['data'], $id, null, $response['status']) : null;

        if ($result === null) {
            // Wompi could not be asked (or answered nonsense): keep the payment in verification and let Wompi send the event again. The key is NOT consumed, so the retry is processed.
            $this->payments->apply($transaction, GatewayResult::unknown($response['ok'] ? 'unreadable_answer' : 'lookup_failed', $response['status']), 'notification', 'provider');

            return self::RETRY;
        }

        if ($result->providerReference !== $transaction->provider_reference) {
            $this->alerts->raise('wompi_workspace_event_reference_mismatch', ['transaction' => $transaction->id, 'workspace' => $transaction->workspace_id]);

            return self::OK;
        }

        $key = 'wompi:'.substr(hash('sha256', $id.'|'.($event['status'] ?? '').'|'.($payload['timestamp'] ?? '')), 0, 40);
        $this->payments->apply($transaction, $result, 'notification', 'provider', $key);

        return self::OK;
    }

    /** Anyone can post garbage: one alert per connection per five minutes is enough to notice it and cannot be flooded. */
    private function alertOnce(string $event, int $integrationId): void
    {
        if (Cache::add("alert:{$event}:{$integrationId}", true, now()->addMinutes(5))) {
            $this->alerts->raise($event, ['integration' => $integrationId]);
        }
    }
}
