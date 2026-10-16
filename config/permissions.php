<?php

/*
 * Catalog of the permissions the system defines: what each one allows, in words a person can read, and the area it belongs to.
 * It is documentation shown in Usuarios y roles (role editor, permission matrix, a user's effective permissions); it never grants
 * anything. The permissions themselves are created by RolesAndPermissionsSeeder and enforced by the routes (`workspace.permission:`),
 * and a test keeps this catalog and the seeder in step. A permission only appears here when an action of the system uses it.
 *
 * `platform` marks the permissions that only operate inside the administrative Workspace (Desarrollo_DEV): in a client Workspace
 * the server ignores them, whatever the role.
 */
return [
    'groups' => [
        'general' => 'General',
        'users' => 'Usuarios y roles',
        'settings' => 'Configuraciones, integraciones y auditoría',
        'assistants' => 'Asistentes',
        'conversations' => 'Conversaciones',
        'bookings' => 'Servicios y reservas',
        'customers' => 'Clientes',
        'campaigns' => 'Campañas',
        'commerce' => 'Catálogo y pedidos',
        'platform' => 'Administración de la plataforma',
    ],

    'permissions' => [
        'view-dashboard' => ['group' => 'general', 'label' => 'Consultar Dashboard y Reportes', 'description' => 'Abre el Dashboard y Reportes con las cifras agregadas del Workspace.'],
        'view-users' => ['group' => 'general', 'label' => 'Ver nombres del equipo en Reportes', 'description' => 'Muestra los nombres y correos del equipo en «Usuarios recientes» de Reportes. No abre Usuarios y roles.'],

        'manage-users' => ['group' => 'users', 'label' => 'Acceder a Usuarios y roles', 'description' => 'Abre el módulo y muestra usuarios, roles, permisos y Workspaces que administra. Cada acción necesita además su propio permiso.'],
        'create-users' => ['group' => 'users', 'label' => 'Crear usuarios', 'description' => 'Crea cuentas dentro de un Workspace que administra, con un rol que ya posee.'],
        'edit-users' => ['group' => 'users', 'label' => 'Editar usuarios', 'description' => 'Cambia el nombre, el correo y la contraseña de cuentas que existen solo en su Workspace.'],
        'assign-roles' => ['group' => 'users', 'label' => 'Asignar roles y membresías', 'description' => 'Cambia el rol de un miembro, lo mueve o lo quita del Workspace. Solo roles con permisos que ya posee; nunca el propio rol.'],
        'deactivate-users' => ['group' => 'users', 'label' => 'Activar y desactivar usuarios', 'description' => 'Bloquea o restablece el acceso de una cuenta sin borrar su historial.'],

        'manage-settings' => ['group' => 'settings', 'label' => 'Gestionar configuraciones, integraciones y auditoría', 'description' => 'Cambia las Configuraciones y las credenciales de Integraciones y consulta el historial de Auditoría.'],
        'export-audit' => ['group' => 'settings', 'label' => 'Exportar la auditoría', 'description' => 'Descarga el historial de Auditoría filtrado en PDF.'],

        'view-chatbots' => ['group' => 'assistants', 'label' => 'Consultar asistentes', 'description' => 'Ve los asistentes, sus canales y sus instrucciones.'],
        'manage-chatbots' => ['group' => 'assistants', 'label' => 'Gestionar asistentes', 'description' => 'Crea y edita asistentes, sus canales, su apariencia y su token de acceso.'],

        'view-conversations' => ['group' => 'conversations', 'label' => 'Consultar conversaciones', 'description' => 'Lee las conversaciones y los mensajes de los contactos del Workspace.'],
        'reply-conversations' => ['group' => 'conversations', 'label' => 'Atender conversaciones', 'description' => 'Toma una conversación, escribe, la devuelve a la IA y la resuelve.'],
        'manage-conversations' => ['group' => 'conversations', 'label' => 'Gestionar conversaciones', 'description' => 'Asigna conversaciones a otros agentes, opera cualquiera y usa el entorno DEMO.'],

        'view-bookings' => ['group' => 'bookings', 'label' => 'Consultar agenda, servicios y profesionales', 'description' => 'Ve la agenda, los servicios y los profesionales.'],
        'manage-bookings' => ['group' => 'bookings', 'label' => 'Gestionar reservas', 'description' => 'Crea, confirma, reprograma, cancela y cierra citas.'],
        'manage-services' => ['group' => 'bookings', 'label' => 'Configurar servicios y agenda', 'description' => 'Edita el catálogo, los profesionales, los horarios, las excepciones, las reglas y el enlace público.'],

        'view-customers' => ['group' => 'customers', 'label' => 'Consultar clientes', 'description' => 'Ve las fichas de clientes del Workspace.'],
        'manage-customers' => ['group' => 'customers', 'label' => 'Gestionar clientes', 'description' => 'Crea y edita fichas, las activa o desactiva y las anonimiza.'],

        'view-campaigns' => ['group' => 'campaigns', 'label' => 'Consultar campañas', 'description' => 'Ve plantillas y campañas.'],
        'manage-campaigns' => ['group' => 'campaigns', 'label' => 'Gestionar campañas', 'description' => 'Crea plantillas y campañas, las prepara, las simula y las cancela. No envía mensajes.'],

        'view-catalog' => ['group' => 'commerce', 'label' => 'Consultar el catálogo de productos', 'description' => 'Ve los productos, sus precios y las sedes que los ofrecen.'],
        'manage-catalog' => ['group' => 'commerce', 'label' => 'Gestionar el catálogo de productos', 'description' => 'Crea y edita productos, cambia su precio y los activa o desactiva. No borra ninguno.'],
        'view-orders' => ['group' => 'commerce', 'label' => 'Consultar pedidos', 'description' => 'Ve los pedidos, sus líneas, los datos de contacto y entrega y su historial de estados.'],
        'manage-orders' => ['group' => 'commerce', 'label' => 'Registrar, editar y cancelar pedidos', 'description' => 'Crea pedidos, edita sus notas y datos de entrega mientras no hayan salido, y los cancela con un motivo.'],
        'process-orders' => ['group' => 'commerce', 'label' => 'Avanzar el estado de los pedidos', 'description' => 'Confirma, prepara, marca como listo, despacha y entrega pedidos. No los crea ni los cancela.'],

        'manage-plans' => ['group' => 'platform', 'label' => 'Administrar planes comerciales', 'description' => 'Crea y edita planes, define sus módulos y los asigna a Workspaces. Solo opera en Desarrollo_DEV.', 'platform' => true],
        'manage-modules' => ['group' => 'platform', 'label' => 'Administrar el catálogo de módulos', 'description' => 'Registra módulos y categorías. Solo opera en Desarrollo_DEV.', 'platform' => true],
        'access-private-data' => ['group' => 'platform', 'label' => 'Abrir accesos de soporte', 'description' => 'Permite abrir un acceso temporal, justificado y auditado a datos privados de otro Workspace. Solo opera en Desarrollo_DEV.', 'platform' => true],
    ],
];
