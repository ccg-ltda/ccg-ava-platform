<?php

/**
 * Conditions for the AI features: DRAFT for CCG's legal review (full text in docs/legal/10-condiciones-uso-ia.md). It does not promise results Ava cannot guarantee:
 * Ava runs no model of its own, the assistant behaves as the organization configured it.
 */
return [
    'intro' => [
        'Estas condiciones explican cómo funcionan los asistentes de inteligencia artificial de Zonno Platform by CCG y qué responsabilidades tiene cada parte. Prestador: {{legal_name}} (NIT {{tax_id}}). Vigencia de esta versión: {{effective_date}}.',
    ],
    'sections' => [
        [
            'heading' => '1. Qué hace la IA',
            'paragraphs' => ['La organización conecta un asistente (hoy un flujo de n8n con un modelo de lenguaje de un proveedor externo) que responde mensajes, consulta reservas, pedidos o catálogo con herramientas autorizadas y puede pasar la conversación a una persona. La plataforma no ejecuta un modelo propio: el asistente actúa según las instrucciones de la organización.'],
        ],
        [
            'heading' => '2. Limitaciones',
            'items' => [
                'Las respuestas pueden ser incorrectas o incompletas y no son asesoría médica, legal, financiera ni profesional.',
                'Lo que hace el asistente queda registrado en la actividad técnica de la conversación.',
                'Si el asistente falla o no puede resolver, la conversación pasa a una persona o queda pendiente.',
            ],
        ],
        [
            'heading' => '3. Transparencia e intervención humana',
            'paragraphs' => ['La organización debe informar a las personas que hablan con un asistente de IA y ofrecer hablar con una persona. Las decisiones con efectos jurídicos o patrimoniales importantes no deben tomarse únicamente por el asistente.'],
        ],
        [
            'heading' => '4. Datos y proveedores',
            'paragraphs' => ['El contenido de la conversación se envía al proveedor del modelo solo en la medida necesaria para responder. Ese proveedor es un subencargado. No se usan las conversaciones para entrenar modelos propios de CCG. Lista de proveedores: [POR CONFIRMAR por CCG].'],
        ],
        [
            'heading' => '5. Responsabilidades',
            'paragraphs' => ['La organización responde por las instrucciones que da al asistente y por lo que este envía en su nombre; CCG responde por el funcionamiento de la plataforma conforme a los términos. Nada aquí limita derechos irrenunciables de los titulares de datos ni de los consumidores.'],
        ],
    ],
];
