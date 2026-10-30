<?php

/*
 * CORS is a BROWSER policy: it decides which sites may read a response from a script. It is not authentication, authorization or a signature check, and it
 * never touches a request from a server (n8n, Meta, Wompi). Laravel adds these headers only to the paths below; every other path (the application itself,
 * its Inertia requests, `/api/agent/*`, `/api/webhooks/*`, `sanctum/csrf-cookie`) gets none, so a script on another site cannot read them.
 *
 *  - `api/widget/*`: the chat widget runs as a script on the CUSTOMER's own website, so its origin can not be listed here. The server checks the
 *    channel's "allowed sites" itself (WidgetController: 403 when the Origin is not allowed).
 *  - `api/booking/*`: the public booking API, open by design (it is keyed by the Workspace's public link).
 *
 * `supports_credentials` is false and must stay false: these APIs use no cookies, and credentials combined with `*` are invalid and unsafe.
 * CORS_ALLOWED_ORIGINS (comma separated, default `*`) can narrow the origins; narrowing it breaks the widget on every customer site that is not listed.
 */
return [
    'paths' => ['api/widget/*', 'api/booking/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    // What public/widget/ava-widget.js sends: JSON in and out, plus the support-context header.
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Zonno-Context'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,
];
