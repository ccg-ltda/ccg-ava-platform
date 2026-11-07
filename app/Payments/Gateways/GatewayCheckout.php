<?php

namespace App\Payments\Gateways;

use Carbon\CarbonInterface;

/**
 * How the customer is sent to the provider: `redirect` (GET to `url`) or `form_post` (the browser POSTs `fields` to `url`). `secrets` is provider session material that Ava
 * keeps ENCRYPTED on the transaction (it never reaches the browser unless it is itself a field of the form the provider asks for).
 */
final class GatewayCheckout
{
    /**
     * @param  'redirect'|'form_post'  $method
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $fields = [],
        public readonly array $secrets = [],
        public readonly ?string $externalId = null,
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}
}
