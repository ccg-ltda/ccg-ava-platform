<?php

namespace App\Payments\Gateways;

use App\Integrations\WompiType;
use App\Models\Integration;
use App\Models\PaymentTransaction;

/**
 * Wompi (Colombia) Web Checkout for ONE Workspace's own commercial account. Independent of the platform's Wompi.
 *
 * Flow (documented by Wompi, docs.wompi.co): the server builds the checkout URL (`https://checkout.wompi.co/p/`) with the amount in cents, the currency, ITS reference and the
 * integrity signature SHA-256(reference + amount + currency + integrity secret); the customer pays on Wompi; Wompi sends the browser back to `redirect-url` adding `?id=` and sends
 * a signed `transaction.updated` event. NOTHING of that is believed: the truth is the transaction read from Wompi's API by its id, compared with this transaction's reference,
 * amount and currency (PaymentService). The id in the URL is only a candidate to look up.
 *
 * Not done on purpose: refunds by API (the void endpoint is documented but its response was not verifiable, so `capabilities()` says no; a return made in Wompi's own portal arrives
 * as a `VOIDED` event and is recorded as refunded) and the optional `expiration-time` (its format could not be verified).
 */
class WompiAdapter implements GatewayAdapter
{
    public function __construct(private readonly WompiType $type, private readonly WompiClient $client) {}

    public function key(): string
    {
        return 'wompi';
    }

    public function label(): string
    {
        return 'Wompi';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(query: true, notifications: true, refund: false, partialRefund: false);
    }

    public function currencies(): array
    {
        return ['COP'];
    }

    public function countries(): array
    {
        return ['CO'];
    }

    public function hosts(): array
    {
        return [parse_url((string) config('integrations.wompi.checkout_url'), PHP_URL_HOST)];
    }

    public function environment(Integration $integration): string
    {
        return ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    public function configurationProblems(Integration $integration): array
    {
        return $this->type->problems($integration->config ?? [], $integration->secrets);
    }

    public function providerReference(string $reference): string
    {
        return $reference;
    }

    public function createSession(PaymentTransaction $transaction, Integration $integration, CheckoutContext $context): GatewayCheckout
    {
        if ($this->configurationProblems($integration) !== [] || $transaction->currency !== 'COP') {
            throw new PaymentException('El pago con Wompi no está disponible.', 'gateway_unavailable');
        }

        $url = rtrim((string) config('integrations.wompi.checkout_url'), '?').'?'.http_build_query([
            'public-key' => $integration->config['public_key'],
            'currency' => 'COP',
            'amount-in-cents' => $transaction->amount_minor,
            'reference' => $transaction->provider_reference,
            'signature:integrity' => hash('sha256', $transaction->provider_reference.$transaction->amount_minor.'COP'.$integration->secrets['integrity_secret']),
            'redirect-url' => $context->returnUrl,
        ], '', '&', PHP_QUERY_RFC3986);

        // The URL carries this transaction's return token, which exists only now: it is kept (encrypted) so the page can send the customer there again without a new session.
        return new GatewayCheckout('redirect', $url, [], ['checkout_url' => $url]);
    }

    public function resume(PaymentTransaction $transaction, Integration $integration): GatewayCheckout
    {
        $url = $transaction->secrets['checkout_url'] ?? null;

        if (! is_string($url)) {
            throw new PaymentException('No se pudo reanudar el pago. Intenta de nuevo.', 'session_failed');
        }

        return new GatewayCheckout('redirect', $url, [], ['checkout_url' => $url]);
    }

    public function query(PaymentTransaction $transaction, Integration $integration): GatewayResult
    {
        $known = $transaction->external_id;
        $id = $known ?? ($transaction->metadata['candidate_id'] ?? null);

        // Wompi was never told anything we can look up: the customer has not reached it (or its event has not arrived yet). That is «not started», not progress and not failure.
        if (! is_string($id) || $id === '') {
            return new GatewayResult(GatewayOutcome::Pending, code: 'not_started');
        }

        $started = microtime(true);
        $response = $this->client->transaction($integration, $id);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if (! $response['ok']) {
            // An id nobody knows is only a bad candidate from the browser; the same answer for an id Ava itself recorded would be strange, and strange is «unknown».
            return $known === null && $response['status'] === 404
                ? new GatewayResult(GatewayOutcome::Pending, code: 'not_started', httpStatus: 404, durationMs: $ms)
                : GatewayResult::unknown($response['error'] === 'timeout' ? 'timeout' : ($response['error'] === 'connection' ? 'connection_error' : 'http_error'), $response['status'], $ms);
        }

        $result = self::resultFrom($response['data'], $id, $ms, $response['status']);

        // A candidate that belongs to ANOTHER reference (somebody else's transaction, or a forged id) says nothing about this one.
        if ($result === null) {
            return $known === null ? new GatewayResult(GatewayOutcome::Pending, code: 'not_started', durationMs: $ms) : GatewayResult::unknown('unreadable_answer', $response['status'], $ms);
        }

        if ($result->providerReference !== $transaction->provider_reference) {
            return $known === null ? new GatewayResult(GatewayOutcome::Pending, code: 'not_started', durationMs: $ms) : GatewayResult::unknown('reference_mismatch', $response['status'], $ms);
        }

        return $result;
    }

    /**
     * What a Wompi transaction (read from its API) says, in Ava's terms; null when the answer is not what was asked for or is malformed. Statuses are the documented ones:
     * APPROVED, DECLINED, ERROR, VOIDED and PENDING; anything else is «unknown», never a guess.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resultFrom(array $data, string $expectedId, ?int $durationMs = null, ?int $httpStatus = null): ?GatewayResult
    {
        if ((string) ($data['id'] ?? '') !== $expectedId || ! isset($data['status'], $data['amount_in_cents'], $data['currency'], $data['reference']) || ! is_numeric($data['amount_in_cents'])) {
            return null;
        }

        $outcome = match (strtoupper((string) $data['status'])) {
            'APPROVED' => GatewayOutcome::Approved,
            'DECLINED', 'ERROR' => GatewayOutcome::Declined,
            'VOIDED' => GatewayOutcome::Refunded,
            'PENDING' => GatewayOutcome::Pending,
            default => GatewayOutcome::Unknown,
        };

        return new GatewayResult($outcome, (int) $data['amount_in_cents'], strtoupper((string) $data['currency']), (string) $data['reference'], (string) $data['id'], null, strtolower((string) $data['status']), $httpStatus, $durationMs);
    }
}
