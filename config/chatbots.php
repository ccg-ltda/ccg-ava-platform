<?php

/*
 * Chatbots: the channels a chatbot can answer through. This is the GLOBAL catalog (one entry per channel for the
 * whole platform); what a Workspace configures for a channel is its own row in `integrations` (credentials
 * included), never an entry here.
 *
 *  - `integration`: key of the integration type (config/integrations.php) that holds the Workspace's credentials
 *    for the channel, or null when the channel needs none.
 *  - `conversations`: Ava keeps this channel's messages as conversations (the automation reports them, or Ava records
 *    them itself, like the web widget) and a human agent may take them over.
 *  - `delivery`: how a message written by a human agent reaches the contact: `integration` (Ava sends it through the
 *    Workspace's integration for the channel, which must implement SendsMessages) or `pull` (the contact's widget
 *    fetches it). A channel without it cannot carry agent replies, and Ava says so instead of pretending.
 *  - `embeddable`: the channel is a script on the customer's site (it gets a public key in its install code).
 *  - `appearance`: how its button looks on the customer's site is configured by the client: `widget` (button + chat
 *    panel) or `button` (a link button). Channels without it have no visual configuration.
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
            'conversations' => true,
            'delivery' => 'pull',
            'embeddable' => true,
            'appearance' => 'widget',
            'available' => true,
            'unavailable' => null,
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'description' => 'Conversaciones con el número de WhatsApp Business del Workspace.',
            'integration' => 'whatsapp',
            'conversations' => true,
            'delivery' => 'integration',
            'embeddable' => true,
            'appearance' => 'button',
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
        'poll_per_minute' => 30,
        'config_per_minute' => 60,
    ],

    /*
     * Visual options of the button / widget a client can choose (value => label). The widget script knows what each
     * value looks like; here live only the allowed values and limits, so the form and the validation share one source.
     */
    'appearance' => [
        'positions' => ['bottom-right' => 'Inferior derecha', 'bottom-left' => 'Inferior izquierda'],
        'open_behaviors' => ['click' => 'Al hacer clic', 'auto' => 'Abrir solo a los 5 segundos'],
        'icons' => ['avatar' => 'Avatar del chatbot', 'channel' => 'Icono del canal'],
        /*
         * Continuous settings, drawn as sliders. `size` and `widget_size` are pixels (button side, chat window width: its
         * height follows from the width), `shape` is the corner rounding of the button as a percentage of its side (50 =
         * circle), `shadow` a level (0 = none) and `radius` the corner radius of the chat in pixels. The widget script
         * draws each value; here live the limits, which the form and the validation share.
         */
        'size' => ['min' => 48, 'max' => 72, 'step' => 4],
        'shape' => ['min' => 0, 'max' => 50, 'step' => 5],
        'shadow' => ['min' => 0, 'max' => 4, 'step' => 1],
        'widget_size' => ['min' => 320, 'max' => 420, 'step' => 10],
        'radius' => ['min' => 0, 'max' => 28, 'step' => 1],
        /* What the earlier named choices (before the sliders) mean now, so a look saved with them keeps its appearance. */
        'legacy' => [
            'size' => ['sm' => 48, 'md' => 60, 'lg' => 72],
            'shape' => ['square' => 10, 'rounded' => 30, 'round' => 50],
            'shadow' => ['none' => 0, 'soft' => 2, 'strong' => 3],
            'widget_size' => ['sm' => 320, 'md' => 370, 'lg' => 420],
        ],
        'max_length' => ['button_text' => 40, 'header_title' => 40, 'welcome_message' => 280, 'message' => 200],
        /* Lowest contrast (WCAG ratio) a custom text color may have against the button color. */
        'min_contrast' => 3,
        /* Button color of a channel whose client has not chosen one (Web uses the Workspace's main color). */
        'default_colors' => ['whatsapp' => '#128C7E'],
    ],

    /* Human attention: longest message an agent can send. */
    'agent_message_max' => 4096,

    /* Requests per minute an automation may make with one chatbot token (every message it reports is one). */
    'agent_per_minute' => 600,

    /*
     * D-FALLO: the ONLY text Ava sends to a contact when the AI fails for good and the conversation is handed to a person. It is empty by default
     * (nothing is sent) and must be a text the business has approved: Ava invents none, and it must not promise a time or confirm anything. One message
     * per failure, never repeated by a retry or a recovery.
     */
    'failure_notice' => env('CHATBOT_FAILURE_NOTICE'),

    /* Longest text of the instructions of a chatbot, in characters. */
    'instructions_max' => 8000,

    /*
     * Rules of honesty that travel with EVERY execution and with the configuration an automation reads (they are not a prompt Ava runs: Ava does not
     * execute models, the workflow does). They say what the assistant may not do and what Ava does not do yet, so it neither invents nor promises.
     * Ava enforces what it can on its own side (prices, totals, availability, states, permissions); these lines cover what only the model can break.
     */
    'guidelines' => [
        'Usa solo datos que Ava te devuelva en esta conversación (precios, horarios, disponibilidad, sedes, productos, estados de citas y pedidos). Nunca los inventes ni los recuerdes de memoria.',
        'Confirma una cita o un pedido únicamente cuando la herramienta responda con éxito, y dile al contacto el estado exacto que devuelve Ava («por confirmar» no es «confirmada»).',
        'Si una herramienta falla, responde «uncertain» o no está disponible, díselo al contacto tal cual y no repitas la operación por tu cuenta.',
        'Nunca envíes precios, totales, moneda, número de pedido ni estados a Ava: solo qué productos y cuántos. El total lo calcula Ava.',
        'No prometas lo que Ava no hace hoy: no avisa al cliente de los cambios de un pedido, no valida cobertura ni costo de envío, no controla inventario y no procesa pagos.',
        'No canceles ni cambies el estado de un pedido por tu cuenta: eso lo decide el negocio. Si el contacto lo pide, transfiere a una persona.',
        'Si falta un dato, el contacto lo pide o no sabes cómo continuar, transfiere a una persona (handoff) en lugar de adivinar.',
        'No pidas ni escribas datos de salud, diagnósticos ni otra información sensible: no son necesarios para atender.',
    ],

    /*
     * The business tools an assistant can run through Ava (route name without "agent."), how the trace (agent_tool_calls) names them and whether they only
     * read or change something. The labels name the REQUEST, never its result: the result is the `status` of the trace.
     */
    'tools' => [
        'booking.services' => ['label' => 'Consulta de servicios', 'kind' => 'read'],
        'booking.professionals' => ['label' => 'Consulta de profesionales', 'kind' => 'read'],
        'booking.locations' => ['label' => 'Consulta de sedes', 'kind' => 'read'],
        'booking.availability' => ['label' => 'Consulta de disponibilidad', 'kind' => 'read'],
        'booking.appointments' => ['label' => 'Consulta de sus citas', 'kind' => 'read'],
        'booking.book' => ['label' => 'Reserva de una cita', 'kind' => 'write'],
        'booking.cancel' => ['label' => 'Cancelación de una cita', 'kind' => 'write'],
        'booking.confirm' => ['label' => 'Confirmación del cliente', 'kind' => 'write'],
        'booking.reschedule' => ['label' => 'Reprogramación de una cita', 'kind' => 'write'],
        'orders.options' => ['label' => 'Consulta de opciones de pedido', 'kind' => 'read'],
        'orders.products' => ['label' => 'Consulta del catálogo', 'kind' => 'read'],
        'orders.index' => ['label' => 'Consulta de sus pedidos', 'kind' => 'read'],
        'orders.store' => ['label' => 'Creación de un pedido', 'kind' => 'write'],
    ],

    /* How the interface names the status of a traced call. `started` is also what stays when the process ended before the outcome could be written. */
    'tool_statuses' => ['started' => 'Sin resultado registrado', 'succeeded' => 'Hecho', 'rejected' => 'Rechazado', 'failed' => 'Falló', 'uncertain' => 'Resultado incierto'],

    /* Avatar of a chatbot: same formats and weight limit as the Workspace logo (never SVG). */
    'avatar' => [
        'mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'max_kb' => 1024,
    ],
];
