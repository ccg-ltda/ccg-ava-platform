import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

const NAV_ITEMS = [
    { name: 'Dashboard', href: '/dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1', roles: null },
    { name: 'Usuarios', href: '/users', icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z', roles: ['admin'] },
];

export default function AuthenticatedLayout({ children }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const userRoles = user?.roles || [];

    const [sidebarOpen, setSidebarOpen] = useState(false);

    const visibleNavItems = NAV_ITEMS.filter(
        (item) => !item.roles || item.roles.some((r) => userRoles.includes(r)),
    );

    return (
        <div className="flex min-h-screen circuit-bg text-slate-200">
            {/* Sidebar Cyberpunk */}
            <aside
                className={`fixed inset-y-0 left-0 z-40 w-64 transform bg-[#0a0f18]/90 backdrop-blur-xl border-r border-cyan-500/20 text-white transition-transform duration-300 ease-in-out lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}
            >
                <div className="flex h-16 items-center justify-center border-b border-cyan-500/20 px-4">
                    <span className="font-orbitron text-lg font-black tracking-widest text-cyan-400 drop-shadow-[0_0_10px_rgba(0,240,255,0.6)]">
                        PLATAFORMA CCG
                    </span>
                </div>
                <nav className="mt-6 space-y-2 px-3">
                    {visibleNavItems.map((item) => (
                        <Link
                            key={item.name}
                            href={item.href}
                            className="flex items-center rounded-xl px-4 py-3 text-sm font-orbitron tracking-wider text-slate-300 transition-all hover:bg-cyan-500/10 hover:text-cyan-400 hover:shadow-[0_0_15px_rgba(0,240,255,0.2)] border border-transparent hover:border-cyan-500/30"
                            onClick={() => setSidebarOpen(false)}
                        >
                            <svg
                                className="me-3 h-5 w-5 text-cyan-400 drop-shadow-[0_0_6px_rgba(0,240,255,0.8)]"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth="2"
                                    d={item.icon}
                                />
                            </svg>
                            {item.name}
                        </Link>
                    ))}
                </nav>
            </aside>

            {/* Main content */}
            <div className="flex flex-1 flex-col lg:ml-64">
                {/* Header Cyberpunk */}
                <header className="sticky top-0 z-30 bg-[#0a0f18]/80 backdrop-blur-xl border-b border-cyan-500/20 shadow-lg shadow-cyan-500/5">
                    <div className="flex items-center justify-between px-4 py-3 sm:px-6">
                        <button
                            onClick={() => setSidebarOpen(!sidebarOpen)}
                            className="rounded-lg p-2 text-cyan-400 hover:bg-cyan-500/10 hover:shadow-[0_0_10px_rgba(0,240,255,0.3)] lg:hidden transition-all"
                        >
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <div className="flex items-center gap-4 ml-auto">
                            <span className="font-orbitron text-xs tracking-wider text-cyan-300/80">
                                {user?.email}
                            </span>
                            {userRoles.length > 0 && (
                                <span className="rounded-full bg-cyan-500/10 border border-cyan-500/30 px-3 py-1 text-[10px] font-orbitron tracking-widest text-cyan-400 shadow-[0_0_8px_rgba(0,240,255,0.3)]">
                                    {userRoles[0].toUpperCase()}
                                </span>
                            )}
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="rounded-xl bg-red-600/20 border border-red-500/40 px-3.5 py-1.5 text-xs font-orbitron tracking-wider text-red-400 transition-all hover:bg-red-600 hover:text-white hover:shadow-[0_0_15px_rgba(239,68,68,0.6)]"
                            >
                                CERRAR SESIÓN
                            </Link>
                        </div>
                    </div>
                </header>

                {/* Content */}
                <main className="flex-1 overflow-y-auto p-6 sm:p-10">
                    {children}
                </main>
            </div>
        </div>
    );
}
