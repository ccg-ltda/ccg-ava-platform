<?php

namespace App\Payments\Gateways;

/** The gateways Ava knows (config/payments.php `gateways`): key => adapter. Knowing a gateway says nothing about who may use it (GatewayAvailability). */
class PaymentGatewayManager
{
    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('payments.gateways', []));
    }

    /** @return list<string> `https://host` of every provider the customer may be sent to (the CSP `form-action` of the payment page) */
    public function formHosts(): array
    {
        $hosts = [];

        foreach ($this->keys() as $key) {
            foreach ($this->adapter($key)->hosts() as $host) {
                $hosts[] = 'https://'.$host;
            }
        }

        return array_values(array_unique($hosts));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, config('payments.gateways', []));
    }

    public function adapter(string $key): GatewayAdapter
    {
        $class = config("payments.gateways.{$key}");

        if (! is_string($class) || ! is_a($class, GatewayAdapter::class, true)) {
            throw new PaymentException('Este método de pago no existe.', 'unknown_gateway');
        }

        return app($class);
    }
}
