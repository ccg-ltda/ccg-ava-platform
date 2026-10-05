import { ChevronLeft, ChevronRight } from 'lucide-react';
import Select from '@/Components/Select';

const button =
    'grid size-9 place-items-center rounded-lg border border-line bg-card text-ink transition-colors duration-150 hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-card';

/**
 * Footer of a paginated list. `meta` = { current_page, last_page, from, to, total }.
 * Pass `perPage`, `perPageOptions` and `onPerPage` to also offer a page-size selector.
 */
export default function Pagination({ meta, onPage, noun = 'registros', perPage, perPageOptions, onPerPage }) {
    if (!meta || meta.total === 0) return null;

    return (
        <nav className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm sm:px-6" aria-label="Paginación">
            <p className="text-ink-muted">
                {meta.from}–{meta.to} de {meta.total} {noun}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                {onPerPage && (
                    <div className="me-2 flex w-full items-center gap-2 text-ink-muted sm:w-auto">
                        Mostrar:
                        <Select
                            size="sm"
                            anchor="top end"
                            aria-label={`${noun} por página`}
                            className="w-20"
                            value={perPage}
                            onChange={onPerPage}
                            options={perPageOptions.map((option) => ({ value: option, label: String(option) }))}
                        />
                    </div>
                )}
                <button type="button" className={button} onClick={() => onPage(meta.current_page - 1)} disabled={meta.current_page <= 1} aria-label="Página anterior">
                    <ChevronLeft className="size-4" aria-hidden="true" />
                </button>
                <span className="px-1 text-ink-muted">
                    Página {meta.current_page} de {meta.last_page}
                </span>
                <button type="button" className={button} onClick={() => onPage(meta.current_page + 1)} disabled={meta.current_page >= meta.last_page} aria-label="Página siguiente">
                    <ChevronRight className="size-4" aria-hidden="true" />
                </button>
            </div>
        </nav>
    );
}
