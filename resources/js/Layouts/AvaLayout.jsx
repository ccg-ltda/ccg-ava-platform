import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AccessProvider } from '@/Components/Access/AccessContext';
import ThemeToggle from '@/Components/Access/ThemeToggle';
import ToastContainer from '@/Components/Access/ToastContainer';
import { AvaBackground } from '@/Layouts/AccessLayout';

const icon = (path) => (
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d={path} />
    </svg>
);

// Only routes that exist. `roles` limits an entry to users whose role in the active Workspace matches.
const NAV_ITEMS = [
    { label: 'Dashboard', route: 'dashboard', icon: icon('M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10') },
    { label: 'Usuarios', route: 'users.index', roles: ['admin'], icon: icon('M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M10 11a4 4 0 100-8 4 4 0 000 8zM21 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75') },
    { label: 'Perfil', route: 'profile.edit', icon: icon('M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z') },
];

function Shell({ children }) {
    const { auth, workspace } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const role = auth.user?.roles?.[0];
    const items = NAV_ITEMS.filter((item) => !item.roles || item.roles.includes(role));

    return (
        <div className="ava">
            <AvaBackground />
            <ToastContainer />

            <div className="app-shell">
                <header className="app-topbar">
                    <button
                        type="button"
                        className="app-menu-button"
                        onClick={() => setMenuOpen((open) => !open)}
                        aria-label="Menú"
                        aria-expanded={menuOpen}
                    >
                        <svg viewBox="0 0 24 24">
                            <path d="M3 6h18M3 12h18M3 18h18" />
                        </svg>
                    </button>

                    <div className="app-brand">
                        <div className="logo-icon">
                            <svg viewBox="0 0 24 24">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                            </svg>
                        </div>
                        <span>Ava Platform</span>
                    </div>

                    {workspace && (
                        <div className="app-chip">
                            <span>{workspace.organization}</span>
                            <strong>{workspace.code}</strong>
                        </div>
                    )}

                    <div className="app-spacer" />

                    <div className="app-user">
                        <span>{auth.user?.name}</span>
                        <span>
                            <span className="role-badge">{role}</span>
                        </span>
                    </div>

                    <ThemeToggle inline />

                    <button type="button" className="app-logout" onClick={() => router.post(route('logout'))}>
                        Cerrar sesión
                    </button>
                </header>

                <div className="app-body">
                    <aside className={`app-sidebar${menuOpen ? ' open' : ''}`}>
                        <nav className="app-nav">
                            {items.map((item) => (
                                <Link
                                    key={item.route}
                                    href={route(item.route)}
                                    className={`app-nav-link${route().current(item.route) ? ' active' : ''}`}
                                    onClick={() => setMenuOpen(false)}
                                >
                                    {item.icon}
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                    </aside>

                    <main className="app-main">{children}</main>
                </div>
            </div>
        </div>
    );
}

/** Shell of the internal application (top bar, navigation, themed background). */
export default function AvaLayout({ children }) {
    return (
        <AccessProvider>
            <Shell>{children}</Shell>
        </AccessProvider>
    );
}
