<?php

/*
 * Auditoría: the administrative modules whose changes are recorded. `label` is the module shown in the UI and the
 * PDF, `noun` completes the sentence of the event ("Modificó el usuario ..."). Only actions that change data or
 * configuration are recorded, never reads. The PDF includes at most `pdf_max_events` events.
 */
return [
    'resources' => [
        'user' => ['label' => 'Usuarios', 'noun' => 'el usuario'],
        'membership' => ['label' => 'Miembros', 'noun' => 'la membresía de'],
        'role' => ['label' => 'Roles', 'noun' => 'el rol'],
        'workspace' => ['label' => 'Workspaces', 'noun' => 'el Workspace'],
        'organization' => ['label' => 'Organizaciones', 'noun' => 'la organización'],
        'settings' => ['label' => 'Configuraciones', 'noun' => 'la configuración del Workspace'],
        'integration' => ['label' => 'Integraciones', 'noun' => 'la integración'],
        'chatbot' => ['label' => 'Chatbots', 'noun' => 'el chatbot'],
        'chatbot_channel' => ['label' => 'Canales de chatbots', 'noun' => 'el canal'],
        'chatbot_channel_appearance' => ['label' => 'Apariencia de canales', 'noun' => 'la apariencia del canal'],
        'conversation' => ['label' => 'Conversaciones', 'noun' => 'la conversación'],
        'chatbot_execution' => ['label' => 'Ejecuciones de asistentes', 'noun' => 'el workflow del asistente'],
    ],

    'actions' => [
        'created' => ['label' => 'Creado', 'verb' => 'Creó'],
        'updated' => ['label' => 'Modificado', 'verb' => 'Modificó'],
        'deleted' => ['label' => 'Eliminado', 'verb' => 'Eliminó'],
        'taken' => ['label' => 'Tomado', 'verb' => 'Tomó'],
        'assigned' => ['label' => 'Asignado', 'verb' => 'Asignó'],
        'returned' => ['label' => 'Devuelto a la IA', 'verb' => 'Devolvió a la IA'],
        'resolved' => ['label' => 'Resuelto', 'verb' => 'Resolvió'],
        'tested' => ['label' => 'Probado', 'verb' => 'Probó'],
        'requested' => ['label' => 'Persona pedida', 'verb' => 'Pidió una persona para'],
        'reopened' => ['label' => 'Reabierto', 'verb' => 'Reabrió'],
        'executed' => ['label' => 'Ejecutado', 'verb' => 'Ejecutó'],
    ],

    'pdf_max_events' => 1000,
];
