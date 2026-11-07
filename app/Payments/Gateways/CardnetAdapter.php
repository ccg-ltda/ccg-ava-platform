<?php

namespace App\Payments\Gateways;

use App\Integrations\CardnetType;
use App\Models\Integration;
use App\Models\PaymentTransaction;

/**
 * CardNET (República Dominicana), hosted payment page. STATE OF THIS ADAPTER: the structure is in place (configuration, environments, hosts, currency, the identifier sent to
 * the provider) but the PROTOCOL is deliberately not implemented, because CardNET's official specification could not be read and nothing may be invented.
 *
 * Blocked by the official documentation (docs/pagos-pasarelas.md §9): the session creation request and its fields, the unit and format of the amount, the form that sends the
 * customer to CardNET, the consultation of the result and its response codes, the session lifetime and any refund. `capabilities()` says so: no query, no refund, so
 * PaymentService treats every answer as unknown and Ava offers CardNET to nobody. When the specification is available, only `createSession`, `resume` and `query` change.
 */
class CardnetAdapter implements GatewayAdapter
{
    /** ISO 4217 numeric code the provider form uses for the currency, by currency. Only currencies Ava is sure CardNET serves in the Dominican Republic. */
    public const CURRENCY_CODES = ['DOP' => '214'];

    public function __construct(private readonly CardnetType $type) {}

    public function key(): string
    {
        return 'cardnet';
    }

    public function label(): string
    {
        return 'CardNET';
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(query: false, notifications: false, refund: false, partialRefund: false);
    }

    public function currencies(): array
    {
        return array_keys(self::CURRENCY_CODES);
    }

    public function countries(): array
    {
        return ['DO'];
    }

    public function hosts(): array
    {
        return array_values((array) config('integrations.cardnet.hosts'));
    }

    public function environment(Integration $integration): string
    {
        return ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    public function configurationProblems(Integration $integration): array
    {
        return $this->type->problems($integration->config ?? [], $integration->secrets);
    }

    /** Ava's references are `AVA` + 13 characters (16): they fit the 20 characters the CCG team's CardNET form allows for the order id (to be confirmed by the specification). */
    public function providerReference(string $reference): string
    {
        return substr($reference, 0, 20);
    }

    public function createSession(PaymentTransaction $transaction, Integration $integration, CheckoutContext $context): GatewayCheckout
    {
        throw $this->pending();
    }

    public function resume(PaymentTransaction $transaction, Integration $integration): GatewayCheckout
    {
        throw $this->pending();
    }

    public function query(PaymentTransaction $transaction, Integration $integration): GatewayResult
    {
        return GatewayResult::unknown('protocol_pending');
    }

    private function pending(): PaymentException
    {
        return new PaymentException('El pago con CardNET todavía no está disponible.', 'protocol_pending');
    }
}
