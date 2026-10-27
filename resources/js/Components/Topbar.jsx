import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import RoleLabel from '@/Components/RoleLabel';
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, CircleHelp, LogOut, Menu as MenuIcon, User } from 'lucide-react';
import { useState } from 'react';
import { isOffered, topTabs } from '@/config/navigation';
import useAccess from '@/Hooks/useAccess';
import { useBrandShort } from '@/Hooks/useBrand';
import Avatar from './Avatar';
import Badge from './Badge';
import HelpPanel from './HelpPanel';

/** Navy top bar: brand, the single "General" tab, user menu and logout. Decoration stays behind the controls. */
export default function Topbar({ user, role, onMenu }) {
    const access = useAccess();
    const brand = useBrandShort();
    const hasHelp = Boolean(usePage().props.help);
    const [helpOpen, setHelpOpen] = useState(false);

    return (
        <header className="brand-surface topbar-surface sticky top-0 z-30 flex h-16 items-center gap-3 px-4 text-white shadow-card sm:px-6">
            <button
                type="button"
                onClick={onMenu}
                aria-label="Abrir menú"
                className="grid size-10 place-items-center rounded-full text-white/90 transition-colors duration-150 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white md:hidden"
            >
                <MenuIcon className="size-5" aria-hidden="true" />
            </button>

            <p className="max-w-[9rem] truncate text-sm font-extrabold tracking-[0.12em] uppercase sm:max-w-[16rem] sm:tracking-[0.2em]" title={brand}>{brand}</p>

            <nav className="ml-2 hidden items-center gap-1 sm:flex" aria-label="Secciones">
                {topTabs.filter((tab) => isOffered(tab, access)).map((tab) => (
                    <Link
                        key={tab.label}
                        href={route(tab.route)}
                        className="rounded-full bg-white/15 px-4 py-1.5 text-sm font-semibold ring-1 ring-white/25 focus-visible:outline-2 focus-visible:outline-white"
                        aria-current="page"
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>

            <div className="flex-1" />

            {hasHelp && (
                <>
                    <button
                        type="button"
                        onClick={() => setHelpOpen(true)}
                        aria-haspopup="dialog"
                        className="flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold transition-colors duration-150 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white"
                    >
                        <CircleHelp className="size-4" aria-hidden="true" />
                        <span className="hidden sm:inline">Ayuda</span>
                        <span className="sr-only sm:hidden">Ayuda</span>
                    </button>
                    <HelpPanel show={helpOpen} onClose={() => setHelpOpen(false)} />
                </>
            )}

            <Menu as="div" className="relative">
                <MenuButton className="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 transition-colors duration-150 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white">
                    <Avatar name={user?.name} photoUrl={user?.photoUrl} className="size-8 bg-white/15 text-xs ring-1 ring-white/25" />
                    <span className="hidden text-left leading-tight lg:block">
                        <span className="block max-w-40 truncate text-sm font-semibold">{user?.name}</span>
                        <span className="block text-[10px] font-semibold uppercase tracking-wider text-white/70">{role}</span>
                    </span>
                    <ChevronDown className="size-4 text-white/70" aria-hidden="true" />
                </MenuButton>

                <MenuItems
                    transition
                    className="absolute right-0 mt-2 w-60 origin-top-right rounded-card border border-line bg-card p-2 text-ink shadow-card-hover transition duration-150 ease-out data-closed:scale-95 data-closed:opacity-0"
                >
                    <div className="px-3 py-2">
                        <p className="truncate text-sm font-semibold">{user?.name}</p>
                        <p className="truncate text-xs text-ink-muted">{user?.email}</p>
                        <RoleLabel role={role} className="mt-1" />
                    </div>
                    <MenuItem>
                        <Link
                            href={route('profile.edit')}
                            className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium data-focus:bg-canvas"
                        >
                            <User className="size-4 text-ink-muted" aria-hidden="true" />
                            Mi perfil
                        </Link>
                    </MenuItem>
                </MenuItems>
            </Menu>

            <button
                type="button"
                onClick={() => router.post(route('logout'))}
                className="flex items-center gap-2 rounded-full border border-white/25 px-3 py-1.5 text-sm font-semibold transition-colors duration-150 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white"
            >
                <LogOut className="size-4" aria-hidden="true" />
                <span className="hidden sm:inline">Cerrar sesión</span>
            </button>
        </header>
    );
}
