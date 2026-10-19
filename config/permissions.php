<?php

/*
 * Catalog of the permissions the system defines: what each one allows, in words a person can read, and the resource it belongs to.
 * It is documentation shown in Usuarios y roles (role editor, permission list, a user's effective permissions); it never grants
 * anything. The permissions themselves are created by RolesAndPermissionsSeeder from this file and enforced by the routes
 * (`workspace.permission:`) and by the controllers that offer an action; a test keeps this catalog, the seeder and the routes in step.
 * A permission only appears here when an action of the system uses it: none exists for an operation that does not.
 *
 * Every permission is INDEPENDENT: holding one never grants another (viewing is not editing, creating is not deleting). `view-*` opens
 * the list of a resource; the rest of its operations each have their own. The module a plan includes decides whether the resource exists
 * for a Workspace; the permission decides what each person may do with it. Neither replaces the other.
 *
 * `group` is the resource (see `groups`; its `list` is the permission that opens the page, which every other permission of the group needs on top of its own,
 * because each operation is done from that page); `module` (optional) is the module key of config/modules.php it belongs to, so the editor can tell
 * which module a Workspace needs for the permission to matter. `platform` marks the permissions that only operate inside the administrative
 * Workspace (Desarrollo_DEV): in a client Workspace the server ignores them, whatever the role.
 */
return [
    'groups' => [
        'general' => ['label' => 'General', 'description' => 'Dashboard, Reportes y datos del equipo en las cifras.'],
        'users' => ['label' => 'Usuarios y roles', 'description' => 'Cuentas, roles y membresías de los Workspaces que administras.', 'list' => 'manage-users', 'module' => 'users_roles'],
        'settings' => ['label' => 'Configuraciones', 'description' => 'Identidad, formatos regionales, apariencia e impuestos del Workspace.', 'list' => 'view-settings', 'module' => 'settings'],
        'integrations' => ['label' => 'Integraciones', 'description' => 'Conexiones con servicios externos (n8n, WhatsApp, agendas).', 'list' => 'view-integrations', 'module' => 'integrations'],
        'audit' => ['label' => 'Auditoría', 'description' => 'Historial de cambios administrativos y eventos automáticos.', 'list' => 'view-audit', 'module' => 'audit'],
        'assistants' => ['label' => 'Asistentes', 'description' => 'Asistentes, sus canales, apariencia y acceso de la automatización.', 'list' => 'view-chatbots', 'module' => 'assistants'],
        'conversations' => ['label' => 'Conversaciones', 'description' => 'Bandeja, atención humana y entorno DEMO.', 'list' => 'view-conversations', 'module' => 'conversations'],
        'bookings' => ['label' => 'Agenda y reservas', 'description' => 'Citas, reglas de reserva y página pública.', 'list' => 'view-bookings', 'module' => 'bookings'],
        'services' => ['label' => 'Servicios', 'description' => 'Catálogo de servicios reservables.', 'list' => 'view-services', 'module' => 'service_catalog'],
        'professionals' => ['label' => 'Profesionales y disponibilidad', 'description' => 'Profesionales, recursos, horarios y días no laborables.', 'list' => 'view-professionals', 'module' => 'professionals'],
        'locations' => ['label' => 'Sedes', 'description' => 'Dónde se atiende el negocio.', 'list' => 'view-locations', 'module' => 'locations'],
        'customers' => ['label' => 'Clientes', 'description' => 'Fichas de clientes del Workspace.', 'list' => 'view-customers', 'module' => 'customers'],
        'campaigns' => ['label' => 'Campañas', 'description' => 'Campañas y plantillas de mensaje (no envían mensajes).', 'list' => 'view-campaigns', 'module' => 'campaigns'],
        'catalog' => ['label' => 'Catálogo de productos', 'description' => 'Productos, precios y disponibilidad por sede.', 'list' => 'view-catalog', 'module' => 'product_catalog'],
        'orders' => ['label' => 'Pedidos', 'description' => 'Pedidos, su detalle y su flujo de estados.', 'list' => 'view-orders', 'module' => 'orders'],
        'platform' => ['label' => 'Administración de la plataforma', 'description' => 'Planes, módulos y accesos de soporte de CCG.'],
    ],

    'permissions' => [
        // General
        'view-dashboard' => ['group' => 'general', 'label' => 'Consultar Dashboard y Reportes', 'description' => 'Abre el Dashboard y Reportes con las cifras agregadas del Workspace.'],
        'view-users' => ['group' => 'general', 'label' => 'Ver nombres del equipo en Reportes', 'description' => 'Muestra los nombres y correos del equipo en «Usuarios recientes» de Reportes. No abre Usuarios y roles.'],

        // Usuarios y roles
        'manage-users' => ['group' => 'users', 'label' => 'Acceder a Usuarios y roles', 'description' => 'Abre el módulo y muestra usuarios, roles, permisos y Workspaces que administra. Cada acción necesita además su propio permiso.'],
        'create-users' => ['group' => 'users', 'label' => 'Crear usuarios', 'description' => 'Crea cuentas dentro de un Workspace que administra, con un rol que ya posee.'],
        'edit-users' => ['group' => 'users', 'label' => 'Editar usuarios', 'description' => 'Cambia el nombre, el correo y la contraseña de cuentas que existen solo en su Workspace.'],
        'assign-roles' => ['group' => 'users', 'label' => 'Asignar roles y membresías', 'description' => 'Cambia el rol de un miembro, lo mueve o lo quita del Workspace. Solo roles con permisos que ya posee; nunca el propio rol.'],
        'deactivate-users' => ['group' => 'users', 'label' => 'Activar y desactivar usuarios', 'description' => 'Bloquea o restablece el acceso de una cuenta sin borrar su historial.'],

        // Configuraciones
        'view-settings' => ['group' => 'settings', 'label' => 'Consultar configuraciones', 'description' => 'Abre Configuraciones y ve los valores guardados. No los cambia.'],
        'edit-settings' => ['group' => 'settings', 'label' => 'Editar configuraciones', 'description' => 'Cambia la identidad, los formatos regionales, la apariencia y los impuestos del Workspace.'],

        // Integraciones
        'view-integrations' => ['group' => 'integrations', 'label' => 'Consultar integraciones', 'description' => 'Abre Integraciones y ve el estado de cada conexión. Nunca muestra claves ni secretos.'],
        'manage-integrations' => ['group' => 'integrations', 'label' => 'Crear y editar integraciones', 'description' => 'Crea conexiones y cambia su configuración y sus credenciales (n8n, WhatsApp, agendas externas). Las credenciales son de solo escritura.'],
        'test-integrations' => ['group' => 'integrations', 'label' => 'Probar conexiones', 'description' => 'Ejecuta «Probar conexión»: una llamada real al servicio externo.'],
        'activate-integrations' => ['group' => 'integrations', 'label' => 'Activar, desactivar y desconectar', 'description' => 'Enciende o apaga una conexión y desconecta n8n sin borrar su historial.'],
        'delete-integrations' => ['group' => 'integrations', 'label' => 'Retirar conexiones de prueba', 'description' => 'Retira una conexión manual que ya no se necesita. Solo las que no tienen historial que conservar.'],

        // Auditoría
        'view-audit' => ['group' => 'audit', 'label' => 'Consultar la auditoría', 'description' => 'Abre el historial de Auditoría del Workspace con sus filtros.'],
        'export-audit' => ['group' => 'audit', 'label' => 'Exportar la auditoría', 'description' => 'Descarga el historial de Auditoría filtrado en PDF.'],

        // Asistentes
        'view-chatbots' => ['group' => 'assistants', 'label' => 'Consultar asistentes', 'description' => 'Ve los asistentes, sus canales y sus instrucciones.'],
        'create-chatbots' => ['group' => 'assistants', 'label' => 'Crear asistentes', 'description' => 'Crea un asistente nuevo.'],
        'edit-chatbots' => ['group' => 'assistants', 'label' => 'Editar asistentes', 'description' => 'Cambia la identidad, las instrucciones y el avatar de un asistente.'],
        'activate-chatbots' => ['group' => 'assistants', 'label' => 'Activar y desactivar asistentes', 'description' => 'Enciende o apaga un asistente. Nunca se borra.'],
        'manage-chatbot-channels' => ['group' => 'assistants', 'label' => 'Configurar canales y apariencia', 'description' => 'Asocia cada canal a su integración y cambia la apariencia del botón y del chat.'],
        'manage-agent-tokens' => ['group' => 'assistants', 'label' => 'Generar y revocar el token de acceso', 'description' => 'Genera o revoca el token con el que n8n lee las instrucciones del asistente.'],

        // Conversaciones
        'view-conversations' => ['group' => 'conversations', 'label' => 'Consultar conversaciones', 'description' => 'Lee las conversaciones y los mensajes de los contactos del Workspace.'],
        'reply-conversations' => ['group' => 'conversations', 'label' => 'Atender conversaciones', 'description' => 'Toma una conversación, escribe, la devuelve a la IA y la resuelve.'],
        'manage-conversations' => ['group' => 'conversations', 'label' => 'Supervisar conversaciones', 'description' => 'Asigna conversaciones a otros agentes y opera cualquiera, aunque no sea suya.'],
        'use-conversation-demo' => ['group' => 'conversations', 'label' => 'Usar el entorno DEMO', 'description' => 'Genera, reinicia y limpia los escenarios DEMO y simula tráfico sobre ellos. No existe fuera de los entornos de demostración.'],

        // Agenda y reservas
        'view-bookings' => ['group' => 'bookings', 'label' => 'Consultar la agenda', 'description' => 'Ve la agenda y las citas.'],
        'create-bookings' => ['group' => 'bookings', 'label' => 'Crear citas', 'description' => 'Registra una cita nueva.'],
        'edit-bookings' => ['group' => 'bookings', 'label' => 'Editar y reprogramar citas', 'description' => 'Confirma, reprograma, marca como atendida o no asistida y registra la confirmación del cliente.'],
        'cancel-bookings' => ['group' => 'bookings', 'label' => 'Cancelar citas', 'description' => 'Cancela una cita con su motivo.'],
        'manage-booking-settings' => ['group' => 'bookings', 'label' => 'Configurar reglas y página pública', 'description' => 'Cambia las reglas de reserva, los avisos de citas y el enlace de la página pública.'],

        // Servicios
        'view-services' => ['group' => 'services', 'label' => 'Consultar servicios', 'description' => 'Ve el catálogo de servicios.'],
        'create-services' => ['group' => 'services', 'label' => 'Crear servicios', 'description' => 'Crea un servicio reservable.'],
        'edit-services' => ['group' => 'services', 'label' => 'Editar servicios', 'description' => 'Cambia nombre, duración, precio y tiempos de un servicio.'],
        'activate-services' => ['group' => 'services', 'label' => 'Activar y desactivar servicios', 'description' => 'Ofrece o retira un servicio. Nunca se borra.'],

        // Profesionales y disponibilidad
        'view-professionals' => ['group' => 'professionals', 'label' => 'Consultar profesionales', 'description' => 'Ve los profesionales, los recursos y su disponibilidad.'],
        'create-professionals' => ['group' => 'professionals', 'label' => 'Crear profesionales y recursos', 'description' => 'Da de alta un profesional o un recurso (sala, silla).'],
        'edit-professionals' => ['group' => 'professionals', 'label' => 'Editar profesionales y recursos', 'description' => 'Cambia datos, servicios, sedes y horario semanal de un profesional o recurso.'],
        'activate-professionals' => ['group' => 'professionals', 'label' => 'Activar y desactivar profesionales', 'description' => 'Habilita o retira a un profesional o recurso. Nunca se borra.'],
        'manage-availability' => ['group' => 'professionals', 'label' => 'Gestionar días y horas no disponibles', 'description' => 'Crea, edita y elimina excepciones: vacaciones, festivos y bloqueos.'],

        // Sedes
        'view-locations' => ['group' => 'locations', 'label' => 'Consultar sedes', 'description' => 'Ve las sedes del Workspace.'],
        'create-locations' => ['group' => 'locations', 'label' => 'Crear sedes', 'description' => 'Crea una sede nueva.'],
        'edit-locations' => ['group' => 'locations', 'label' => 'Editar sedes', 'description' => 'Cambia dirección, contacto y servicios de una sede.'],
        'activate-locations' => ['group' => 'locations', 'label' => 'Activar y desactivar sedes', 'description' => 'Abre o cierra una sede. Nunca se borra.'],

        // Clientes
        'view-customers' => ['group' => 'customers', 'label' => 'Consultar clientes', 'description' => 'Ve la lista de clientes del Workspace.'],
        'view-customer-details' => ['group' => 'customers', 'label' => 'Ver el detalle de un cliente', 'description' => 'Abre la ficha completa: contacto, consentimiento, citas y conversaciones relacionadas.'],
        'create-customers' => ['group' => 'customers', 'label' => 'Crear clientes', 'description' => 'Registra una ficha nueva (con deduplicación).'],
        'edit-customers' => ['group' => 'customers', 'label' => 'Editar clientes', 'description' => 'Cambia los datos, las etiquetas y el consentimiento de una ficha.'],
        'activate-customers' => ['group' => 'customers', 'label' => 'Activar y desactivar clientes', 'description' => 'Habilita o retira una ficha sin borrarla.'],
        'anonymize-customers' => ['group' => 'customers', 'label' => 'Anonimizar clientes', 'description' => 'Elimina los datos personales de una ficha de forma irreversible (el equivalente a eliminar).'],

        // Campañas
        'view-campaigns' => ['group' => 'campaigns', 'label' => 'Consultar campañas', 'description' => 'Ve la lista de campañas y plantillas.'],
        'view-campaign-details' => ['group' => 'campaigns', 'label' => 'Ver el detalle de una campaña', 'description' => 'Abre la campaña con sus destinatarios, exclusiones y resultado de la simulación.'],
        'create-campaigns' => ['group' => 'campaigns', 'label' => 'Crear campañas', 'description' => 'Crea una campaña nueva.'],
        'edit-campaigns' => ['group' => 'campaigns', 'label' => 'Editar campañas', 'description' => 'Cambia el nombre, la plantilla y la audiencia de una campaña que no se ha preparado.'],
        'prepare-campaigns' => ['group' => 'campaigns', 'label' => 'Preparar y simular campañas', 'description' => 'Calcula los destinatarios y simula el resultado. No envía mensajes: el envío real no existe.'],
        'cancel-campaigns' => ['group' => 'campaigns', 'label' => 'Cancelar campañas', 'description' => 'Cancela una campaña.'],
        'manage-campaign-templates' => ['group' => 'campaigns', 'label' => 'Gestionar plantillas de mensaje', 'description' => 'Crea, edita, activa y desactiva plantillas.'],

        // Catálogo de productos
        'view-catalog' => ['group' => 'catalog', 'label' => 'Consultar el catálogo de productos', 'description' => 'Ve los productos, sus precios y las sedes que los ofrecen.'],
        'create-products' => ['group' => 'catalog', 'label' => 'Crear productos', 'description' => 'Da de alta un producto.'],
        'edit-products' => ['group' => 'catalog', 'label' => 'Editar productos y precios', 'description' => 'Cambia los datos y el precio de un producto. Los pedidos ya hechos conservan su copia.'],
        'activate-products' => ['group' => 'catalog', 'label' => 'Activar y desactivar productos', 'description' => 'Ofrece o retira un producto. Nunca se borra.'],

        // Pedidos
        'view-orders' => ['group' => 'orders', 'label' => 'Consultar pedidos', 'description' => 'Ve la lista de pedidos.'],
        'view-order-details' => ['group' => 'orders', 'label' => 'Ver el detalle de un pedido', 'description' => 'Abre el pedido con sus líneas, datos de contacto y entrega e historial de estados.'],
        'create-orders' => ['group' => 'orders', 'label' => 'Registrar pedidos', 'description' => 'Crea un pedido; el total lo calcula el servidor con el catálogo.'],
        'edit-orders' => ['group' => 'orders', 'label' => 'Editar pedidos', 'description' => 'Cambia las notas y los datos de entrega mientras el pedido no haya salido.'],
        'cancel-orders' => ['group' => 'orders', 'label' => 'Cancelar pedidos', 'description' => 'Cancela un pedido con un motivo escrito.'],
        'process-orders' => ['group' => 'orders', 'label' => 'Avanzar el estado de los pedidos', 'description' => 'Confirma, prepara, marca como listo, despacha y entrega pedidos. No los crea ni los cancela.'],

        // Administración de la plataforma
        'manage-plans' => ['group' => 'platform', 'label' => 'Administrar planes comerciales', 'description' => 'Crea y edita planes, define sus módulos y los asigna a Workspaces. Solo opera en Desarrollo_DEV.', 'platform' => true],
        'manage-modules' => ['group' => 'platform', 'label' => 'Administrar el catálogo de módulos', 'description' => 'Registra módulos y categorías. Solo opera en Desarrollo_DEV.', 'platform' => true],
        'access-private-data' => ['group' => 'platform', 'label' => 'Abrir accesos de soporte', 'description' => 'Permite abrir un acceso temporal, justificado y auditado a datos privados de otro Workspace. Solo opera en Desarrollo_DEV.', 'platform' => true],
    ],

    /*
     * Permissions that were split into finer ones. On the FIRST run of the seeder after the split (a fine permission that does not exist yet
     * is created), every role that held the broad permission receives the fine ones in `grants`, so no existing role loses an ability; on later
     * runs nothing is granted again, so a superuser's decision to take one away is kept. `retire` removes the broad permission afterwards
     * (it is no longer used by any route): its assignments are already expanded, and Spatie detaches the rows it leaves. A permission whose
     * operation did not exist before (for example `delete-integrations`) is NOT listed in `grants`: nobody gains a new power by migration;
     * `admin` receives every permission in any case.
     */
    'legacy' => [
        'manage-users' => ['grants' => ['create-users', 'edit-users', 'assign-roles', 'deactivate-users'], 'retire' => false],
        'manage-settings' => ['grants' => ['view-settings', 'edit-settings', 'view-integrations', 'manage-integrations', 'test-integrations', 'activate-integrations', 'view-audit', 'export-audit'], 'retire' => true],
        'manage-chatbots' => ['grants' => ['create-chatbots', 'edit-chatbots', 'activate-chatbots', 'manage-chatbot-channels', 'manage-agent-tokens'], 'retire' => true],
        'manage-conversations' => ['grants' => ['use-conversation-demo'], 'retire' => false],
        'view-bookings' => ['grants' => ['view-services', 'view-professionals', 'view-locations'], 'retire' => false],
        'manage-bookings' => ['grants' => ['create-bookings', 'edit-bookings', 'cancel-bookings'], 'retire' => true],
        'manage-services' => ['grants' => ['manage-booking-settings', 'create-services', 'edit-services', 'activate-services', 'create-professionals', 'edit-professionals', 'activate-professionals', 'manage-availability', 'create-locations', 'edit-locations', 'activate-locations'], 'retire' => true],
        'view-customers' => ['grants' => ['view-customer-details'], 'retire' => false],
        'manage-customers' => ['grants' => ['create-customers', 'edit-customers', 'activate-customers', 'anonymize-customers'], 'retire' => true],
        'view-campaigns' => ['grants' => ['view-campaign-details'], 'retire' => false],
        'manage-campaigns' => ['grants' => ['create-campaigns', 'edit-campaigns', 'prepare-campaigns', 'cancel-campaigns', 'manage-campaign-templates'], 'retire' => true],
        'manage-catalog' => ['grants' => ['create-products', 'edit-products', 'activate-products'], 'retire' => true],
        'view-orders' => ['grants' => ['view-order-details'], 'retire' => false],
        'manage-orders' => ['grants' => ['create-orders', 'edit-orders', 'cancel-orders'], 'retire' => true],
    ],
];
