<?php

/*
 * Wompi (Colombia) payment gateway for the public sign-up. Sandbox and production are separate: the environment decides the API host and the key prefix
 * that is accepted, and an event whose `environment` does not match is ignored. Secrets are read only here, on the server, and never reach the browser.
 * The "private key" of Wompi is not used by the hosted Web Checkout flow, so it is not read.
 */
return [
    // sandbox | production
    'environment' => env('WOMPI_ENV', 'sandbox'),
    'public_key' => env('WOMPI_PUBLIC_KEY'),
    // "Secreto para Integridad" (signs the checkout) and "Secreto de eventos" (verifies the webhook): two different values from the merchant dashboard.
    'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
    'events_secret' => env('WOMPI_EVENTS_SECRET'),
    'checkout_url' => 'https://checkout.wompi.co/p/',
    'api_urls' => ['sandbox' => 'https://sandbox.wompi.co/v1', 'production' => 'https://production.wompi.co/v1'],
    'timeout_seconds' => (int) env('WOMPI_TIMEOUT', 10),
];
