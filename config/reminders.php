<?php

/*
 * Recordatorios (module `reminders`; RECOMENDACIONES.md §79.2). Product limits, changed by a reviewed edit (nothing is read from the environment).
 *
 * Not the confirmations and reminders of an appointment: those belong to Agenda (`config/bookings.php` `notifications`, App\Booking\AppointmentNotifier) and are untouched.
 * This module schedules COMMERCIAL follow-ups to customers («invite them to book again 15 days after the service»).
 */
return [
    'statuses' => [
        'scheduled' => 'Programado',
        'processing' => 'Enviando',
        'sent' => 'Enviado',
        'failed' => 'Falló',
        'cancelled' => 'Cancelado',
        'skipped' => 'Omitido',
        'uncertain' => 'Sin confirmar',
    ],

    // Fixed reasons a person reads (the database only stores the code).
    'reasons' => [
        'plan' => 'El plan del Workspace ya no incluye Recordatorios.',
        'customer_unavailable' => 'El cliente ya no está disponible (desactivado, anonimizado o eliminado).',
        'no_consent' => 'El cliente no tiene el consentimiento para recibir mensajes.',
        'channel_unavailable' => 'El canal ya no está disponible en este Workspace.',
        'no_email' => 'El cliente no tiene correo.',
        'no_valid_phone' => 'El cliente no tiene un teléfono válido.',
        'appointment_closed' => 'La cita relacionada ya no está vigente.',
        'order_closed' => 'El pedido relacionado ya está cerrado.',
        'cancelled_by_user' => 'Cancelado por una persona.',
        'interrupted' => 'Se interrumpió mientras se enviaba: pudo haberse enviado. No se reintenta solo.',
        'send_failed' => 'No se pudo enviar.',
        'template_missing' => 'Falta la plantilla aprobada de WhatsApp.',
    ],

    'channels' => [
        'email' => ['label' => 'Correo electrónico', 'description' => 'Canal base. Usa el correo de la plataforma.'],
        'whatsapp' => ['label' => 'WhatsApp', 'description' => 'Con una plantilla aprobada por Meta (un seguimiento siempre cae fuera de la ventana de 24 h).'],
    ],

    'related_types' => ['appointment' => 'Cita', 'order' => 'Pedido', 'service' => 'Servicio'],

    'limits' => [
        'name_max' => 120,
        'purpose_max' => 255,
        'subject_max' => 150,
        'body_max' => 1500,
        // Earliest a reminder may be scheduled (minutes from now) and how far ahead.
        'min_lead_minutes' => 2,
        'max_days_ahead' => 365,
        // Scheduled reminders a Workspace can hold at once (a runaway automation can not fill the table).
        'max_scheduled' => 5000,
        'page_size' => 15,
    ],

    'retries' => [
        'max_attempts' => 4,
        // Seconds to wait before attempt 2, 3 and 4. Only for a failure where NOTHING went out and trying again can work.
        'backoff_seconds' => [300, 1800, 7200],
        // A `processing` reminder untouched this long is closed as «uncertain» (never re-sent automatically).
        'stale_processing_minutes' => 10,
    ],

    // Whether a customer must have granted marketing consent. Follow-ups are promotional invitations: the same rule as Campañas.
    'require_consent' => true,

    'dispatch_batch' => 200,
];
