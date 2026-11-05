import { Menu, X } from 'lucide-react';
import { visibleNavigation } from '@/config/navigation';
import useAccess from '@/Hooks/useAccess';
import { useBrandShort } from '@/Hooks/useBrand';
import NavSection from './NavSection';
import Tooltip from './Tooltip';

/**
 * Left navigation. From `md` up (any desktop width, a half-screen window included): full width, or collapsed to icons with the toggle (`collapsed`, remembered per
 * browser; until the user chooses, narrow windows start collapsed to leave room for the page). Mobile: sliding panel (`open` / `onClose`) with an overlay. When collapsed the labels stay in the page for screen readers (sr-only) and as a tooltip: nothing that the
 * modules and permissions hide is ever shown.
 *
 * The surface goes from light blue (top, navy text) through deep blue (navigation, white text)
 * to near white (bottom, navy text), so every zone keeps its own readable text color.
 */
export default function Sidebar({ workspace, open, onClose, collapsed = false, onToggleCollapsed }) {
    const sections = visibleNavigation(useAccess());
    const brand = useBrandShort();

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
                className={`brand-surface sidebar-surface fixed inset-y-0 left-0 z-50 flex w-64 flex-col text-white transition-transform duration-300 ease-out shrink-0 group/side md:sticky md:top-16 md:z-auto md:h-[calc(100dvh-4rem)] md:translate-x-0 md:self-start md:data-[collapsed=true]:w-16 ${
                    open ? 'translate-x-0' : '-translate-x-full'
                }`}
                aria-label="Navegación principal"
                data-collapsed={collapsed ? 'true' : 'false'}
            >
                <div className="flex h-16 shrink-0 items-center justify-between gap-3 px-4 md:group-data-[collapsed=true]/side:justify-center md:group-data-[collapsed=true]/side:px-0">
                    <div className="flex min-w-0 items-center gap-2.5 md:group-data-[collapsed=true]/side:hidden">
                        {/* The mascot with its phone (public/brand/mascota-celular.png, real alpha): sits directly on the blue surface, no tile or ring. Decorative: the brand name is next to it. */}
                        <img src="/brand/mascota-celular.png" alt="" width="400" height="455" decoding="async" className="h-11 w-auto shrink-0" />
                        <div className="min-w-0 leading-tight">
                            <p className="line-clamp-2 text-sm font-extrabold tracking-wide break-words" title={brand}>{brand}</p>
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
                    {onToggleCollapsed && (
                        <Tooltip content={collapsed ? 'Expandir el menú' : 'Contraer el menú'} className="hidden md:inline-flex">
                            {(describedBy) => (
                                <button
                                    type="button"
                                    onClick={onToggleCollapsed}
                                    aria-expanded={!collapsed}
                                    aria-label={collapsed ? 'Expandir el menú lateral' : 'Contraer el menú lateral'}
                                    {...describedBy}
                                    className="grid size-9 shrink-0 place-items-center rounded-full text-white/85 transition-colors hover:bg-white/15 hover:text-white focus-visible:outline-2 focus-visible:outline-white"
                                >
                                    <Menu className="size-5" aria-hidden="true" />
                                </button>
                            )}
                        </Tooltip>
                    )}
                </div>

                <nav className="mt-6 min-h-0 flex-1 space-y-4 overflow-x-hidden overflow-y-auto px-3 py-4 sidebar-scroll">
                    {sections.map((section) => (
                        <NavSection key={section.label ?? section.items[0].route} section={section} onNavigate={onClose} />
                    ))}
                </nav>

                {/* Collapsed (desktop): the sidebar shows icons only, so only the Workspace logo is shown. */}
                {workspace?.settings?.logoUrl && (
                    <img src={workspace.settings.logoUrl} alt={`Logo de ${workspace.name}`} className="mx-auto mb-3 hidden size-9 rounded-lg bg-white object-contain p-0.5 md:group-data-[collapsed=true]/side:block" />
                )}

                {workspace && (
                    <div className="m-3 rounded-xl bg-white/10 p-3 text-xs text-white ring-1 ring-white/20 md:group-data-[collapsed=true]/side:hidden">
                        <p className="flex items-center gap-2 font-semibold">
                            <span className="size-2 rounded-full bg-accent-green" aria-hidden="true" />
                            Workspace activo
                        </p>
                        <div className="mt-2 flex items-center gap-2">
                            {workspace.settings?.logoUrl && (
                                <img src={workspace.settings.logoUrl} alt="" className="size-9 shrink-0 rounded-lg bg-white object-contain p-0.5" />
                            )}
                            <div className="min-w-0">
                                <p className="truncate font-bold tracking-wide">{workspace.code}</p>
                                <p className="truncate text-white/70">{workspace.organization}</p>
                            </div>
                        </div>
                    </div>
                )}
            </aside>
        </>
    );
}
