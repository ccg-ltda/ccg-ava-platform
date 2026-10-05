import { BarChart3, Plug, Settings, Users } from 'lucide-react';

/**
 * Single definition of the sidebar menu. Every `route` is a real Laravel route name;
 * `permission` limits an item to users whose role in the active Workspace grants it (the same permission
 * the route requires in routes/web.php; omit for everyone).
 */
export const navigation = [
    {
        label: 'Analytics',
        items: [{ label: 'Reportes', route: 'dashboard', icon: BarChart3 }],
    },
    {
        label: 'Sistema',
        items: [
            { label: 'Configuraciones', route: 'settings.index', icon: Settings, permission: 'manage-settings' },
            { label: 'Integraciones', route: 'integrations.index', icon: Plug, permission: 'manage-settings' },
            { label: 'Usuarios y Roles', route: 'users.index', icon: Users, permission: 'manage-users' },
        ],
    },
];

/** Top bar tabs. For now a single tab covers the whole platform. */
export const topTabs = [{ label: 'General', route: 'dashboard' }];

/** Sections and items the given permissions allow (empty sections are dropped). */
export function visibleNavigation(permissions = []) {
    return navigation
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => !item.permission || permissions.includes(item.permission)),
        }))
        .filter((section) => section.items.length > 0);
}
