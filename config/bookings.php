<?php

/*
 * Servicios y Reservas. `defaults` are the booking rules of a Workspace that never saved its own (`booking_settings`).
 * `providers` are the agendas that can be the source of truth: `internal` is Ava's own and needs nothing; the others are an
 * integration type (config/integrations.php) the Workspace connected, and need the module `calendar_providers`.
 * `public` limits the open endpoints (booking page and Ava Chat tools): how far ahead a search may look and how often a
 * visitor may ask.
 */
return [
    'defaults' => [
        'provider' => 'internal',
        'requires_confirmation' => false,
        'min_notice_minutes' => 60,
        'max_advance_days' => 60,
        'cancel_notice_hours' => 2,
        'slot_step_minutes' => 15,
        'require_phone' => true,
        'require_email' => false,
        'public_enabled' => false,
    ],

    'providers' => [
        'internal' => ['label' => 'Agenda de Ava', 'integration' => null],
        'booker' => ['label' => 'Booker', 'integration' => 'booker'],
    ],

    'steps' => [5, 10, 15, 20, 30, 60],

    /*
     * Confirmations and reminders (one capability shared by every solution that books). NOTHING here is a business rule of a solution: whether a
     * Workspace sends anything, how long before, with which text or Meta template, is its own configuration (`booking_settings`).
     *  - `kinds`: received (a booking waits for the business to accept it), confirmed (the business accepted it), reminder (before it starts).
     *  - `variables`: the only placeholders a text or a template parameter may use. None is clinical: name, service, professional, location, date, time, business.
     *  - `default_texts`: what a kind says when the Workspace wrote no text of its own.
     *  - `offsets`: how many reminders and how far ahead a Workspace may configure (minutes before the start).
     *  - `retries`: a send that PROVABLY did not leave Ava (no connection, rate limit) is retried after these seconds; an uncertain one never is.
     *  - `phone_policy_since`: appointments created from this date had their phone validated as international (+country); older ones are ambiguous
     *    digits and are never messaged (see docs: a local number must not be read as a foreign one).
     *  - `window_hours`: WhatsApp lets a free text go out only within this time of the contact's last message; otherwise only an approved template.
     */
    'notifications' => [
        'kinds' => ['received', 'confirmed', 'reminder'],
        'variables' => ['nombre', 'servicio', 'profesional', 'sede', 'fecha', 'hora', 'negocio'],
        'default_texts' => [
            'received' => 'Hola {{nombre}}, recibimos tu solicitud de cita de {{servicio}} para el {{fecha}} a las {{hora}}. Te avisaremos cuando {{negocio}} la confirme.',
            'confirmed' => 'Hola {{nombre}}, tu cita de {{servicio}} quedó confirmada para el {{fecha}} a las {{hora}}. {{negocio}}',
            'reminder' => 'Hola {{nombre}}, te recordamos tu cita de {{servicio}} el {{fecha}} a las {{hora}}. {{negocio}}',
        ],
        'text_max' => 1000,
        'offsets' => ['max_count' => 3, 'min' => 15, 'max' => 20160],
        'retries' => ['max_attempts' => 3, 'backoff' => [60, 300]],
        'phone_policy_since' => '2026-10-17',
        'window_hours' => 24,
        'stuck_minutes' => 10,
    ],

    'public' => [
        'max_days' => 14,
        /* A visitor (identified by phone or email) cannot hold more future appointments than this through the open channels. */
        'max_active_per_contact' => 5,
        'config_per_minute' => 60,
        'availability_per_minute' => 40,
        'write_per_minute' => 6,
    ],
];
