import { Link } from '@inertiajs/react';
import { pendingLabel, usePending } from '@/lib/pendingStore';

/** Sidebar entry. On tablet (collapsed sidebar) only the icon is shown. */
export default function NavItem({ item, active, onNavigate }) {
    const Icon = item.icon;
    // Only Conversaciones shows it, and only for who can read them (the number is 0 otherwise): conversations waiting for a person's answer.
    const pending = usePending();
    const badge = item.route === 'conversations.index' ? pending : 0;
    const state = active
        ? 'bg-white/15 text-white shadow-sm ring-1 ring-white/25'
        : 'text-white/80 hover:bg-white/10 hover:text-white';

    return (
        <Link
            href={route(item.route)}
            onClick={onNavigate}
            title={item.label}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-3 rounded-full px-3 py-2.5 lg:group-data-[collapsed=true]/side:justify-center text-sm font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white md:justify-center lg:justify-start ${state}`}
        >
            <span className="relative shrink-0">
                <Icon className="size-5" aria-hidden="true" />
                {badge > 0 && (
                    <span aria-hidden="true" className="absolute -top-1.5 -right-2 hidden min-w-4 rounded-full bg-emerald-500 px-1 text-center text-[10px] leading-4 font-bold text-white ring-2 ring-primary md:block lg:group-data-[collapsed=true]/side:block lg:hidden">
                        {pendingLabel(badge)}
                    </span>
                )}
            </span>
            <span className="md:sr-only lg:not-sr-only lg:group-data-[collapsed=true]/side:sr-only">{item.label}</span>
            {badge > 0 && (
                <>
                    <span aria-hidden="true" className="ml-auto min-w-5 rounded-full bg-emerald-500 px-1.5 text-center text-[11px] leading-5 font-bold text-white md:hidden lg:inline-block lg:group-data-[collapsed=true]/side:hidden">
                        {pendingLabel(badge)}
                    </span>
                    <span className="sr-only">, {badge} {badge === 1 ? 'conversación pendiente' : 'conversaciones pendientes'} de respuesta</span>
                </>
            )}
        </Link>
    );
}
