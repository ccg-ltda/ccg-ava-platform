<?php

namespace App\Orders;

use App\Models\Order;

/**
 * The point where order events will be told to the customer (receipt, confirmation, state changes, dispatch, delivery or incident). TODAY NOTHING
 * IS SENT: the bound implementation is UnavailableOrderNotifier. A real one must reuse the delivery pieces of confirmations and reminders of
 * appointments (WhatsApp text and template sending, the 24 h window, delivery states, idempotency, retries) with its own table of events; it must
 * never claim an order was notified or delivered without the provider's evidence. See RECOMENDACIONES.md, «Pedidos».
 */
interface OrderNotifier
{
    public const EVENTS = ['received', 'status_changed'];

    /** Whether a real channel is connected. The screens say so instead of implying the customer was told. */
    public function available(): bool;

    /** Called after an order event is saved. It must never throw into the operation that triggered it. @param  string  $event  one of EVENTS */
    public function notify(Order $order, string $event): void;
}
