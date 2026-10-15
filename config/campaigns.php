<?php

/*
 * Campañas: reusable message templates and the campaigns addressed to customers.
 * A campaign lives through: draft -> prepared (recipients fixed and checked) -> simulated (a dry run: nothing leaves Ava) or cancelled.
 * `sent` does NOT exist: no outbound channel for campaigns is connected (see App\Campaigns\CampaignDispatcher), so no campaign is ever
 * marked as sent. Do not add the status before a real, tested dispatcher exists.
 */
return [
    'statuses' => [
        'draft' => 'Borrador',
        'prepared' => 'Preparada',
        'simulated' => 'Simulada',
        'cancelled' => 'Cancelada',
    ],

    /* Channels a campaign can be addressed through, and the customer data each one needs. */
    'channels' => [
        'whatsapp' => ['label' => 'WhatsApp', 'requires' => 'phone'],
        'email' => ['label' => 'Correo', 'requires' => 'email'],
    ],

    /* Variables a template may use as {{key}}: `field` is the customer attribute that fills it. */
    'variables' => [
        'nombre' => ['label' => 'Nombre', 'field' => 'first_name'],
        'apellido' => ['label' => 'Apellidos', 'field' => 'last_name'],
        'nombre_completo' => ['label' => 'Nombre completo', 'field' => 'full_name'],
        'telefono' => ['label' => 'Teléfono', 'field' => 'phone'],
        'correo' => ['label' => 'Correo electrónico', 'field' => 'email'],
    ],

    /* Why a customer is left out of a campaign. */
    'exclusions' => [
        'inactive' => 'Cliente inactivo',
        'opted_out' => 'Se dio de baja',
        'no_consent' => 'Sin consentimiento registrado',
        'no_contact' => 'Sin dato de contacto para el canal',
        'missing_variable' => 'Falta un dato que usa el mensaje',
    ],

    'max_selected' => 2000,

    /*
     * Length of a message body, in Unicode characters (code points: an emoji or an accented letter is ONE, a line break is ONE, and the
     * {{variable}} markers count as written, not as the value they will be replaced by). 1024 is the limit of the BODY component of a
     * WhatsApp Business Platform template (Meta, "Template components"; verified 2026-10). Header (60), footer (60) and buttons are components
     * Ava's templates do not have, so they have no limit here: add them together with the component. Meta's own way of counting (code
     * points vs UTF-16 units) is not documented; the count here is the stricter one for text without astral characters and the same
     * one used by the server (`MessageTemplates::length`) and the editor (`resources/js/config/messageLimits.js`).
     */
    'body_max' => 1024,
];
