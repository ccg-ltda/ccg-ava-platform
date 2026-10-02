import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link, router } from '@inertiajs/react';
import { ChevronDown, LogOut, Menu as MenuIcon, User } from 'lucide-react';
import { topTabs } from '@/config/navigation';
import { roleTone } from '@/config/roles';
import Badge from './Badge';

const initials = (name = '') =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

/** Navy top bar: brand, the single "General" tab, user menu and logout. Decoration stays behind the controls. */
export default function Topbar({ user, role, onMenu }) {
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

            <p className="text-sm font-extrabold tracking-[0.2em] uppercase">CCG Avachat</p>

            <nav className="ml-2 hidden items-center gap-1 sm:flex" aria-label="Secciones">
                {topTabs.map((tab) => (
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

            <Menu as="div" className="relative">
                <MenuButton className="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 transition-colors duration-150 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white">
                    <span className="grid size-8 place-items-center rounded-full bg-white/15 text-xs font-bold ring-1 ring-white/25">
                        {initials(user?.name)}
                    </span>
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
                        <Badge tone={roleTone(role)} className="mt-2">
                            {role}
                        </Badge>
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
