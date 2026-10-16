<?php

/*
 * Pedidos (orders) of E-commerce and Delivery: ONE order model for both. Nothing here is a business rule of a solution: an order is
 * either picked up or delivered, and the same states serve both (a pickup order is never `dispatched`).
 *
 *  - `statuses`: the states and how the screen names them.
 *  - `transitions`: the only moves the server accepts. `delivered` and `cancelled` are final: nothing leaves them and no exception exists.
 *  - `methods`: `pickup` (the customer collects it) and `delivery` (somebody takes it to an address). A `delivery` order carries an address.
 *  - `cancel`: leaving the flow for `cancelled` needs a written reason and the permission to cancel (`manage-orders`); every other move needs `process-orders`.
 *  - `limits`: what one order may hold. The total is always computed by the server from the prices the catalog has at that moment.
 *
 * Not defined yet (so not stored): discounts, taxes, shipping cost, payment state, coverage zones. See RECOMENDACIONES.md before adding any.
 */
return [
    'statuses' => [
        'received' => 'Recibido',
        'confirmed' => 'Confirmado',
        'preparing' => 'En preparación',
        'ready' => 'Listo',
        'dispatched' => 'Despachado',
        'delivered' => 'Entregado',
        'cancelled' => 'Cancelado',
    ],

    'transitions' => [
        'received' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['dispatched', 'delivered', 'cancelled'],
        'dispatched' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ],

    'terminal' => ['delivered', 'cancelled'],

    'methods' => ['pickup' => 'Recogida', 'delivery' => 'Entrega a domicilio'],

    /* A move that only makes sense for one method: ready -> dispatched is for deliveries, ready -> delivered (handed over at the counter) for pickups. */
    'method_moves' => ['ready>dispatched' => 'delivery', 'ready>delivered' => 'pickup'],

    'limits' => ['lines' => 50, 'quantity' => 9999, 'price' => 99999999.99, 'notes' => 500, 'reason' => 255],
];
