import { Bot, X } from 'lucide-react';
import { visibleNavigation } from '@/config/navigation';
import NavItem from './NavItem';

/**
 * Left navigation. Desktop: full width. Tablet: collapsed to icons. Mobile: sliding panel
 * (`open` / `onClose`) with an overlay.
 *
 * The surface goes from light blue (top, navy text) through deep blue (navigation, white text)
 * to near white (bottom, navy text), so every zone keeps its own readable text color.
 */
export default function Sidebar({ permissions, workspace, open, onClose }) {
    const sections = visibleNavigation(permissions);

    return (
        <>
            <div
                className={`fixed inset-0 z-40 bg-ink/50 transition-opacity duration-200 md:hidden ${
                    open ? 'opacity-100' : 'pointer-events-none opacity-0'
                }`}
                onClick={onClose}
                aria-hidden="true"
            />

            <aside
                className={`brand-surface sidebar-surface fixed inset-y-0 left-0 z-50 flex w-64 flex-col text-white transition-transform duration-300 ease-out md:sticky md:top-16 md:z-auto md:h-[calc(100dvh-4rem)] md:w-16 md:translate-x-0 md:self-start lg:w-64 ${
                    open ? 'translate-x-0' : '-translate-x-full'
                }`}
                aria-label="Navegación principal"
            >
                <div className="flex h-16 shrink-0 items-center justify-between gap-3 px-4 md:justify-center lg:justify-between">
                    <div className="flex items-center gap-3">
                        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white/15 ring-1 ring-white/25">
                            <Bot className="size-5" aria-hidden="true" />
                        </span>
                        <div className="leading-tight md:hidden lg:block">
                            <p className="text-sm font-extrabold tracking-wide">Ava</p>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/70">Platform</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar menú"
                        className="grid size-9 place-items-center rounded-full hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white md:hidden"
                    >
                        <X className="size-5" aria-hidden="true" />
                    </button>
                </div>

                <nav className="mt-6 flex-1 space-y-5 overflow-y-auto px-3 py-4">
                    {sections.map((section) => (
                        <div key={section.label} className="space-y-1">
                            <p className="px-3 pb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-white/70 md:hidden lg:block">
                                {section.label}
                            </p>
                            <div className="hidden h-px bg-white/20 md:block lg:hidden" />
                            {section.items.map((item) => (
                                <NavItem key={item.route} item={item} active={route().current(item.route)} onNavigate={onClose} />
                            ))}
                        </div>
                    ))}
                </nav>

                {workspace && (
                    <div className="m-3 rounded-xl bg-white/10 p-3 text-xs text-white ring-1 ring-white/20 md:hidden lg:block">
                        <p className="flex items-center gap-2 font-semibold">
                            <span className="size-2 rounded-full bg-accent-green" aria-hidden="true" />
                            Workspace activo
                        </p>
                        <p className="mt-1 truncate font-bold tracking-wide">{workspace.code}</p>
                        <p className="truncate text-white/70">{workspace.organization}</p>
                    </div>
                )}
            </aside>
        </>
    );
}
