<?php

/*
 * Protection of the API surface against abuse and overload (RECOMENDACIONES.md §79). The limits that already existed keep their own keys (`chatbots.agent_per_minute`,
 * `chatbots.widget.*`, `bookings.public.*`, `auth.authenticated_per_minute`, the inbound provider webhooks): this file ADDS the layers they lacked and every number here is
 * a conservative starting point, meant to be tuned when the commercial definition arrives. Nothing is read from the environment on purpose: a limit changes by a reviewed edit.
 *
 * Who is the consumer of a request (never what the request SAYS it is):
 *  - agent       an automation (n8n) holding the token of ONE chatbot (`/api/agent/*`): identified by the token, and through it the Workspace.
 *  - widget      a visitor of the public chat: anonymous, so IP and the public widget key are the only identities.
 *  - booking     a visitor of the public booking page: same.
 *  - user        a signed-in person (`routes/web.php`): the account, and the Workspace of the SESSION.
 *  - provider    Meta / Wompi: authenticated by signature, bucketed by IP or by the public integration key.
 *  Workers, the scheduler and outbound webhook deliveries never pass through HTTP middleware: they are not limited by any of this (their own retry policies apply).
 */
return [
    'agent' => [
        // All the traffic of ONE Workspace's automations together (several chatbots, one tenant): a single client can not monopolise the shared workers and the database.
        // A busy conversation makes about 3 to 6 calls; 1 800/min is some 300 conversations per minute.
        'workspace_per_minute' => 1800,
        // State-changing tools (book, cancel, reschedule, create an order, report a message) per Workspace per minute: a loop of an automation can not create thousands of rows.
        'write_per_minute' => 240,
        // Requests of one Workspace being served at the same moment.
        'concurrent' => 25,
        // Largest body an automation may send (messages are small; 256 KB leaves ample room).
        'max_body_kb' => 256,
    ],

    'widget' => [
        // Every visitor of ONE widget key together (the per-IP limit stops one visitor; this stops many IPs against one tenant's AI): 20/min/visitor is already enforced.
        'key_messages_per_minute' => 300,
        'max_body_kb' => 16,
    ],

    'booking' => [
        'key_write_per_minute' => 60,
        'max_body_kb' => 16,
    ],

    'user' => [
        // The people of ONE Workspace together, on top of the per-person bucket (`auth.authenticated_per_minute`).
        'workspace_per_minute' => 3000,
    ],

    'provider' => [
        // Provider webhooks (Meta, Wompi) carry a signature; this only bounds what is read before it is checked.
        'max_body_kb' => 1024,
    ],

    // A refused request is logged (route pattern, consumer type, Workspace id, IP; never a token, a header, a URL with a key or a body).
    'log_exceeded' => true,
];
