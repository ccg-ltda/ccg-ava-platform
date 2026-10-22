import { ChevronDown } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { isCurrentRoute, isFolded, openAfterNavigation, openAfterToggle, sectionHasRoute, startsOpen } from '@/config/navigation';
import NavItem from './NavItem';

/**
 * One section of the sidebar. Every section that has a heading folds and unfolds behind it (mobile and desktop), including the one
 * that holds the current page: the user decides, and a section opens by itself only when navigation arrives at one of its pages.
 * The heading never wraps (long titles are cut with an ellipsis and keep their full text in the tooltip) and the chevron keeps its
 * size, so unfolding never changes the width or the height of the heading. On tablet the sidebar shows icons only, so the items
 * of every section stay visible there. A section without heading (the first one) is always open.
 */
export default function NavSection({ section, onNavigate }) {
    const panelId = useId();
    const hasActive = sectionHasRoute(section, (name) => route().current(name));
    const [open, setOpen] = useState(() => startsOpen(section, hasActive));
    const foldable = Boolean(section.label);
    const folded = isFolded(section, open);

    // Arriving at a page of this section (a link, the browser's back button) unfolds it; leaving never folds it behind the user's back.
    useEffect(() => {
        setOpen((current) => openAfterNavigation(current, hasActive));
    }, [hasActive]);

    return (
        <div className="space-y-1">
            {foldable && (
                <button
                    type="button"
                    onClick={() => setOpen(openAfterToggle(open))}
                    aria-expanded={open}
                    aria-controls={panelId}
                    title={section.label}
                    className="flex h-8 w-full min-w-0 items-center gap-1.5 rounded-full px-3 text-left lg:group-data-[collapsed=true]/side:hidden text-[10px] font-bold uppercase tracking-[0.1em] text-white/70 hover:text-white focus-visible:outline-2 focus-visible:outline-white md:hidden lg:flex"
                >
                    <span className="min-w-0 flex-1 truncate">{section.label}</span>
                    {folded && hasActive && <span className="size-1.5 shrink-0 rounded-full bg-accent-green" aria-label="Contiene la página actual" />}
                    <ChevronDown className={`size-3.5 shrink-0 transition-transform duration-150 ${folded ? '-rotate-90' : ''}`} aria-hidden="true" />
                </button>
            )}
            <div className="hidden h-px bg-white/20 md:block lg:hidden lg:group-data-[collapsed=true]/side:block" />
            <div id={panelId} className={`space-y-1 ${folded ? 'hidden md:block lg:hidden lg:group-data-[collapsed=true]/side:block' : ''}`}>
                {section.items.map((item) => (
                    <NavItem key={item.route} item={item} active={isCurrentRoute(item.route, (name) => route().current(name))} onNavigate={onNavigate} />
                ))}
            </div>
        </div>
    );
}
