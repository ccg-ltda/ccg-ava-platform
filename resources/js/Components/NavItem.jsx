import { Link } from '@inertiajs/react';

/** Sidebar entry. On tablet (collapsed sidebar) only the icon is shown. */
export default function NavItem({ item, active, onNavigate }) {
    const Icon = item.icon;
    const state = active
        ? 'bg-white/15 text-white shadow-sm ring-1 ring-white/25'
        : 'text-white/80 hover:bg-white/10 hover:text-white';

    return (
        <Link
            href={route(item.route)}
            onClick={onNavigate}
            title={item.label}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-3 rounded-full px-3 py-2.5 text-sm font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white md:justify-center lg:justify-start ${state}`}
        >
            <Icon className="size-5 shrink-0" aria-hidden="true" />
            <span className="md:hidden lg:inline">{item.label}</span>
        </Link>
    );
}
