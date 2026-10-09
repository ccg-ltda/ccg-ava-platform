import { Link } from '@inertiajs/react';
import { handlingStates } from '@/config/conversations';

/** The inbox filter by who answers: all, AI, waiting for an agent, in attention and resolved, each with its count. */
export default function StatusFilter({ counts, current, hrefFor }) {
    const total = Object.values(counts).reduce((sum, value) => sum + value, 0);
    const items = [{ id: 'all', label: 'Todas', count: total }, ...Object.entries(handlingStates).map(([id, state]) => ({ id, label: state.short, count: counts[id] ?? 0 }))];

    return (
        <nav aria-label="Filtrar conversaciones por estado" className="flex gap-2 overflow-x-auto">
            {items.map(({ id, label, count }) => {
                const active = current === id;

                return (
                    <Link
                        key={id}
                        href={hrefFor(id)}
                        preserveScroll
                        preserveState
                        aria-current={active ? 'true' : undefined}
                        className={`inline-flex shrink-0 items-center gap-2 rounded-full border px-3 py-1 text-sm font-semibold outline-none transition-colors focus-visible:outline-2 focus-visible:outline-primary ${active ? 'border-primary bg-primary-soft text-primary' : 'border-line bg-card text-ink-muted hover:text-ink'}`}
                    >
                        {label}
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${active ? 'bg-primary/15' : 'bg-canvas'}`}>{count}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
