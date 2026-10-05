import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Check, ChevronDown, Globe2, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import Badge from './Badge';

/** The "every Workspace" choice of the administrative views. Its id is what the server expects in `?workspace=`. */
export const ALL_WORKSPACES = { id: 'all', name: 'Todos los Workspaces', code: null, organization: null };

/**
 * Searchable Workspace selector. It never loads the whole list: it asks the server for a few matches as the user
 * types (GET workspaces.search), so it works the same with three Workspaces or three thousand. Controlled:
 * `value` is the selected option ({ id, name, code, organization, roles? }) or null; `onChange(option)`.
 *
 * `purpose`: "view" (choose what a report looks at; superusers in the administrative Workspace only, and
 * `includeAll` adds "Todos los Workspaces") or "assign" (Workspaces where people may be placed, with their roles).
 * The server decides what each purpose may find; this component is only the control.
 */
export default function WorkspaceCombobox({ id, purpose, value, onChange, includeAll = false, placeholder = 'Buscar Workspace', invalid = false, disabled = false, className = '' }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [hasMore, setHasMore] = useState(false);
    const [state, setState] = useState('idle'); // idle | loading | error
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) return undefined;

        const controller = new AbortController();
        setState('loading');

        // Typing pauses briefly before asking, so each keystroke is not a request.
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(route('workspaces.search', { q: query.trim(), purpose }), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) throw new Error(String(response.status));

                const body = await response.json();
                setResults(body.data);
                setHasMore(body.hasMore);
                setState('idle');
            } catch (error) {
                if (error.name !== 'AbortError') setState('error');
            }
        }, query === '' ? 0 : 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query, purpose, open]);

    const showAll = includeAll && 'todos los workspaces'.includes(query.trim().toLowerCase());
    const options = showAll ? [ALL_WORKSPACES, ...results] : results;
    const empty = state === 'idle' && options.length === 0;

    return (
        <Combobox
            immediate
            by="id"
            value={value}
            onChange={onChange}
            disabled={disabled}
            onClose={() => setQuery('')}
        >
            {() => (
                <div className={`relative ${className}`}>
                    <ComboboxInput
                        id={id}
                        aria-invalid={invalid || undefined}
                        autoComplete="off"
                        spellCheck={false}
                        className="field pr-16"
                        placeholder={placeholder}
                        displayValue={(option) => (option ? (option.code ? `${option.name} (${option.code})` : option.name) : '')}
                        onChange={(event) => setQuery(event.target.value)}
                        onFocus={() => setOpen(true)}
                        onBlur={() => setOpen(false)}
                    />
                    <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center gap-1.5 text-ink-muted">
                        {state === 'loading' && <Loader2 className="size-4 animate-spin" aria-label="Buscando" />}
                    </span>
                    <ComboboxButton className="absolute inset-y-0 right-0 flex items-center px-3 text-ink-muted" aria-label="Mostrar Workspaces" onClick={() => setOpen(true)}>
                        <ChevronDown className="size-4" aria-hidden="true" />
                    </ComboboxButton>

                    <ComboboxOptions
                        anchor={{ to: 'bottom start', gap: 4 }}
                        transition
                        className="z-50 w-(--input-width) [--anchor-max-height:18rem] overflow-auto rounded-lg border border-line bg-card p-1 shadow-card-hover outline-none transition duration-100 ease-out empty:invisible data-closed:scale-95 data-closed:opacity-0"
                    >
                        {options.map((option) => (
                            <ComboboxOption
                                key={option.id}
                                value={option}
                                className="group flex cursor-pointer items-center justify-between gap-3 rounded-md px-3 py-2 text-sm text-ink select-none data-focus:bg-primary/15"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    {option.id === 'all' && <Globe2 className="size-4 shrink-0 text-accent-blue" aria-hidden="true" />}
                                    <span className="min-w-0">
                                        <span className="block truncate group-data-selected:font-semibold">{option.name}</span>
                                        {(option.code || option.organization) && (
                                            <span className="block truncate text-xs text-ink-muted">
                                                {option.code && <span className="font-mono">{option.code}</span>}
                                                {option.code && option.organization && ' · '}
                                                {option.organization}
                                            </span>
                                        )}
                                    </span>
                                </span>
                                <span className="flex shrink-0 items-center gap-2">
                                    {option.isAdministrative && <Badge tone="violet">Admin</Badge>}
                                    {option.isActive === false && <Badge tone="neutral">Inactivo</Badge>}
                                    <Check className="hidden size-4 group-data-selected:block" aria-hidden="true" />
                                </span>
                            </ComboboxOption>
                        ))}

                        {state === 'loading' && options.length === 0 && <p className="px-3 py-3 text-sm text-ink-muted">Buscando...</p>}
                        {state === 'error' && <p className="px-3 py-3 text-sm text-danger" role="alert">No se pudo buscar. Inténtalo de nuevo.</p>}
                        {empty && <p className="px-3 py-3 text-sm text-ink-muted">{query.trim() ? `Sin resultados para «${query.trim()}»` : 'No hay Workspaces disponibles'}</p>}
                        {hasMore && state === 'idle' && <p className="border-t border-line px-3 py-2 text-xs text-ink-muted">Hay más resultados: escribe para acotar la búsqueda.</p>}
                    </ComboboxOptions>
                </div>
            )}
        </Combobox>
    );
}
