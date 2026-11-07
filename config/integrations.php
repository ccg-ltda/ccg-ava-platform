<?php

use App\Integrations\BookerType;
use App\Integrations\CardnetType;
use App\Integrations\HttpApiType;
use App\Integrations\InstagramType;
use App\Integrations\MessengerType;
use App\Integrations\N8nType;
use App\Integrations\WebType;
use App\Integrations\WhatsAppType;
use App\Integrations\WompiType;

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
        'instagram' => InstagramType::class,
        'messenger' => MessengerType::class,
        'web' => WebType::class,
        'n8n' => N8nType::class,
        'booker' => BookerType::class,
        'cardnet' => CardnetType::class,
        'wompi' => WompiType::class,
    ],

    /*
     * Types with their own card in Integraciones (not a generic connection and not a chatbot channel): they never
     * appear in the generic connection form or list.
     */
    'dedicated' => ['n8n', 'booker', 'cardnet', 'wompi'],

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

    /*
     * Booker (external agenda): API hosts by environment and the seconds Ava waits for it. The paths and the authentication come from
     * Booker's open-source Ruby client, not from a Sandbox test: see App\Integrations\BookerClient.
     */
    'booker' => [
        'timeout' => 15,
        /* Hosts by environment. They are environment configuration, never hard-wired: the integration of each Workspace picks one. */
        'urls' => ['staging' => env('BOOKER_STAGING_URL', 'https://api-staging.booker.com'), 'production' => env('BOOKER_PRODUCTION_URL', 'https://api.booker.com')],

        /* `scope` of the OAuth client-credentials token. `public` is what the customer API (Ruby client) uses; the scope the Merchant API needs is NOT confirmed: set BOOKER_SCOPE after the first Staging test. */
        'scope' => env('BOOKER_SCOPE', 'public'),

        /*
         * The Merchant API (the one Booker's support confirmed for availability and booking). The paths are the ones support gave for Staging;
         * the SHAPE of the requests and responses is not documented anywhere Ava can read, so it lives in one class (App\Booking\BookerMerchantCalendar)
         * and is verified in Staging. Until `verified` is true Ava offers availability and booking for a Workspace pointed at STAGING only, never at production.
         * `locations_test_ids` are Booker's shared test locations: never accepted for production.
         */
        'merchant' => [
            'verified' => (bool) env('BOOKER_MERCHANT_VERIFIED', false),
            'cache_seconds' => 300,
            'paths' => [
                'treatments' => '/v4.1/merchant/treatments',
                'customers' => '/v4.1/merchant/customers',
                'availability' => '/v4.1/merchant/availability/1day',
                'appointment_create' => '/v4.1/merchant/appointment/create',
            ],
            'test_location_ids' => ['3749'],
        ],
    ],

    /*
     * CardNET (República Dominicana): hosts by environment, as the CCG team gave them. They are the ONLY thing configured here about the protocol: the paths, fields and
     * response codes are NOT confirmed by the official specification and are deliberately not in the code (App\Payments\Gateways\CardnetAdapter).
     */
    'cardnet' => ['hosts' => ['sandbox' => 'labservicios.cardnet.com.do', 'production' => 'ecommerce.cardnet.com.do']],

    /*
     * Wompi PER WORKSPACE (Colombia). NOT the platform's own Wompi (config/wompi.php): other credentials, other table, other events address. The base URLs and the Web Checkout
     * URL are the ones Wompi documents (docs.wompi.co); seconds Ava waits for the API.
     */
    'wompi' => [
        'api_urls' => ['sandbox' => 'https://sandbox.wompi.co/v1', 'production' => 'https://production.wompi.co/v1'],
        'checkout_url' => 'https://checkout.wompi.co/p/',
        'timeout' => 15,
    ],

    /* WhatsApp Business (Meta Graph API): used only to verify a number with its token (read-only). */
    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v25.0'),
        'timeout' => 10,
        // Requests per minute that ONE integration's webhook accepts (on top of the per-IP limit of the whole group): a flood aimed at one number cannot starve the others.
        'webhook_per_minute' => 300,
    ],

    /*
     * Instagram Messaging and Messenger (Meta). Both are connected by OAUTH, never by pasting a token: the account or Page the person authorizes becomes the
     * Workspace's own integration (its token encrypted). The Meta app itself (IDs and secrets) is the platform's: `config/services.php` `meta`.
     * Hosts and paths come from Meta's current documentation (Instagram API with Instagram Login; Messenger Platform); the Graph version is configuration.
     */
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
        'facebook_graph_url' => 'https://graph.facebook.com',
        'facebook_dialog_url' => 'https://www.facebook.com',
        'instagram_graph_url' => 'https://graph.instagram.com',
        'instagram_oauth_url' => 'https://www.instagram.com/oauth/authorize',
        'instagram_token_url' => 'https://api.instagram.com/oauth/access_token',
        'timeout' => 10,
        // Scopes Meta documents for messaging. Instagram: instagram_business_basic + instagram_business_manage_messages. Messenger: pages_show_list + pages_messaging + pages_manage_metadata (subscribe the Page to the webhook).
        'scopes' => [
            'instagram' => ['instagram_business_basic', 'instagram_business_manage_messages'],
            'messenger' => ['pages_show_list', 'pages_messaging', 'pages_manage_metadata'],
        ],
        // The webhook fields Ava subscribes to: only what it processes (messages). Delivery receipts and reactions are not handled yet.
        'webhook_fields' => ['instagram' => 'messages', 'messenger' => 'messages'],
        // How long the authorization the person started stays valid (the `state`), and how long the Pages list waits for the choice.
        'state_minutes' => 15,
        'webhook_per_minute' => 600,
    ],

    /*
     * The connection test calls a URL chosen by a Workspace admin from the server, so it must never reach
     * internal services (database, Redis, MinIO, cloud metadata...). Private and reserved addresses are
     * refused unless this is explicitly enabled (local development against a mock API only).
     */
    'allow_private_hosts' => (bool) env('INTEGRATIONS_ALLOW_PRIVATE_HOSTS', false),
];
