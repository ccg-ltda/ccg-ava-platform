<?php

/*
 * Privacy controls of Ava Platform (technical controls only; the legal policy is a proposal in docs/privacidad.md and needs legal review).
 *
 * Two kinds of data, kept apart:
 *  - ADMINISTRATIVE data (Workspaces, plans, modules, roles, states, counts): platform administrators manage it without any grant.
 *  - PRIVATE data of the final contacts of a client (the content of their conversations, who they are): a platform administrator
 *    never sees it from another Workspace unless a person with `access-private-data` opens a SUPPORT ACCESS: one Workspace, one kind
 *    of data, a written reason, a short life. Being superuser does not grant it. Every grant and every read is audited in the
 *    audited Workspace (who, when, what kind, why) without ever storing the private content itself.
 */
return [
    'support_access' => [
        /* How long a grant lasts (minutes). A technical default pending a decision (docs/privacidad.md); never indefinite. */
        'minutes' => 30,

        'reason_min' => 15,

        /* What a grant can open. Only conversations exist as private content shown from another Workspace today. */
        'types' => [
            'conversations' => 'Conversaciones y mensajes',
        ],
    ],

    /* Audit resources whose records identify a final contact: their audit rows carry "#id" instead of a name or number. */
    'private_audit_resources' => ['conversation', 'customer'],
];
