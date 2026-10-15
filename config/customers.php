<?php

/*
 * Clientes: the customer record of a Workspace. Only what Ava really needs to attend, contact and (with consent) write to someone.
 * Not a CRM: no pipeline, deals or activity feed. Conversations and appointments are NOT copied here, they are read from their own modules.
 */
return [
    /* How the record came to exist. Only `manual` is written by the interface today; the others are reserved for the flows that will create them. */
    'sources' => [
        'manual' => 'Alta manual',
        'conversation' => 'Conversación',
        'booking' => 'Reserva',
        'import' => 'Importación',
    ],

    /*
     * Consent to receive marketing messages. `unknown` is the default and means NO: a campaign only reaches `granted`.
     * `revoked` is an opt-out and always wins until a person records a new consent.
     */
    'consents' => [
        'unknown' => 'Sin registrar',
        'granted' => 'Autorizado',
        'revoked' => 'Dado de baja',
    ],

    /* Channels a customer can be identified on (keys of config/chatbots.php `channels`). */
    'identity_channels' => ['whatsapp', 'web', 'instagram', 'messenger'],

    'max_tags' => 10,
];
