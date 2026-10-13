import { BarChart3, Bot, CalendarDays, History, Layers, LayoutDashboard, MessagesSquare, Plug, Scissors, Settings, UserRound, Users } from 'lucide-react';

/**
 * Single definition of the sidebar menu. A section without `label` is shown without a heading. Every `route` is a real Laravel route name;
 * `permission` limits an item to users whose role in the active Workspace grants it (the same permission
 * the route requires in routes/web.php; omit for everyone). `module` limits it to Workspaces whose plan includes that module
 * (the same key as `workspace.module:<key>` on its routes), and `platform` to the CCG administration (superuser inside the
 * administrative Workspace). This only decides what is shown: the server enforces every route on its own.
 */
export const navigation = [
    {
        // The first entries are the Dashboard (the home page) and the assistants themselves, so the section carries no heading.
        label: null,
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, permission: 'view-dashboard', module: 'dashboard' },
            { label: 'Asistentes', route: 'chatbots.index', icon: Bot, permission: 'view-chatbots', module: 'assistants' },
            { label: 'Conversaciones', route: 'conversations.index', icon: MessagesSquare, permission: 'view-conversations', module: 'conversations' },
        ],
    },
    {
        label: 'Servicios y Reservas',
        items: [
            { label: 'Agenda', route: 'bookings.index', icon: CalendarDays, permission: 'view-bookings', module: 'bookings' },
            { label: 'Servicios', route: 'services.index', icon: Scissors, permission: 'view-bookings', module: 'service_catalog' },
            { label: 'Profesionales', route: 'professionals.index', icon: UserRound, permission: 'view-bookings', module: 'professionals' },
        ],
    },
    {
        label: 'Analytics',
        items: [
            { label: 'Reportes', route: 'reports.index', icon: BarChart3, permission: 'view-dashboard', module: 'reports' },
            { label: 'Auditoría', route: 'audit.index', icon: History, permission: 'manage-settings', module: 'audit' },
        ],
    },
    {
        label: 'Sistemas',
        items: [
            { label: 'Configuraciones', route: 'settings.index', icon: Settings, permission: 'manage-settings', module: 'settings' },
            { label: 'Integraciones', route: 'integrations.index', icon: Plug, permission: 'manage-settings', module: 'integrations' },
            { label: 'Usuarios y Roles', route: 'users.index', icon: Users, permission: 'manage-users', module: 'users_roles' },
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
