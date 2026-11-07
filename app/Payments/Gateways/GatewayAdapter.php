<?php

namespace App\Payments\Gateways;

use App\Models\Integration;
use App\Models\PaymentTransaction;

/**
 * One payment provider. The core (PaymentService) owns Workspace isolation, the state machine, idempotency and events; an adapter only knows its provider's protocol.
 * An adapter must NEVER read credentials from anywhere but the Integration it is given, never log a secret, and never decide that a payment is approved from anything
 * the browser sent: `query()` must ask the provider from the server.
 */
interface GatewayAdapter
{
    public function key(): string;

    public function label(): string;

    public function capabilities(): GatewayCapabilities;

    /** @return list<string> ISO 4217 currencies the provider accepts */
    public function currencies(): array;

    /** @return list<string> hosts (no scheme) the customer is sent to; only these are accepted for the checkout and allowed in the CSP `form-action` */
    public function hosts(): array;

    /** @return list<string> ISO 3166 countries of the merchants the provider serves */
    public function countries(): array;

    /** `sandbox` or `production`, as the connection says. */
    public function environment(Integration $integration): string;

    /** @return list<string> what is missing or invalid in the stored configuration (empty = complete). Never contains a secret. */
    public function configurationProblems(Integration $integration): array;

    /** The identifier sent to the provider as THE order id: it must respect the provider's length/charset. Unique per connection. */
    public function providerReference(string $reference): string;

    public function createSession(PaymentTransaction $transaction, Integration $integration, CheckoutContext $context): GatewayCheckout;

    /** Rebuilds the checkout of an open transaction from what was stored (no new session at the provider). */
    public function resume(PaymentTransaction $transaction, Integration $integration): GatewayCheckout;

    /** Asks the provider, from the server, what happened to this transaction. Must not throw: a failure is `GatewayResult::unknown()`. */
    public function query(PaymentTransaction $transaction, Integration $integration): GatewayResult;
}
