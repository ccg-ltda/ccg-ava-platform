<?php

/*
 * Outbound webhooks of a Workspace (Integraciones > Webhooks; RECOMENDACIONES.md §78). Ava POSTs a signed JSON event to the HTTPS address the Workspace registers.
 * Nothing here is read from the environment: these are product limits, changed by a reviewed edit.
 *
 * The catalog lists ONLY facts the code really produces, each at the single place that produces it (`origin`). Payloads carry identifiers, states and dates, never
 * names, phone numbers, e-mails, addresses, message text or notes: a consumer that needs more reads it in Ava.
 */
return [
    'api_version' => '2026-10',

    'events' => [
        'conversation.created' => ['group' => 'Conversaciones', 'label' => 'Conversación nueva', 'description' => 'Un contacto escribe por primera vez en un canal (se crea la conversación).', 'origin' => 'ConversationService::record', 'data' => ['conversation_id', 'channel', 'chatbot_id']],
        'message.received' => ['group' => 'Conversaciones', 'label' => 'Mensaje recibido', 'description' => 'Un contacto escribe un mensaje (WhatsApp, web o mensajes que n8n reporta a Ava). No incluye el texto.', 'origin' => 'ConversationService::record', 'data' => ['message_id', 'conversation_id', 'channel', 'type']],
        'conversation.handling_changed' => ['group' => 'Conversaciones', 'label' => 'Cambia quién atiende', 'description' => 'La IA pide una persona, una persona toma, asigna, devuelve a la IA o resuelve; o el contacto reabre una conversación.', 'origin' => 'ConversationControl::record', 'data' => ['conversation_id', 'action', 'handling', 'handling_version']],
        'appointment.created' => ['group' => 'Reservas', 'label' => 'Cita creada', 'description' => 'Se crea una cita en la agenda de Ava.', 'origin' => 'AppointmentService::book', 'data' => ['appointment_id', 'status', 'service_id', 'professional_id', 'location_id', 'starts_at', 'ends_at', 'channel']],
        'appointment.rescheduled' => ['group' => 'Reservas', 'label' => 'Cita reprogramada', 'description' => 'Se cambia la fecha u hora de una cita de la agenda de Ava.', 'origin' => 'AppointmentService::reschedule', 'data' => ['appointment_id', 'status', 'starts_at', 'ends_at']],
        'appointment.cancelled' => ['group' => 'Reservas', 'label' => 'Cita cancelada', 'description' => 'Se cancela una cita de la agenda de Ava.', 'origin' => 'AppointmentService::cancel', 'data' => ['appointment_id', 'status']],
        'appointment.status_changed' => ['group' => 'Reservas', 'label' => 'Cita confirmada, realizada o ausente', 'description' => 'El equipo confirma una cita o la marca como realizada o como inasistencia.', 'origin' => 'AppointmentService::transition', 'data' => ['appointment_id', 'status']],
        'order.created' => ['group' => 'Pedidos', 'label' => 'Pedido creado', 'description' => 'Se registra un pedido (por el equipo o por el asistente).', 'origin' => 'OrderService::create', 'data' => ['order_id', 'number', 'status', 'currency', 'total', 'delivery_method', 'location_id']],
        'order.status_changed' => ['group' => 'Pedidos', 'label' => 'Pedido cambia de estado', 'description' => 'Un pedido pasa a otro estado (confirmado, en preparación, cancelado…).', 'origin' => 'OrderService::transition', 'data' => ['order_id', 'number', 'status']],
    ],

    // Sent only by the "Enviar prueba" button; it can never be subscribed to.
    'test_event' => 'webhook.test',

    'limits' => [
        'max_endpoints' => 10,
        'name_max' => 80,
        'url_max' => 2048,
        'timeout_seconds' => 10,
        'max_payload_bytes' => 65536,
        // Waits before attempt 2, 3, 4, 5 and 6 (seconds). A delivery that still fails after the last one is abandoned.
        'backoff_seconds' => [60, 300, 1800, 7200, 21600],
        // An endpoint whose deliveries are ABANDONED this many times in a row is switched off (and the team is told); any success resets the count.
        'disable_after_failures' => 10,
        // After rotating the secret the previous one keeps signing a second header for this long, so the receiver can switch without losing events.
        'secret_grace_hours' => 24,
        'retention_days' => 14,
        'stale_sending_minutes' => 5,
        'tests_per_minute' => 6,
    ],
];
