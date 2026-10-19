import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Check, ChevronDown, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Searchable selector of the existing accounts that may be added to ONE Workspace. It opens with the first ones and filters by name or email as the user
 * types (GET workspaces.members.eligible). The server only ever lists accounts that pass the rules of adding a member to that Workspace (not a member
 * yet, active, not an internal account of the platform for a client), and the add action checks them again: this is only the control.
 * Controlled: `value` is the chosen option ({ id, name, email }) or null; `onChange(option)`.
 */
export default function UserCombobox({ id, workspaceId, value, onChange, placeholder = 'Buscar usuario por nombre o correo', invalid = false, disabled = false, className = '' }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [hasMore, setHasMore] = useState(false);
    const [state, setState] = useState('idle'); // idle | loading | error
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) return undefined;

        const controller = new AbortController();
        setState('loading');

        const timer = setTimeout(async () => {
            try {
                const response = await fetch(route('workspaces.members.eligible', { workspace: workspaceId, q: query.trim() }), {
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
    }, [query, workspaceId, open]);

    return (
        <Combobox immediate by="id" value={value} onChange={onChange} disabled={disabled} onClose={() => setQuery('')}>
            {() => (
                <div className={`relative ${className}`}>
                    <ComboboxInput
                        id={id}
                        aria-invalid={invalid || undefined}
                        autoComplete="off"
                        spellCheck={false}
                        className="field pr-16"
                        placeholder={placeholder}
                        displayValue={(option) => (option ? `${option.name} (${option.email})` : '')}
                        onChange={(event) => setQuery(event.target.value)}
                        onFocus={() => setOpen(true)}
                        onBlur={() => setOpen(false)}
                    />
                    <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-ink-muted">
                        {state === 'loading' && <Loader2 className="size-4 animate-spin" aria-label="Buscando" />}
                    </span>
                    <ComboboxButton className="absolute inset-y-0 right-0 flex items-center px-3 text-ink-muted" aria-label="Mostrar usuarios" onClick={() => setOpen(true)}>
                        <ChevronDown className="size-4" aria-hidden="true" />
                    </ComboboxButton>

                    <ComboboxOptions
                        anchor={{ to: 'bottom start', gap: 4 }}
                        transition
                        className="z-50 w-(--input-width) [--anchor-max-height:18rem] overflow-auto rounded-lg border border-line bg-card p-1 shadow-card-hover outline-none transition duration-100 ease-out empty:invisible data-closed:scale-95 data-closed:opacity-0"
                    >
                        {results.map((option) => (
                            <ComboboxOption key={option.id} value={option} className="group flex cursor-pointer items-center justify-between gap-3 rounded-md px-3 py-2 text-sm text-ink select-none data-focus:bg-primary/15">
                                <span className="min-w-0">
                                    <span className="block truncate group-data-selected:font-semibold">{option.name}</span>
                                    <span className="block truncate text-xs text-ink-muted">{option.email}</span>
                                </span>
                                <Check className="hidden size-4 shrink-0 group-data-selected:block" aria-hidden="true" />
                            </ComboboxOption>
                        ))}

                        {state === 'loading' && results.length === 0 && <p className="px-3 py-3 text-sm text-ink-muted">Buscando...</p>}
                        {state === 'error' && <p className="px-3 py-3 text-sm text-danger" role="alert">No se pudo buscar. Inténtalo de nuevo.</p>}
                        {state === 'idle' && results.length === 0 && (
                            <p className="px-3 py-3 text-sm text-ink-muted">{query.trim() ? `Sin resultados para «${query.trim()}»` : 'No hay usuarios que se puedan añadir a este Workspace.'}</p>
                        )}
                        {hasMore && state === 'idle' && <p className="border-t border-line px-3 py-2 text-xs text-ink-muted">Hay más usuarios: escribe para acotar la búsqueda.</p>}
                    </ComboboxOptions>
                </div>
            )}
        </Combobox>
    );
}
