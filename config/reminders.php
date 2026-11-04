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
        // Rules (automatic reminders by service): why a reminder was cancelled or an appointment was not scheduled.
        'rule_inactive' => 'La regla que lo programó está desactivada.',
        'appointment_changed' => 'La cita ya no figura como realizada.',
        'rule_changed' => 'La regla cambió y esta cita ya no le corresponde.',
        'no_customer' => 'La persona de la cita no tiene una ficha en Clientes.',
        'ambiguous_customer' => 'El teléfono y el correo de la cita pertenecen a fichas distintas de Clientes.',
        'too_late' => 'Llegó tarde: la fecha de envío ya había pasado por más tiempo del permitido.',
        'invalid' => 'No se pudo programar con los datos actuales.',
    ],

    // Automatic reminders by service (`reminder_rules`): «N days after a customer had service X, remind them».
    'rules' => [
        'delay_units' => ['hours' => 'Horas', 'days' => 'Días', 'weeks' => 'Semanas'],
        // Longest wait a rule can ask for, in days (a reminder can not be scheduled further ahead than `limits.max_days_ahead`).
        'max_delay_days' => 360,
        // Hour of the day a reminder in days or weeks goes out (Workspace time zone) when the rule does not choose one.
        'default_send_time' => '10:00',
        // How long after its intended moment a reminder may still go out (the scheduler was off, the Workspace had no channel). Later than this it is skipped, never sent out of context.
        'grace_hours' => 48,
        // Appointments a single scan evaluates per rule (the rest wait for the next run).
        'scan_batch' => 500,
        'max_per_workspace' => 50,
        'name_max' => 120,
        // A skipped appointment is evaluated again while one of these reasons holds (consent can be granted, a card can be created, a channel connected).
        'retry_reasons' => ['no_customer', 'ambiguous_customer', 'no_consent', 'channel_unavailable', 'no_email', 'no_valid_phone'],
    ],

    'channels' => [
        'email' => ['label' => 'Correo electrónico', 'description' => 'Canal base. Usa el correo de la plataforma.'],
        'whatsapp' => ['label' => 'WhatsApp', 'description' => 'Con una plantilla aprobada por Meta (un seguimiento siempre cae fuera de la ventana de 24 h).'],
    ],

    // What a reminder tied to an APPOINTMENT (those an automatic rule schedules) can also say, besides the customer's data (config/campaigns.php): resolved from that appointment when it goes out.
    'appointment_variables' => ['servicio' => 'Servicio', 'profesional' => 'Profesional', 'fecha' => 'Fecha de la cita', 'hora' => 'Hora de la cita', 'sede' => 'Sede'],

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
        // Customers one manual send may address (each gets its own message).
        'max_recipients' => 50,
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
