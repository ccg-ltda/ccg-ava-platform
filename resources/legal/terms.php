<?php

/**
 * Terms of use of the platform: DRAFT for CCG's legal review. {{placeholders}} are filled from config/legal.php; an empty
 * value is shown as "[POR CONFIRMAR: ...]". Nothing here promises availability, results or liability limits that CCG has not confirmed.
 */
return [
    'intro' => [
        'Estos términos regulan el uso de la plataforma Zonno Platform by CCG por las personas que reciben una cuenta para trabajar en el Workspace de una organización. Prestador del servicio: {{legal_name}}, identificación {{tax_id}}, domicilio {{address}}.',
        'Vigencia de esta versión: {{effective_date}}.',
    ],
    'sections' => [
        [
            'heading' => '1. Qué es el servicio',
            'paragraphs' => [
                'Zonno Platform es una herramienta para que una organización administre asistentes conversacionales, sus canales (por ejemplo WhatsApp o el chat de su sitio web), las conversaciones, las reservas, los clientes y otros módulos que su plan incluya. Cada organización trabaja en su propio Workspace, aislado del de las demás.',
                'Qué módulos y funciones están disponibles depende del plan de cada Workspace y de los permisos de cada rol.',
            ],
        ],
        [
            'heading' => '2. Cuentas y acceso',
            'items' => [
                'Las cuentas las crea y administra el administrador del Workspace; no existe el registro público.',
                'Tu cuenta es personal. Guarda tu contraseña, no la compartas y cámbiala si sospechas que otra persona la conoce.',
                'El administrador puede asignar, cambiar o retirar tu rol y desactivar tu cuenta. Una cuenta desactivada no puede entrar.',
                'Debes avisar de inmediato al administrador de tu Workspace o a CCG si detectas un acceso no autorizado.',
            ],
        ],
        [
            'heading' => '3. Uso permitido',
            'paragraphs' => ['Te comprometes a usar la plataforma solo para las funciones de tu trabajo y de acuerdo con la ley. No está permitido:'],
            'items' => [
                'Intentar acceder a datos de otro Workspace, a funciones que tu rol no tiene o a cuentas ajenas.',
                'Eludir los controles de seguridad, límites de uso o medidas de aislamiento.',
                'Usar la plataforma para enviar mensajes no solicitados, engañosos o contrarios a las reglas del canal (por ejemplo, las políticas de WhatsApp de Meta).',
                'Cargar contenido ilícito o datos personales que no tengas derecho a tratar.',
            ],
        ],
        [
            'heading' => '4. Datos personales',
            'paragraphs' => [
                'El tratamiento de los datos de tu cuenta se describe en el Aviso de privacidad y en la Política de tratamiento de datos personales. Los datos de las personas que escriben a los asistentes de una organización los trata esa organización como responsable; CCG los procesa por su cuenta para prestar el servicio. El reparto exacto de responsabilidades queda fijado en el contrato con cada organización.',
            ],
        ],
        [
            'heading' => '5. Servicios de terceros',
            'paragraphs' => [
                'La plataforma puede conectarse con servicios de terceros que la organización decide usar (entre otros, Meta/WhatsApp, un servidor de automatización n8n o un sistema de agenda como Booker). Esos servicios tienen sus propias condiciones y CCG no los controla: su disponibilidad, cambios o fallos pueden afectar a las funciones que dependen de ellos.',
            ],
        ],
        [
            'heading' => '6. Disponibilidad y soporte',
            'paragraphs' => [
                'Niveles de disponibilidad, horarios de soporte y tiempos de respuesta: [POR CONFIRMAR por CCG]. Mientras no se confirmen, este texto no ofrece garantías de disponibilidad continua.',
            ],
        ],
        [
            'heading' => '7. Propiedad intelectual',
            'paragraphs' => [
                'La plataforma, su código, diseño y marcas pertenecen a CCG o a sus licenciantes. El contenido y los datos que cada organización carga en su Workspace siguen siendo de esa organización. Alcance de las licencias y derechos de uso: [POR CONFIRMAR por CCG].',
            ],
        ],
        [
            'heading' => '8. Responsabilidad',
            'paragraphs' => [
                'Alcance y límites de la responsabilidad de CCG frente a la organización y a las personas usuarias: [POR CONFIRMAR por CCG y su asesoría jurídica]. Las respuestas que generan los asistentes automatizados proceden del flujo de trabajo que configura cada organización; revisarlas es responsabilidad de quien las configura.',
            ],
        ],
        [
            'heading' => '9. Cambios en estos términos',
            'paragraphs' => [
                'Cada texto tiene una versión. Si cambia, se te pedirá aceptar la versión nueva antes de seguir usando la plataforma y se conservará el registro de las versiones que aceptaste.',
            ],
        ],
        [
            'heading' => '10. Ley aplicable y contacto',
            'paragraphs' => [
                'Jurisdicción y normativa aplicable: {{jurisdiction}}. Contacto: {{contact_email}}.',
            ],
        ],
    ],
];
