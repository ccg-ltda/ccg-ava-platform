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
 * `default_plan` modules: only those of the `shared` category (the plan every existing Workspace falls back to); the others are added to plans on purpose.
 *
 * Routes that are NOT modules (a user's own profile, the Workspace logo and selector, login) are technical and stay outside.
 * `also_for` (optional) lists the categories whose solutions may ALSO build their plans from the module. It is a label for the catalog and
 * the plan builder only: it grants nothing, changes no data and no plan (the module stays one implementation, and each Workspace keeps its
 * own records and booking rules). Add it only after checking that the module's data and operations fit the other solution.
 * Adding a module = one entry here + `workspace.module:<key>` on its routes + `module` on its entry in config/navigation.js.
 */
return [
    /* Key of the plan given to every Workspace that existed before plans, and to each new one (Plataforma completa). */
    'default_plan' => 'full_platform',

    'default_plan_name' => 'Plataforma completa',

    'default_plan_description' => 'Todos los módulos compartidos de Zonno Platform. Plan inicial de compatibilidad para los Workspaces existentes.',

    /*
     * Conceptual groups of Ava Platform. They organize the catalog, the plan builder and the menu; they are NOT separate code, tables or
     * permissions: a module of any group is reusable, depends only on what `dependencies` declares and can be part of several plans.
     *  - platform: administration of the Workspace (users, roles, audit, settings, integrations). Plans, the catalog and Workspaces
     *    themselves are administered by CCG from the administrative Workspace and are not modules.
     *  - shared: capabilities every solution can reuse (assistants, conversations, customers, campaigns, dashboard, reports).
     *  - solutions: commercial solutions built on the shared capabilities (Servicios y Reservas today; Salud, eCommerce... later).
     * A module registered from the UI has no `group` in code: it takes the one of its category (`categories.*.group`) and, for a category
     * created in the UI, `default_group`. The list of categories stays open.
     */
    'groups' => [
        'platform' => ['name' => 'Administración de la plataforma', 'description' => 'Usuarios, roles, auditoría, configuraciones e integraciones del Workspace.', 'sort' => 10],
        'shared' => ['name' => 'Capacidades compartidas', 'description' => 'Asistentes, conversaciones, clientes, campañas y analítica que cualquier solución reutiliza.', 'group' => 'shared', 'sort' => 20],
        'solutions' => ['name' => 'Soluciones comerciales', 'description' => 'Servicios y Reservas, Salud, eCommerce y combinaciones futuras.', 'sort' => 30],
    ],

    'default_group' => 'solutions',

    /*
     * The STRUCTURE of the application menu, the one the sidebar draws (resources/js/config/navigation.js: General, Operación, Administración).
     * The catalog and the plan builder organize modules by it, so what a plan includes reads like the menu its Workspace will see. It is
     * organization only: it grants nothing and says nothing about the commercial solution a module is offered in (that is the category and `also_for`).
     * `menu` assigns every implemented module to one section (a test keeps it equal to navigation.js); a module registered from the UI, which has no
     * page, falls in `default_menu_section`.
     */
    'menu_sections' => [
        'general' => ['name' => 'General', 'description' => 'Lo de todos los días: el resumen, los asistentes y las conversaciones.', 'sort' => 10],
        'operation' => ['name' => 'Operación', 'description' => 'Los módulos funcionales del negocio: reportes, catálogo, pedidos, servicios, agenda, clientes y campañas.', 'sort' => 20],
        'administration' => ['name' => 'Administración', 'description' => 'La administración interna del Workspace: usuarios, auditoría, configuraciones e integraciones.', 'sort' => 30],
    ],

    'default_menu_section' => 'operation',

    'menu' => [
        'dashboard' => 'general',
        'assistants' => 'general',
        'conversations' => 'general',
        'reports' => 'operation',
        'product_catalog' => 'operation',
        'orders' => 'operation',
        'service_catalog' => 'operation',
        'locations' => 'operation',
        'professionals' => 'operation',
        'bookings' => 'operation',
        'customers' => 'operation',
        'campaigns' => 'operation',
        'users_roles' => 'administration',
        'audit' => 'administration',
        'settings' => 'administration',
        'integrations' => 'administration',
        'calendar_providers' => 'administration', // no page of its own: it is configured in Integraciones
    ],

    /*
     * The short name of a module as the SIDEBAR shows it (resources/js/config/navigation.js). It is how dependencies are named in the plan builder
     * ("Agenda necesita Servicios y Profesionales"), so they read like the menu the Workspace sees. Display only: the key stays the identity and the full
     * `name` of the catalog stays on the module's own card. A module with no page of its own (calendar_providers) has none and shows its name. A test keeps
     * this equal to navigation.js.
     */
    'labels' => [
        'dashboard' => 'Dashboard', 'assistants' => 'Asistentes', 'conversations' => 'Conversaciones', 'reports' => 'Reportes', 'product_catalog' => 'Catálogo',
        'orders' => 'Pedidos', 'service_catalog' => 'Servicios', 'locations' => 'Sedes', 'professionals' => 'Profesionales', 'bookings' => 'Agenda',
        'customers' => 'Clientes', 'campaigns' => 'Campañas', 'users_roles' => 'Usuarios y Roles', 'audit' => 'Auditoría', 'settings' => 'Configuraciones',
        'integrations' => 'Integraciones',
    ],

    /*
     * Short notes for whoever builds a plan, true to how the code behaves (nothing commercial is implied): what a Workspace loses if the module is
     * left out. A plan may still leave it out; the note only helps to decide.
     */
    'plan_hints' => [
        'dashboard' => 'Sin él, el Workspace no tiene resumen de inicio ni accesos rápidos.',
        'users_roles' => 'Sin él, nadie del Workspace podrá administrar cuentas, roles ni miembros.',
        'settings' => 'Sin él, el Workspace no podrá cambiar su identidad, formatos, apariencia ni impuestos.',
        'integrations' => 'Sin él, el Workspace no podrá conectar WhatsApp, n8n ni ningún servicio externo.',
        'assistants' => 'Necesita Conversaciones para recibir y responder mensajes.',
        'conversations' => 'Es la bandeja donde se atienden los mensajes que llegan por los canales.',
        'calendar_providers' => 'Módulo sin página propia: habilita conectar una agenda externa (Booker) desde Integraciones. Necesita Agenda e Integraciones.',
    ],

    'categories' => [
        // `solution => false`: a group of shared capabilities, not a commercial solution (it can not be chosen as the solution of a plan in the public sign-up). Default true.
        'shared' => ['name' => 'Módulos compartidos', 'description' => 'Funciones comunes a todas las soluciones de Zonno Platform.', 'solution' => false, 'sort' => 10],
        'health' => ['name' => 'Salud', 'description' => 'Área para módulos de salud.', 'group' => 'solutions', 'sort' => 20],
        'delivery' => ['name' => 'Delivery', 'description' => 'Área para módulos de delivery.', 'group' => 'solutions', 'sort' => 30],
        'ecommerce' => ['name' => 'eCommerce', 'description' => 'Área para módulos de comercio electrónico.', 'group' => 'solutions', 'sort' => 40],
        'services_bookings' => ['name' => 'Servicios y Reservas', 'description' => 'Área para módulos de servicios y reservas.', 'group' => 'solutions', 'sort' => 50],
        'communication' => ['name' => 'Clientes y comunicación', 'description' => 'Fichas de clientes y mensajes salientes reutilizables por cualquier solución.', 'group' => 'shared', 'solution' => false, 'sort' => 60],
    ],

    /*
     * Assistants receive, store and answer messages through Conversaciones: the agent API (`/messages`, `/messages/status`,
     * `authorize`, `handoff`), the public widget, the Meta webhook, the queued executions and the agent's delivery all need
     * both (ModuleAccess::CHANNEL_MODULES). The reverse is NOT declared: the inbox reads and shows what it already holds without
     * Asistentes, and what it cannot do alone (send) is already refused by ChannelDelivery.
     */
    'dependencies' => [
        'assistants' => ['conversations'],
        /*
         * Servicios y Reservas is built from reusable pieces. A professional performs services; an appointment needs both
         * (availability is computed from the professional's schedule for a service). An external agenda is a way of running the
         * bookings, and is configured as an integration.
         */
        'professionals' => ['service_catalog'],
        'bookings' => ['service_catalog', 'professionals'],
        'calendar_providers' => ['bookings', 'integrations'],
        // A campaign is addressed to customers of the Workspace: it cannot exist without the customer records.
        'campaigns' => ['customers'],
        // An order is made of products of the catalog: without it there is nothing to order.
        'orders' => ['product_catalog'],
    ],

    /*
     * CORE routes: what every signed-in person needs whatever the plan, and the platform administration. They are NOT contractable
     * modules and never appear in a plan or in the catalog; each has its own rule instead of `workspace.module`:
     *  - own account and session (profile, acceptance of the legal texts, logout, e-mail verification, password reset);
     *  - the shell of the app (the Workspace logo and the Workspace selector, which only answers what the user may choose);
     *  - saved filters (the module of each filter's scope is checked inside `SavedFilters`);
     *  - platform administration (plans, catalog, access of support), tied to `Desarrollo_DEV` by `workspace.platform` / permission.
     * A test (ModuleArchitectureTest) fails when an authenticated route is neither behind `workspace.module` nor listed here, so a new
     * page can not be left outside the plan by accident.
     */
    'core_routes' => [
        'profile.*', 'legal.accept', 'legal.accept.store', 'logout', 'verification.*', 'password.*', 'confirm-password',
        'workspaces.search', 'workspace.logo',
        'saved-filters.*',
        'workspace.empty', 'workspaces.modules.*', 'workspaces.limits.*', 'workspaces.trial.*', 'workspaces.terms.*', 'plans.*', 'modules.*', 'module-categories.*', 'workspaces.plan.update', 'support-access.*', 'email-template.*',
    ],

    'implemented' => [
        'dashboard' => ['name' => 'Dashboard', 'description' => 'Resumen ejecutivo y operativo del Workspace.', 'group' => 'shared', 'category' => 'shared', 'route' => 'dashboard', 'permission' => 'view-dashboard'],
        'assistants' => ['name' => 'Asistentes / AVA Chat', 'description' => 'Asistentes, canales, apariencia y acceso de la automatización.', 'group' => 'shared', 'category' => 'shared', 'route' => 'chatbots.index', 'permission' => 'view-chatbots'],
        'conversations' => ['name' => 'Conversaciones', 'description' => 'Bandeja de conversaciones, atención humana y entorno DEMO.', 'group' => 'shared', 'category' => 'shared', 'route' => 'conversations.index', 'permission' => 'view-conversations'],
        'reports' => ['name' => 'Reportes', 'description' => 'Analítica detallada del Workspace.', 'group' => 'shared', 'category' => 'shared', 'route' => 'reports.index', 'permission' => 'view-dashboard'],
        'audit' => ['name' => 'Auditoría', 'description' => 'Historial de cambios administrativos y eventos automáticos.', 'group' => 'platform', 'category' => 'shared', 'route' => 'audit.index', 'permission' => 'view-audit'],
        'settings' => ['name' => 'Configuraciones', 'description' => 'Identidad, formatos regionales, apariencia e impuestos del Workspace.', 'group' => 'platform', 'category' => 'shared', 'route' => 'settings.index', 'permission' => 'view-settings'],
        'integrations' => ['name' => 'Integraciones', 'description' => 'Conexiones del Workspace con servicios externos.', 'group' => 'platform', 'category' => 'shared', 'route' => 'integrations.index', 'permission' => 'view-integrations'],
        'users_roles' => ['name' => 'Usuarios y roles', 'description' => 'Cuentas, roles, permisos y membresías.', 'group' => 'platform', 'category' => 'shared', 'route' => 'users.index', 'permission' => 'manage-users'],
        'service_catalog' => ['name' => 'Catálogo de servicios', 'description' => 'Servicios con duración, precio opcional y tiempo entre citas. Reutilizable por cualquier negocio con citas.', 'group' => 'solutions', 'category' => 'services_bookings', 'also_for' => ['health'], 'route' => 'services.index', 'permission' => 'view-services'],
        'locations' => ['name' => 'Sedes', 'description' => 'Las sedes donde se atiende: dirección, contacto, servicios que ofrece cada una y horarios de los profesionales por sede. Opcional: sin sedes, la agenda funciona igual.', 'group' => 'solutions', 'category' => 'services_bookings', 'also_for' => ['health'], 'route' => 'locations.index', 'permission' => 'view-locations'],
        'product_catalog' => ['name' => 'Catálogo de productos', 'description' => 'Productos con SKU, precio, categoría y las sedes que los ofrecen. Base de E-commerce y Delivery; no son los servicios reservables.', 'group' => 'solutions', 'category' => 'ecommerce', 'also_for' => ['delivery'], 'route' => 'catalog.index', 'permission' => 'view-catalog'],
        'orders' => ['name' => 'Pedidos', 'description' => 'Pedidos con líneas, total calculado por el servidor, estados y trazabilidad. Registra el pedido; no gestiona pagos ni avisa al cliente todavía.', 'group' => 'solutions', 'category' => 'ecommerce', 'also_for' => ['delivery'], 'route' => 'orders.index', 'permission' => 'view-orders'],
        'professionals' => ['name' => 'Profesionales y disponibilidad', 'description' => 'Profesionales, recursos opcionales, horarios semanales, descansos y días no laborables.', 'group' => 'solutions', 'category' => 'services_bookings', 'also_for' => ['health'], 'route' => 'professionals.index', 'permission' => 'view-professionals'],
        'bookings' => ['name' => 'Agenda y reservas', 'description' => 'Agenda, citas (crear, confirmar, reprogramar, cancelar), clientes, reglas de reserva, reserva web y herramientas de Ava Chat.', 'group' => 'solutions', 'category' => 'services_bookings', 'also_for' => ['health'], 'route' => 'bookings.index', 'permission' => 'view-bookings'],
        'calendar_providers' => ['name' => 'Proveedores de agenda', 'description' => 'Conecta una agenda externa (Booker) como fuente de verdad en lugar de la agenda de Ava.', 'group' => 'solutions', 'category' => 'services_bookings', 'route' => 'integrations.index', 'permission' => 'manage-integrations'],
        'customers' => ['name' => 'Clientes', 'description' => 'Ficha de cliente por Workspace: contacto, canales, origen, etiquetas, consentimiento y relación con conversaciones y reservas.', 'group' => 'shared', 'category' => 'communication', 'route' => 'customers.index', 'permission' => 'view-customers'],
        'campaigns' => ['name' => 'Campañas', 'description' => 'Plantillas de mensaje y campañas con selección de destinatarios, exclusiones por consentimiento y trazabilidad (sin envío real todavía).', 'group' => 'shared', 'category' => 'communication', 'route' => 'campaigns.index', 'permission' => 'view-campaigns'],
    ],
];
