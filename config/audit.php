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
        'plan' => ['label' => 'Planes', 'noun' => 'el plan'],
        'workspace_modules' => ['label' => 'Módulos del Workspace', 'noun' => 'los módulos de'],
        'workspace_limits' => ['label' => 'Límites del Workspace', 'noun' => 'los límites de'],
        'ai_execution' => ['label' => 'Ejecuciones de IA', 'noun' => 'una ejecución de IA de'],
        'subscription' => ['label' => 'Suscripciones', 'noun' => 'la suscripción de'],
        'module' => ['label' => 'Catálogo de módulos', 'noun' => 'el módulo'],
        'module_category' => ['label' => 'Categorías de módulos', 'noun' => 'la categoría de módulos'],
        'settings' => ['label' => 'Configuraciones', 'noun' => 'la configuración del Workspace'],
        'integration' => ['label' => 'Integraciones', 'noun' => 'la integración'],
        'service' => ['label' => 'Servicios', 'noun' => 'el servicio'],
        'professional' => ['label' => 'Profesionales', 'noun' => 'el profesional'],
        'location' => ['label' => 'Sedes', 'noun' => 'la sede'],
        'product' => ['label' => 'Catálogo de productos', 'noun' => 'el producto'],
        'product_category' => ['label' => 'Categorías de productos', 'noun' => 'la categoría de productos'],
        'order' => ['label' => 'Pedidos', 'noun' => 'el pedido'],
        'bookable_resource' => ['label' => 'Recursos de reserva', 'noun' => 'el recurso'],
        'schedule_exception' => ['label' => 'Excepciones de horario', 'noun' => 'la excepción de horario'],
        'appointment' => ['label' => 'Citas', 'noun' => 'la cita'],
        'booking_settings' => ['label' => 'Configuración de reservas', 'noun' => 'la configuración de reservas'],
        'customer' => ['label' => 'Clientes', 'noun' => 'el cliente'],
        'invitation' => ['label' => 'Invitaciones a Workspaces', 'noun' => 'la invitación'],
        'customer_tag' => ['label' => 'Etiquetas de clientes', 'noun' => 'la etiqueta de clientes'],
        'support_access' => ['label' => 'Acceso de soporte', 'noun' => 'el acceso de soporte a'],
        'message_template' => ['label' => 'Plantillas de mensaje', 'noun' => 'la plantilla'],
        'campaign' => ['label' => 'Campañas', 'noun' => 'la campaña'],
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
        'accessed' => ['label' => 'Consultado', 'verb' => 'Consultó'],
        'anonymized' => ['label' => 'Anonimizado', 'verb' => 'Anonimizó'],
        'prepared' => ['label' => 'Preparado', 'verb' => 'Preparó'],
        'simulated' => ['label' => 'Simulado', 'verb' => 'Simuló'],
        'cancelled' => ['label' => 'Cancelado', 'verb' => 'Canceló'],
        'executed' => ['label' => 'Ejecutado', 'verb' => 'Ejecutó'],
    ],

    'pdf_max_events' => 1000,
];
