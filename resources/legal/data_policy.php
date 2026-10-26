<?php

/**
 * Personal-data processing policy: DRAFT for CCG's legal review. It lays out roles, principles and the procedure for requests
 * as the platform works today; every legal commitment CCG has not confirmed stays as "[POR CONFIRMAR]".
 */
return [
    'intro' => [
        'Esta política describe cómo {{legal_name}} (identificación {{tax_id}}, domicilio {{address}}) trata los datos personales en la plataforma Zonno Platform by CCG. Normativa aplicable: {{jurisdiction}}.',
        'Vigencia de esta versión: {{effective_date}}.',
    ],
    'sections' => [
        [
            'heading' => '1. Alcance y roles',
            'items' => [
                'Datos de las cuentas del personal (nombre, correo, rol, actividad): CCG actúa como responsable.',
                'Datos de las personas que conversan con los asistentes, reservan citas o hacen pedidos en un Workspace: los decide y responde la organización dueña del Workspace (responsable); CCG los procesa por su cuenta y bajo sus instrucciones (encargado). El contrato con cada organización fija el detalle.',
            ],
            'paragraphs' => ['La distribución definitiva de roles y el contrato de tratamiento de datos: [POR CONFIRMAR por CCG y su asesoría jurídica].'],
        ],
        [
            'heading' => '2. Principios que aplica la plataforma',
            'items' => [
                'Finalidad: los datos se usan para prestar el servicio contratado y para nada más.',
                'Aislamiento: cada Workspace solo ve sus propios datos.',
                'Mínimo necesario: el asistente automatizado no recibe datos de contacto que no necesita; el registro de auditoría no guarda el contenido de los mensajes.',
                'Seguridad: contraseñas y credenciales cifradas, permisos por rol, límites ante abusos y registro de los cambios administrativos.',
                'Acceso excepcional de soporte: denegado por defecto; si se concede, es temporal, justificado por escrito y queda registrado en el Workspace del cliente.',
            ],
        ],
        [
            'heading' => '3. Encargados y proveedores',
            'paragraphs' => ['Intervienen proveedores de alojamiento, correo electrónico y, según lo que cada organización conecte, de mensajería (Meta/WhatsApp) y automatización (n8n). Lista de subencargados, país y garantías: [POR CONFIRMAR por CCG].'],
        ],
        [
            'heading' => '4. Derechos de los titulares y cómo ejercerlos',
            'paragraphs' => [
                'Toda persona puede conocer, actualizar, rectificar y solicitar la supresión de sus datos, y revocar su autorización, en los términos de la normativa aplicable.',
                'Cuenta del personal: escribe a {{privacy_contact}}. Contactos de una organización: dirige la solicitud a esa organización; CCG la apoya con las herramientas de la plataforma.',
                'Plazos de respuesta y procedimiento detallado: [POR CONFIRMAR por CCG].',
            ],
        ],
        [
            'heading' => '5. Conservación y supresión',
            'paragraphs' => ['Plazos de conservación por tipo de dato y procedimiento de supresión al terminar el servicio: [POR CONFIRMAR por CCG y cada organización].'],
        ],
        [
            'heading' => '6. Incidentes de seguridad',
            'paragraphs' => ['Procedimiento y plazos para notificar un incidente a las organizaciones y a la autoridad: [POR CONFIRMAR por CCG].'],
        ],
        [
            'heading' => '7. Comunicaciones comerciales',
            'paragraphs' => ['Solo se envían a quien las autorizó de forma separada y expresa; la autorización se puede retirar en cualquier momento desde el perfil.'],
        ],
        [
            'heading' => '8. Cambios',
            'paragraphs' => ['Esta política tiene versión. Los cambios se publican en esta página y, cuando afecten a lo que aceptaste, se te pedirá una nueva aceptación.'],
        ],
    ],
];
