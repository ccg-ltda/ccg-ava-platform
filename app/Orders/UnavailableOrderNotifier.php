<?php

namespace App\Orders;

use App\Models\Order;

/** No channel is connected to order events yet: it records nothing and sends nothing. */
class UnavailableOrderNotifier implements OrderNotifier
{
    public function available(): bool
    {
        return false;
    }

    public function notify(Order $order, string $event): void {}
}
