import { Bot, Menu, X } from 'lucide-react';
import { visibleNavigation } from '@/config/navigation';
import useAccess from '@/Hooks/useAccess';
import { useBrandShort } from '@/Hooks/useBrand';
import NavSection from './NavSection';
import Tooltip from './Tooltip';

/**
 * Left navigation. Desktop: full width, or collapsed to icons with the toggle (`collapsed`, remembered per browser). Tablet: collapsed to icons. Mobile: sliding
 * panel (`open` / `onClose`) with an overlay. When collapsed the labels stay in the page for screen readers (sr-only) and as a tooltip: nothing that the
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
                className={`brand-surface sidebar-surface fixed inset-y-0 left-0 z-50 flex w-64 flex-col text-white transition-transform duration-300 ease-out md:sticky md:top-16 md:z-auto md:h-[calc(100dvh-4rem)] md:w-16 md:translate-x-0 md:self-start lg:w-64 shrink-0 lg:data-[collapsed=true]:w-16 group/side ${
                    open ? 'translate-x-0' : '-translate-x-full'
                }`}
                aria-label="Navegación principal"
                data-collapsed={collapsed ? 'true' : 'false'}
            >
                <div className="flex h-16 shrink-0 items-center justify-between gap-3 px-4 md:justify-center lg:justify-between lg:group-data-[collapsed=true]/side:justify-center">
                    <div className="flex min-w-0 items-center gap-3 lg:group-data-[collapsed=true]/side:hidden">
                        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white/15 ring-1 ring-white/25">
                            <Bot className="size-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0 leading-tight md:hidden lg:block lg:group-data-[collapsed=true]/side:hidden">
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
                        <Tooltip content={collapsed ? 'Expandir el menú' : 'Contraer el menú'} className="hidden lg:inline-flex">
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

                {/* Tablet, or desktop collapsed: the sidebar shows icons only, so only the Workspace logo is shown. */}
                {workspace?.settings?.logoUrl && (
                    <img src={workspace.settings.logoUrl} alt={`Logo de ${workspace.name}`} className="mx-auto mb-3 hidden size-9 rounded-lg bg-white object-contain p-0.5 md:block lg:hidden lg:group-data-[collapsed=true]/side:block" />
                )}

                {workspace && (
                    <div className="m-3 rounded-xl bg-white/10 p-3 text-xs text-white ring-1 ring-white/20 md:hidden lg:block lg:group-data-[collapsed=true]/side:hidden">
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
