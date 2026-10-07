<?php

use App\Integrations\HttpApiType;
use App\Integrations\N8nType;
use App\Integrations\WebType;
use App\Integrations\WhatsAppType;

/*
 * Integraciones: catalog of integration TYPES and the limits of the generic HTTP/REST type.
 * A new type (OAuth, webhook, a specific provider...) is one class implementing App\Integrations\IntegrationType
 * registered here; the page, the table and the permissions stay the same. A type that a chatbot channel uses
 * (config/chatbots.php) is configured from the channel cards, not from the generic connection form.
 */
return [
    'types' => [
        'http' => HttpApiType::class,
        'whatsapp' => WhatsAppType::class,
        'web' => WebType::class,
        'n8n' => N8nType::class,
    ],

    /*
     * Types with their own card in Integraciones (not a generic connection and not a chatbot channel): they never
     * appear in the generic connection form or list.
     */
    'dedicated' => ['n8n'],

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

    /* n8n: seconds Ava waits for the n8n API when testing the connection. */
    'n8n' => ['timeout' => 10],

    /* WhatsApp Business (Meta Graph API): used only to verify a number with its token (read-only). */
    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v25.0'),
        'timeout' => 10,
    ],

    /*
     * The connection test calls a URL chosen by a Workspace admin from the server, so it must never reach
     * internal services (database, Redis, MinIO, cloud metadata...). Private and reserved addresses are
     * refused unless this is explicitly enabled (local development against a mock API only).
     */
    'allow_private_hosts' => (bool) env('INTEGRATIONS_ALLOW_PRIVATE_HOSTS', false),
];
