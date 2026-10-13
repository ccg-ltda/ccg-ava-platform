<?php

/*
 * Module registry behind Planes. A module is a capability of Ava that a commercial plan can include.
 *
 *  - `implemented` is the ONLY place that says a module has working functionality. A row in the `modules` table (created from
 *    the admin UI) is just a registered idea until its key appears here, so registering a module never makes it operational.
 *    Each entry names the real route and permission that open it (the same ones routes/web.php and navigation.js use) and
 *    seeds the catalog row (`ModuleCatalogSeeder`, re-runnable; it never overwrites names an administrator edited).
 *  - `dependencies` are TECHNICAL requirements between implemented modules: a module listed here is not operative (it is not
 *    offered, its routes answer 403) unless every module it needs is also available. They only describe what the code really
 *    needs; a plan can still combine modules freely as long as these are met. Add an entry only after checking the code.
 *  - `categories` are the organizational groups the catalog starts with. They limit nothing: more are created from the UI.
 *  - A plan decides which modules a Workspace may use; roles and permissions still decide what each user may do in them.
 *  - The administrative Workspace (config `workspace.admin_code`) always has every implemented module.
 *
 * Routes that are NOT modules (a user's own profile, the Workspace logo and selector, login) are technical and stay outside.
 * Adding a module = one entry here + `workspace.module:<key>` on its routes + `module` on its entry in config/navigation.js.
 */
return [
    /* Key of the plan given to every Workspace that existed before plans, and to each new one (Plataforma completa). */
    'default_plan' => 'full_platform',

    'default_plan_name' => 'Plataforma completa',

    'default_plan_description' => 'Todos los módulos compartidos de Ava Platform. Plan inicial de compatibilidad para los Workspaces existentes.',

    'categories' => [
        'shared' => ['name' => 'Módulos compartidos', 'description' => 'Funciones comunes a todas las soluciones de Ava Platform.', 'sort' => 10],
        'health' => ['name' => 'Salud', 'description' => 'Área para módulos de salud.', 'sort' => 20],
        'delivery' => ['name' => 'Delivery', 'description' => 'Área para módulos de delivery.', 'sort' => 30],
        'ecommerce' => ['name' => 'eCommerce', 'description' => 'Área para módulos de comercio electrónico.', 'sort' => 40],
        'services_bookings' => ['name' => 'Servicios y Reservas', 'description' => 'Área para módulos de servicios y reservas.', 'sort' => 50],
    ],

    /*
     * Assistants receive, store and answer messages through Conversaciones: the agent API (`/messages`, `/messages/status`,
     * `authorize`, `handoff`), the public widget, the Meta webhook, the queued executions and the agent's delivery all need
     * both (ModuleAccess::CHANNEL_MODULES). The reverse is NOT declared: the inbox reads and shows what it already holds without
     * Asistentes, and what it cannot do alone (send) is already refused by ChannelDelivery.
     */
    'dependencies' => [
        'assistants' => ['conversations'],
    ],

    'implemented' => [
        'dashboard' => ['name' => 'Dashboard', 'description' => 'Resumen ejecutivo y operativo del Workspace.', 'category' => 'shared', 'route' => 'dashboard', 'permission' => 'view-dashboard'],
        'assistants' => ['name' => 'Asistentes / AVA Chat', 'description' => 'Asistentes, canales, apariencia y acceso de la automatización.', 'category' => 'shared', 'route' => 'chatbots.index', 'permission' => 'view-chatbots'],
        'conversations' => ['name' => 'Conversaciones', 'description' => 'Bandeja de conversaciones, atención humana y entorno DEMO.', 'category' => 'shared', 'route' => 'conversations.index', 'permission' => 'view-conversations'],
        'reports' => ['name' => 'Reportes', 'description' => 'Analítica detallada del Workspace.', 'category' => 'shared', 'route' => 'reports.index', 'permission' => 'view-dashboard'],
        'audit' => ['name' => 'Auditoría', 'description' => 'Historial de cambios administrativos y eventos automáticos.', 'category' => 'shared', 'route' => 'audit.index', 'permission' => 'manage-settings'],
        'settings' => ['name' => 'Configuraciones', 'description' => 'Identidad, formatos regionales, apariencia e impuestos del Workspace.', 'category' => 'shared', 'route' => 'settings.index', 'permission' => 'manage-settings'],
        'integrations' => ['name' => 'Integraciones', 'description' => 'Conexiones del Workspace con servicios externos.', 'category' => 'shared', 'route' => 'integrations.index', 'permission' => 'manage-settings'],
        'users_roles' => ['name' => 'Usuarios y roles', 'description' => 'Cuentas, roles, permisos y membresías.', 'category' => 'shared', 'route' => 'users.index', 'permission' => 'manage-users'],
    ],
];
