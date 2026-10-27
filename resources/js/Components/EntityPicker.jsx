import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Check, ChevronDown, X } from 'lucide-react';
import { useState } from 'react';
import { normalize } from '@/lib/permissions';
import { toggleInScope, toggleValue } from '@/lib/pickers';

/**
 * The one picker of entities (locations, services, resources, professionals, products, people) chosen from a list: a dropdown that is typed into to search by name
 * (ignoring case and accents), with the chosen ones shown below as compact chips that can be removed one by one. `options` are `{ value, label, description?, disabled? }`
 * and must be the ones the server already offered for the active Workspace (and, for locations, the ones the user may use): this is only the control, the server validates
 * every id again. `value` is an array of option values (`multiple`, the default) or one value (`multiple={false}`); `onChange` receives the same shape. A chosen value that
 * is no longer offered is still shown (so editing never drops it silently) and can be removed. Disabled options cannot be added; loading, empty and error states are explicit.
 * With `allLabel` (a list whose EMPTY value means every one, future ones included, as for the locations of a service) the first option is that «all» and, while it is on,
 * a single chip says so instead of one chip per option; clicking an option while «all» is on excludes it (an explicit list), and choosing every option collapses back to «all».
 * Nothing invented is ever sent: «all» is the empty list.
 */
export default function EntityPicker({ id, value, onChange, options, multiple = true, placeholder = 'Buscar por nombre', emptyLabel = 'Ninguna opción coincide', allLabel = null, noneLabel = 'Ninguna seleccionada', loading = false, error, disabled = false, invalid = false, className = '', ...props }) {
    const [query, setQuery] = useState('');
    const chosen = multiple ? [...new Set(value ?? [])] : value === '' || value === null || value === undefined ? [] : [value];
    const known = new Map(options.map((option) => [option.value, option]));
    const needle = normalize(query).trim();
    const visible = needle ? options.filter((option) => normalize(`${option.label} ${option.description ?? ''}`).includes(needle)) : options;
    const ALL = '__all__';
    const scoped = Boolean(allLabel) && multiple;
    const allOn = scoped && chosen.length === 0;
    const offered = options.filter((option) => !option.disabled).map((option) => option.value);
    const change = (next) => onChange(multiple ? next : (next[0] ?? ''));
    const choose = (next) => {
        if (!scoped) return change(next);
        const shown = allOn ? [ALL, ...offered] : chosen;
        const toggled = [...next.filter((item) => !shown.includes(item)), ...shown.filter((item) => !next.includes(item))][0];
        if (toggled === undefined) return undefined;
        return change(toggled === ALL ? [] : toggleInScope(chosen, toggled, offered));
    };
    const remove = (item) => change(chosen.filter((current) => current !== item));
    const pick = (item) => {
        if (item === null || item === undefined) return;
        change(multiple ? toggleValue(chosen, item) : [item]);
        setQuery('');
    };
    const hasError = invalid || Boolean(error);

    return (
        <div className={className}>
            <Combobox immediate value={multiple ? (allOn ? [ALL, ...offered] : chosen) : (chosen[0] ?? null)} onChange={multiple ? choose : (item) => pick(item)} multiple={multiple} disabled={disabled || loading} onClose={() => setQuery('')}>
                <div className="relative">
                    <ComboboxInput
                        id={id}
                        aria-invalid={hasError || undefined}
                        autoComplete="off"
                        spellCheck={false}
                        className="field pr-9"
                        placeholder={loading ? 'Cargando…' : placeholder}
                        displayValue={() => query}
                        onChange={(event) => setQuery(event.target.value)}
                        {...props}
                    />
                    <ComboboxButton className="absolute inset-y-0 right-0 flex items-center px-3 text-ink-muted focus-visible:outline-2 focus-visible:outline-primary" aria-label="Mostrar opciones">
                        <ChevronDown className="size-4" aria-hidden="true" />
                    </ComboboxButton>

                    <ComboboxOptions anchor={{ to: 'bottom start', gap: 4 }} transition className="z-50 w-(--input-width) [--anchor-max-height:16rem] overflow-auto rounded-lg border border-line bg-card p-1 shadow-card-hover outline-none transition duration-100 ease-out data-closed:scale-95 data-closed:opacity-0">
                        {loading && <p className="px-3 py-2 text-sm text-ink-muted">Cargando…</p>}
                        {!loading && visible.length === 0 && <p className="px-3 py-2 text-sm text-ink-muted">{options.length === 0 ? 'No hay opciones disponibles' : emptyLabel}</p>}
                        {scoped && !loading && (!needle || normalize(allLabel).includes(needle)) && (
                            <ComboboxOption key={ALL} value={ALL} className="group flex cursor-pointer items-center justify-between gap-2 rounded-md px-3 py-2 text-sm font-semibold text-ink select-none data-focus:bg-primary-soft data-selected:text-primary">
                                <span className="block truncate">{allLabel}</span>
                                <Check className="hidden size-4 shrink-0 group-data-selected:block" aria-hidden="true" />
                            </ComboboxOption>
                        )}
                        {visible.map((option) => (
                            <ComboboxOption key={option.value} value={option.value} disabled={option.disabled} className="group flex cursor-pointer items-center justify-between gap-2 rounded-md px-3 py-2 text-sm text-ink select-none data-disabled:cursor-not-allowed data-disabled:opacity-50 data-focus:bg-primary-soft data-selected:font-semibold data-selected:text-primary">
                                <span className="min-w-0">
                                    <span className="block truncate">{option.label}</span>
                                    {option.description && <span className="block truncate text-xs font-normal text-ink-muted">{option.description}</span>}
                                </span>
                                <Check className="hidden size-4 shrink-0 group-data-selected:block" aria-hidden="true" />
                            </ComboboxOption>
                        ))}
                    </ComboboxOptions>
                </div>
            </Combobox>

            <ul className="mt-2 flex flex-wrap gap-1.5" aria-label="Seleccionadas" aria-live="polite">
                {allOn && <li className="inline-flex items-center rounded-full bg-primary-soft py-0.5 px-2.5 text-xs font-medium text-primary">{allLabel}</li>}
                {chosen.length === 0 && !scoped && <li className="text-xs text-ink-muted">{noneLabel}</li>}
                {chosen.map((item) => {
                    const option = known.get(item);
                    const label = option?.label ?? `Opción ${item}`;

                    return (
                        <li key={item} className="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-soft py-0.5 pr-1 pl-2.5 text-xs font-medium text-primary">
                            <span className="truncate">{label}</span>
                            {!disabled && (
                                <button type="button" onClick={() => remove(item)} aria-label={`Quitar ${label}`} className="inline-flex size-5 shrink-0 items-center justify-center rounded-full hover:bg-primary/15 focus-visible:outline-2 focus-visible:outline-primary">
                                    <X className="size-3" aria-hidden="true" />
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {error && <p role="alert" className="mt-1 text-sm text-danger">{error}</p>}
        </div>
    );
}
