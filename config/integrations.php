<?php

use App\Integrations\HttpApiType;

/*
 * Integraciones: catalog of integration TYPES and the limits of the generic HTTP/REST type.
 * A new type (OAuth, webhook, a specific provider...) is one class implementing App\Integrations\IntegrationType
 * registered here; the page, the table and the permissions stay the same.
 */
return [
    'types' => [
        'http' => HttpApiType::class,
    ],

    'http' => [
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'auth_types' => [
            'none' => 'Sin autenticación',
            'api_key' => 'API Key',
            'bearer' => 'Bearer Token',
            'basic' => 'Basic Auth',
        ],
        'api_key_locations' => ['header' => 'Header', 'query' => 'Query parameter'],
        'default_timeout' => 10,
        'max_timeout' => 30,
        'max_headers' => 20,
        'max_query' => 20,
        'max_body_bytes' => 20000,
        /* Headers the HTTP client must control itself. */
        'reserved_headers' => ['host', 'content-length', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer', 'expect'],
    ],

    /*
     * The connection test calls a URL chosen by a Workspace admin from the server, so it must never reach
     * internal services (database, Redis, MinIO, cloud metadata...). Private and reserved addresses are
     * refused unless this is explicitly enabled (local development against a mock API only).
     */
    'allow_private_hosts' => (bool) env('INTEGRATIONS_ALLOW_PRIVATE_HOSTS', false),
];
