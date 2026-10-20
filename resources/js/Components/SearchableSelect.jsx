import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { Check, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { normalize } from '@/lib/permissions';

/**
 * Dropdown that can also be typed into: it opens with every option and filters them, ignoring case and accents, as the user types («ana» finds
 * «Ana Ríos» and «Ana Roys»). Same look and contract as `Select` (`value`, `onChange(value)`, `options: [{ value, label }]`, `id` for an
 * <InputLabel htmlFor>), so it can replace it where the list is long; only an option of the list can be chosen, never free text. The options are the
 * ones the server already offered: this is only the control, the server validates the chosen id as before.
 */
export default function SearchableSelect({ id, value, onChange, options, placeholder = 'Seleccionar', emptyLabel = 'Ninguna opción coincide', disabled = false, invalid = false, className = '', ...props }) {
    const [query, setQuery] = useState('');
    const selected = options.find((option) => option.value === value) ?? null;
    const needle = normalize(query).trim();
    const visible = needle ? options.filter((option) => normalize(option.label).includes(needle)) : options;

    return (
        <Combobox immediate value={selected} onChange={(option) => onChange(option ? option.value : '')} disabled={disabled} onClose={() => setQuery('')} by="value">
            <div className={`relative ${className}`}>
                <ComboboxInput
                    id={id}
                    aria-invalid={invalid || undefined}
                    autoComplete="off"
                    spellCheck={false}
                    className="field pr-9"
                    placeholder={placeholder}
                    displayValue={(option) => option?.label ?? ''}
                    onChange={(event) => setQuery(event.target.value)}
                    {...props}
                />
                <ComboboxButton className="absolute inset-y-0 right-0 flex items-center px-3 text-ink-muted focus-visible:outline-2 focus-visible:outline-primary" aria-label="Mostrar opciones">
                    <ChevronDown className="size-4" aria-hidden="true" />
                </ComboboxButton>

                <ComboboxOptions
                    anchor={{ to: 'bottom start', gap: 4 }}
                    transition
                    className="z-50 w-(--input-width) [--anchor-max-height:16rem] overflow-auto rounded-lg border border-line bg-card p-1 shadow-card-hover outline-none transition duration-100 ease-out data-closed:scale-95 data-closed:opacity-0"
                >
                    {visible.length === 0 && <p className="px-3 py-2 text-sm text-ink-muted">{emptyLabel}</p>}
                    {visible.map((option) => (
                        <ComboboxOption
                            key={option.value}
                            value={option}
                            className="group flex cursor-pointer items-center justify-between gap-2 rounded-md px-3 py-2 text-sm text-ink select-none data-focus:bg-primary-soft data-selected:font-semibold data-selected:text-primary"
                        >
                            <span className="truncate">{option.label}</span>
                            <Check className="hidden size-4 shrink-0 group-data-selected:block" aria-hidden="true" />
                        </ComboboxOption>
                    ))}
                </ComboboxOptions>
            </div>
        </Combobox>
    );
}
