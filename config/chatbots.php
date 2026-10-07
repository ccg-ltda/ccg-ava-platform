<?php

/*
 * Chatbots: the channels a chatbot can answer through. This is the GLOBAL catalog (one entry per channel for the
 * whole platform); what a Workspace configures for a channel is its own row in `integrations` (credentials
 * included), never an entry here.
 *
 *  - `integration`: key of the integration type (config/integrations.php) that holds the Workspace's credentials
 *    for the channel, or null when the channel needs none.
 *  - `conversations`: the automation reports this channel's messages to Ava, which shows them as conversations.
 *  - `embeddable`: the channel is a script on the customer's site (it gets a public key in its install code).
 *  - `available`: false when the platform cannot serve the channel yet; `unavailable` says why. A channel is only
 *    marked available when it really works end to end, so the interface never pretends otherwise.
 *
 * Adding a channel = one entry here (plus its integration type when it needs credentials).
 */
return [
    'channels' => [
        'web' => [
            'label' => 'Web',
            'description' => 'Widget de chat para insertar en el sitio web del cliente.',
            'integration' => 'web',
            'embeddable' => true,
            'available' => true,
            'unavailable' => null,
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'description' => 'Conversaciones con el número de WhatsApp Business del Workspace.',
            'integration' => 'whatsapp',
            'conversations' => true,
            'available' => true,
            'unavailable' => null,
        ],
        'instagram' => [
            'label' => 'Instagram',
            'description' => 'Mensajes directos de la cuenta de Instagram del Workspace.',
            'integration' => null,
            'available' => false,
            'unavailable' => 'Este canal todavía no está disponible.',
        ],
        'messenger' => [
            'label' => 'Messenger',
            'description' => 'Mensajes de la página de Facebook del Workspace.',
            'integration' => null,
            'available' => false,
            'unavailable' => 'Este canal todavía no está disponible.',
        ],
    ],

    /* Public web widget: size of a visitor's message, seconds Ava waits for the n8n webhook, and requests per minute. */
    'widget' => [
        'max_message' => 2000,
        'timeout' => 30,
        'max_reply' => 4000,
        'messages_per_minute' => 20,
        'config_per_minute' => 60,
    ],

    /* Requests per minute an automation may make with one chatbot token (every message it reports is one). */
    'agent_per_minute' => 600,

    /* Longest text of the instructions of a chatbot, in characters. */
    'instructions_max' => 8000,

    /* Avatar of a chatbot: same formats and weight limit as the Workspace logo (never SVG). */
    'avatar' => [
        'mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'max_kb' => 1024,
    ],
];
