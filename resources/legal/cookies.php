<?php

/**
 * Cookie policy of the platform: DRAFT for CCG's legal review (full text and inventory in docs/legal/04-politica-de-cookies.md). It lists only what the platform really
 * sets today; if a non-necessary cookie is ever added, a consent mechanism must exist BEFORE it is set.
 */
return [
    'intro' => [
        'Esta política explica qué cookies y tecnologías similares usa la plataforma Zonno Platform by CCG. Responsable: {{legal_name}} (NIT {{tax_id}}). Vigencia de esta versión: {{effective_date}}.',
    ],
    'sections' => [
        [
            'heading' => '1. Qué usamos hoy',
            'items' => [
                'Cookie de sesión: mantiene tu sesión iniciada (máximo 12 horas). Estrictamente necesaria.',
                'Cookie y token de seguridad (CSRF/XSRF): protegen los formularios. Estrictamente necesarias.',
                'Cookies de «Recordar sesión»: solo si marcas esa casilla; guardan, cifrados, tu correo y Workspace. Nunca la contraseña.',
                'Almacenamiento local del navegador: tu preferencia de modo claro u oscuro. No contiene datos personales.',
            ],
        ],
        [
            'heading' => '2. Lo que no usamos',
            'paragraphs' => ['La plataforma no usa cookies de analítica, publicidad ni seguimiento de terceros. Si algún día se incorporan, te pediremos tu decisión antes de instalarlas, con opciones de aceptar y rechazar igualmente visibles, y podrás cambiarla cuando quieras.'],
        ],
        [
            'heading' => '3. Consentimiento',
            'paragraphs' => ['Las cookies estrictamente necesarias no requieren consentimiento porque sin ellas el servicio que pides no funciona. «Recordar sesión» solo se activa si tú lo marcas.'],
        ],
        [
            'heading' => '4. Cómo controlarlas',
            'paragraphs' => ['Puedes borrar o bloquear cookies desde tu navegador; si bloqueas las necesarias no podrás iniciar sesión ni enviar formularios. El chat de un sitio web de una organización cliente puede guardar un identificador de visitante: de eso responde esa organización.'],
        ],
        [
            'heading' => '5. Contacto',
            'paragraphs' => ['Para preguntas: {{privacy_contact}}.'],
        ],
    ],
];
