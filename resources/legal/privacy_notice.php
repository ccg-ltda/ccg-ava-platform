<?php

/**
 * Privacy notice for the people who sign in to the platform: DRAFT for CCG's legal review. It describes only what the platform
 * really does today (see docs/privacidad.md); retention periods, legal basis and the company data are left as "[POR CONFIRMAR]".
 */
return [
    'intro' => [
        'Este aviso explica qué datos personales trata la plataforma Zonno Platform by CCG sobre las personas que tienen una cuenta, para qué los usa y cómo puedes ejercer tus derechos. Responsable del tratamiento de los datos de las cuentas: {{legal_name}}, identificación {{tax_id}}, domicilio {{address}}.',
        'Vigencia de esta versión: {{effective_date}}.',
    ],
    'sections' => [
        [
            'heading' => '1. Qué datos tratamos',
            'items' => [
                'Datos de la cuenta: nombre, correo electrónico y contraseña (se guarda cifrada de forma irreversible; nadie puede verla).',
                'Pertenencia y rol: a qué Workspaces perteneces y qué rol tienes en cada uno.',
                'Actividad administrativa: un registro de auditoría con los cambios que haces, la fecha y tu dirección IP.',
                'Datos técnicos de la sesión: cookies necesarias para mantener tu sesión abierta y proteger los formularios; si marcas «Recordar sesión», se recuerda tu correo y Workspace (cifrados; nunca la contraseña).',
                'Registro de las aceptaciones de textos legales (documento, versión, fecha e IP) y, aparte, tu decisión sobre comunicaciones comerciales.',
            ],
        ],
        [
            'heading' => '2. Para qué los usamos',
            'items' => [
                'Crear tu cuenta, identificarte y darte acceso solo a lo que tu rol permite.',
                'Proteger la seguridad de la plataforma y de los datos de cada Workspace, y poder revisar quién hizo qué.',
                'Enviarte correos del servicio, por ejemplo el enlace para restablecer tu contraseña.',
                'Cumplir obligaciones legales y atender tus solicitudes.',
            ],
            'paragraphs' => ['Base legal de cada finalidad: [POR CONFIRMAR por CCG y su asesoría jurídica].'],
        ],
        [
            'heading' => '3. Comunicaciones comerciales',
            'paragraphs' => [
                'Enviarte información comercial es una finalidad distinta y opcional: solo ocurre si la autorizas, por separado, y puedes retirar esa autorización cuando quieras desde tu perfil. No es necesaria para usar la plataforma ni se mezcla con la aceptación de los términos.',
            ],
        ],
        [
            'heading' => '4. Con quién se comparten',
            'paragraphs' => ['Para prestar el servicio intervienen proveedores tecnológicos (alojamiento, correo electrónico y, según lo que cada organización conecte, servicios como Meta/WhatsApp o n8n). Lista de proveedores y país de tratamiento: [POR CONFIRMAR por CCG].'],
            'items' => [
                'Las personas administradoras de tu Workspace ven tu nombre, correo y rol.',
                'CCG puede acceder a información de un Workspace cliente solo en las condiciones y con el registro que fija su política de acceso de soporte.',
            ],
        ],
        [
            'heading' => '5. Cuánto tiempo los conservamos',
            'paragraphs' => ['Plazos de conservación de la cuenta, el registro de auditoría y las copias de seguridad: [POR CONFIRMAR por CCG]. Mientras no se definan, estos datos se conservan mientras la cuenta exista.'],
        ],
        [
            'heading' => '6. Tus derechos',
            'paragraphs' => [
                'Puedes conocer, actualizar, rectificar y solicitar la supresión de tus datos, revocar autorizaciones y presentar reclamos ante la autoridad competente, en los términos de la normativa aplicable ({{jurisdiction}}).',
                'Para ejercerlos escribe a: {{privacy_contact}}. Algunos datos, como el registro de auditoría, pueden conservarse cuando la ley o la seguridad lo exijan.',
            ],
        ],
        [
            'heading' => '7. Seguridad',
            'paragraphs' => [
                'Aplicamos medidas técnicas como aislamiento de datos entre Workspaces, control de acceso por rol, contraseñas cifradas, credenciales de integraciones cifradas y límites ante intentos abusivos. Ninguna medida elimina todo riesgo; avísanos si detectas un problema.',
            ],
        ],
        [
            'heading' => '8. Más información',
            'paragraphs' => ['La Política de tratamiento de datos personales amplía este aviso. Datos de las personas que escriben a los asistentes de una organización: los trata esa organización como responsable (consulta su propio aviso).'],
        ],
    ],
];
