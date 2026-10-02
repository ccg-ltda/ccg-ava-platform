import { BarChart3, Plug, Settings, Users } from 'lucide-react';

/**
 * Single definition of the sidebar menu. Every `route` is a real Laravel route name;
 * `roles` limits an item to users whose role in the active Workspace is listed (omit for everyone).
 */
export const navigation = [
    {
        label: 'Analytics',
        items: [{ label: 'Reportes', route: 'dashboard', icon: BarChart3 }],
    },
    {
        label: 'Sistema',
        items: [
            { label: 'Configuraciones', route: 'settings.index', icon: Settings, roles: ['admin'] },
            { label: 'Integraciones', route: 'integrations.index', icon: Plug, roles: ['admin'] },
            { label: 'Usuarios y Roles', route: 'users.index', icon: Users, roles: ['admin'] },
        ],
    },
];

/** Top bar tabs. For now a single tab covers the whole platform. */
export const topTabs = [{ label: 'General', route: 'dashboard' }];

/** Sections and items the given role can see (empty sections are dropped). */
export function visibleNavigation(role) {
    return navigation
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => !item.roles || item.roles.includes(role)),
        }))
        .filter((section) => section.items.length > 0);
}
