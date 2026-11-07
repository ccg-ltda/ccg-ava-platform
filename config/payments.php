<?php

use App\Models\Order;
use App\Payments\Gateways\CardnetAdapter;
use App\Payments\Gateways\WompiAdapter;

/*
 * Payment gateways of a Workspace (CardNET, Wompi per Workspace, Azul). Provider-neutral core: App\Payments\Gateways.
 * This is NOT the platform's own Wompi (config/wompi.php, which charges the public sign-up); the two never share code paths, tables or routes.
 *
 *  - `gateways`: key => adapter class. A gateway exists for Ava only when it is registered here; whether a Workspace can use it is decided by its plan,
 *    the administrative grant/revoke and the state of its connection (App\Payments\Gateways\GatewayAvailability).
 *  - `payables`: type => model that can be collected (an order today; appointment deposits later).
 *  - `allow_production`: live charges stay OFF until an operator turns this on after the provider approved the integration. A connection pointed at
 *    production is refused while it is false, so a mis-click cannot charge a real card. There is no simulated payment mode anywhere.
 */
return [
    'gateways' => ['cardnet' => CardnetAdapter::class, 'wompi' => WompiAdapter::class],

    'payables' => ['order' => Order::class],

    'allow_production' => (bool) env('PAYMENTS_ALLOW_PRODUCTION', false),

    /*
     * FOUR different clocks (none of them says «the payment failed»):
     *  - `link_ttl_hours`: how long the customer's link opens the payment PAGE (access). It never touches a transaction already started.
     *  - the provider's own session life: whatever the adapter reports (`GatewayCheckout::$expiresAt`); Ava does not guess it.
     *  - `transaction_ttl_minutes`: the window the customer has to START paying. When it passes and the customer never came back, Ava asks the provider one last time
     *    and, if it is still waiting, frees the order (state `expired`). That closes OUR offer, not the money: see `late_watch_hours`.
     *  - `reconcile.give_up_hours` / `max_attempts`: how long Ava keeps asking about an UNCERTAIN payment (the customer came back, or a query failed). Those never expire by time.
     * `late_watch_hours`: a transaction Ava closed itself (expired, replaced by another attempt, order cancelled) is still asked about this long, because the provider's
     * session may still be open. A verified approval re-opens it (and raises an alert if the order was collected twice). AFTER this window Ava stops asking on its own, but
     * it never assumes a later approval is impossible: a provider notification (webhook) is always verified and applied, and staff can verify any attempt again at any time
     * (PaymentService::reverify).
     */
    'link_ttl_hours' => 72,
    'transaction_ttl_minutes' => 60,
    'late_watch_hours' => 72,

    /* Seconds Ava waits for a provider (create session, query). */
    'timeout_seconds' => 15,

    /*
     * Reconciliation of open transactions (job + scheduler). After the n-th failed check the next one waits `backoff_minutes[n]` (the last value repeats).
     * After `max_attempts` checks, or `give_up_hours` after creation, Ava stops asking and leaves the transaction in `verification_required` for a person.
     */
    'reconcile' => ['late_backoff_minutes' => [10, 30, 60, 180, 360, 720], 'first_check_minutes' => 5, 'backoff_minutes' => [1, 2, 5, 10, 20, 40, 60, 120], 'max_attempts' => 20, 'give_up_hours' => 48, 'batch' => 50],

    'limits' => ['page_per_minute' => 60, 'start_per_minute' => 10, 'callback_per_minute' => 60],
];
