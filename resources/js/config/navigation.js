import { BarChart3, Bot, CalendarDays, Contact, History, Layers, LayoutDashboard, MapPin, Megaphone, MessagesSquare, Package, Plug, Scissors, ShoppingCart, Settings, UserRound, Users } from 'lucide-react';

/**
 * Single definition of the sidebar menu. EVERY section has a heading and folds in the sidebar (see Components/NavSection). A section is
 * only navigation grouping BY FUNCTION: it is not a module, a plan, a category or a commercial solution, and it grants nothing. Each
 * reusable module appears exactly once, whatever solution a plan offers it in: General (the everyday workspace), Operación (the reusable
 * functional modules: reports, services, professionals, agenda, customers, campaigns) and Administración (the internal administration of the
 * platform). Calendar providers have no page of their own: they are configured in Integraciones. A new reusable module is one entry in
 * Operación; a new commercial solution adds nothing here (it is a category and a plan, see docs/modulos-y-planes.md).
 * Every `route` is a real Laravel route name; `permission` limits an item to users whose role in the active Workspace grants it (the same
 * permission the route requires in routes/web.php; omit for everyone). `module` limits it to Workspaces whose plan includes that module (the
 * same key as `workspace.module:<key>` on its routes), and `platform` to the CCG administration (superuser inside the administrative
 * Workspace). Sections never list a module the Workspace has not contracted, so the menu only grows with what the plan includes.
 * This only decides what is shown: the server enforces every route on its own.
 */
export const navigation = [
    {
        label: 'General',
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, permission: 'view-dashboard', module: 'dashboard' },
            { label: 'Asistentes', route: 'chatbots.index', icon: Bot, permission: 'view-chatbots', module: 'assistants' },
            { label: 'Conversaciones', route: 'conversations.index', icon: MessagesSquare, permission: 'view-conversations', module: 'conversations' },
        ],
    },
    {
        label: 'Operación',
        items: [
            { label: 'Reportes', route: 'reports.index', icon: BarChart3, permission: 'view-dashboard', module: 'reports' },
            { label: 'Catálogo', route: 'catalog.index', icon: Package, permission: 'view-catalog', module: 'product_catalog' },
            { label: 'Pedidos', route: 'orders.index', icon: ShoppingCart, permission: 'view-orders', module: 'orders' },
            { label: 'Servicios', route: 'services.index', icon: Scissors, permission: 'view-bookings', module: 'service_catalog' },
            { label: 'Sedes', route: 'locations.index', icon: MapPin, permission: 'view-bookings', module: 'locations' },
            { label: 'Profesionales', route: 'professionals.index', icon: UserRound, permission: 'view-bookings', module: 'professionals' },
            { label: 'Agenda', route: 'bookings.index', icon: CalendarDays, permission: 'view-bookings', module: 'bookings' },
            { label: 'Clientes', route: 'customers.index', icon: Contact, permission: 'view-customers', module: 'customers' },
            { label: 'Campañas', route: 'campaigns.index', icon: Megaphone, permission: 'view-campaigns', module: 'campaigns' },
        ],
    },
    {
        label: 'Administración',
        items: [
            { label: 'Usuarios y Roles', route: 'users.index', icon: Users, permission: 'manage-users', module: 'users_roles' },
            { label: 'Auditoría', route: 'audit.index', icon: History, permission: 'manage-settings', module: 'audit' },
            { label: 'Configuraciones', route: 'settings.index', icon: Settings, permission: 'manage-settings', module: 'settings' },
            { label: 'Integraciones', route: 'integrations.index', icon: Plug, permission: 'manage-settings', module: 'integrations' },
            { label: 'Planes', route: 'plans.index', icon: Layers, platform: true },
        ],
    },
];

/** Top bar tabs. For now a single tab covers the whole platform; like the menu, it is shown only to roles and plans that can open it. */
export const topTabs = [{ label: 'General', route: 'dashboard', permission: 'view-dashboard', module: 'dashboard' }];

/** Whether an item or tab is offered: the role grants its permission, the plan includes its module and, for platform items, the user administers the platform. */
export function isOffered(item, { permissions = [], modules = [], platform = false }) {
    return (!item.permission || permissions.includes(item.permission)) && (!item.module || modules.includes(item.module)) && (!item.platform || platform);
}

/** Sections and items the given access allows (empty sections are dropped). */
export function visibleNavigation(access) {
    return navigation
        .map((section) => ({ ...section, items: section.items.filter((item) => isOffered(item, access)) }))
        .filter((section) => section.items.length > 0);
}

/** Whether the current page is the route of an item or one of its pages (`customers.index` is also current on `customers.show`). */
export function isCurrentRoute(name, current) {
    return current(name) || (name.endsWith('.index') && current(name.replace(/\.index$/, '.*')));
}

/** Whether the current page belongs to the section (`current` is Ziggy's `route().current`). */
export function sectionHasRoute(section, current) {
    return section.items.some((item) => isCurrentRoute(item.route, current));
}

/** Whether a section starts unfolded: a section without heading is always open; a headed one starts open only when it holds the current page. */
export function startsOpen(section, hasActive) {
    return !section.label || hasActive;
}

/** The unfolded state after navigating: arriving at a page of the section unfolds it, leaving never folds it behind the user's back. */
export function openAfterNavigation(open, hasActive) {
    return open || hasActive;
}

/** The unfolded state after the user clicks the heading: always the opposite, even for the section that holds the current page. */
export function openAfterToggle(open) {
    return !open;
}

/** Whether the items of a section are hidden: only a headed section the user folded. */
export function isFolded(section, open) {
    return Boolean(section.label) && !open;
}
