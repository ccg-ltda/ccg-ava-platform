<?php

/*
 * Panel «Ayuda» of the top bar. Everything the panel shows comes from here, so support texts and manuals are edited in ONE place and never in a component.
 *
 * `faq`      groups of questions. Each item: `q`, `a`, optional `steps` (what to try, in order) and `avoid` (what NOT to do). These texts describe how the
 *            application really behaves today; they are a DRAFT written by development, not approved support documentation: review them before publishing.
 * `manuals`  PDF manuals. Put the file in resources/manuals/ and add an entry: `key` (url-safe, unique), `title`, `description`, `version`, `module` (a module key of
 *            config/modules.php `implemented`, or null for every Workspace) and `file` (name inside resources/manuals/). An entry whose file does not exist is
 *            NOT shown, and one whose module is not in the Workspace plan is hidden for it. There are no official manuals yet: the list is empty on purpose.
 * The support e-mail is `config('branding.support_email')` (BRAND_SUPPORT_EMAIL, else LEGAL_CONTACT_EMAIL); without it the panel says it is pending, nothing is made up.
 */
return [
    'manuals' => [
        // ['key' => 'primeros-pasos', 'title' => 'Primeros pasos', 'description' => '...', 'version' => '1.0', 'module' => null, 'file' => 'primeros-pasos.pdf'],
    ],

    'faq' => [
        ['group' => 'Acceso', 'items' => [
            [
                'q' => 'No puedo iniciar sesión',
                'a' => 'Por seguridad el mensaje de error es siempre el mismo, exista o no la cuenta: no dice si falló el correo, la contraseña o el Workspace.',
                'steps' => ['Revisa el código del Workspace (el que te dio tu administrador) y que el correo no tenga espacios ni errores.', 'Usa «¿Olvidó su contraseña?» para elegir una nueva.', 'Si sigue fallando, pide a un administrador de tu Workspace que confirme que tu cuenta está activa y que perteneces a él.'],
                'avoid' => ['No pruebes contraseñas al azar: los intentos repetidos se bloquean un rato.'],
            ],
            [
                'q' => 'No me llegó el correo (invitación o recuperación de contraseña)',
                'a' => 'Los enlaces caducan y sirven una sola vez. El correo puede tardar unos minutos o haber caído en spam.',
                'steps' => ['Espera unos minutos y revisa spam y promociones.', 'Confirma con quien te invitó que escribió bien tu correo.', 'Pide que te reenvíen la invitación (el enlace anterior deja de funcionar) o repite «¿Olvidó su contraseña?» una sola vez.'],
                'avoid' => ['No pidas el enlace muchas veces seguidas: cada solicitud invalida el anterior.'],
            ],
            [
                'q' => 'El enlace de mi invitación dice que no es válido',
                'a' => 'La invitación caduca, se usa una sola vez y se puede cancelar. Un enlace caducado, ya usado o cancelado muestra siempre el mismo aviso.',
                'steps' => ['Pide a quien te invitó una invitación nueva.', 'Si ya creaste tu cuenta, entra por el acceso normal con el código del Workspace.'],
                'avoid' => [],
            ],
        ]],
        ['group' => 'Módulos y permisos', 'items' => [
            [
                'q' => 'No veo un módulo en el menú',
                'a' => 'El menú solo muestra lo que tu Workspace tiene contratado en su plan y lo que tu rol permite. Son dos condiciones distintas.',
                'steps' => ['Si el módulo no está en tu plan, el aviso dirá que el Workspace no tiene acceso según su plan: contacta a soporte para ampliarlo.', 'Si el módulo está en tu plan pero tu rol no tiene permiso, el aviso lo dirá: pide a un administrador de tu Workspace que ajuste tu rol.'],
                'avoid' => ['Escribir la dirección a mano no abre un módulo que no tienes: el servidor lo rechaza.'],
            ],
            [
                'q' => 'No veo los datos de otra sede',
                'a' => 'Un usuario puede estar limitado a algunas sedes; solo ve y modifica lo que pertenece a ellas.',
                'steps' => ['Pide a un administrador que revise las sedes asignadas a tu usuario.'],
                'avoid' => [],
            ],
        ]],
        ['group' => 'Pantallas y datos', 'items' => [
            [
                'q' => 'Una pantalla se queda en blanco',
                'a' => 'Suele ser una versión antigua de la página guardada en el navegador.',
                'steps' => ['Recarga con Ctrl+F5 (Cmd+Shift+R en Mac).', 'Prueba en una ventana privada.', 'Si persiste, escribe a soporte con la pantalla, la hora aproximada y una captura.'],
                'avoid' => ['No repitas la acción varias veces si dudas de que se guardó: revisa primero el listado.'],
            ],
            [
                'q' => 'La imagen de un producto no se ve',
                'a' => 'La imagen guardada ya no está disponible en el almacenamiento (el icono aparece en rojo).',
                'steps' => ['Edita el producto y sube la imagen de nuevo.'],
                'avoid' => [],
            ],
        ]],
        ['group' => 'WhatsApp y asistentes', 'items' => [
            [
                'q' => 'WhatsApp aparece «Verificación en curso» o no llegan mensajes',
                'a' => 'La conexión solo se da por buena cuando se comprueban las credenciales, el webhook, la verificación de Meta y que llegó un mensaje real.',
                'steps' => ['En Integraciones abre «Webhook y estado de la conexión» y revisa qué etapa está pendiente.', 'Pega la URL del webhook en Meta y suscribe el campo «messages».', 'Envía un mensaje de prueba al número.'],
                'avoid' => ['No compartas el token de acceso ni el secreto de la app por correo o chat.'],
            ],
        ]],
        ['group' => 'Buenas prácticas', 'items' => [
            [
                'q' => '¿Qué no debo hacer nunca?',
                'a' => 'Algunas acciones comprometen tu cuenta o la de tu equipo.',
                'steps' => [],
                'avoid' => ['Compartir tu contraseña, enlaces de invitación o tokens.', 'Dar a alguien un rol con más permisos de los que necesita.', 'Desactivar usuarios o integraciones sin avisar al equipo.'],
            ],
        ]],
    ],
];
