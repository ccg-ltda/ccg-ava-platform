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

    'public' => [
        'max_days' => 14,
        /* A visitor (identified by phone or email) cannot hold more future appointments than this through the open channels. */
        'max_active_per_contact' => 5,
        'config_per_minute' => 60,
        'availability_per_minute' => 40,
        'write_per_minute' => 6,
    ],
];
