import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';
import { Check, ChevronDown } from 'lucide-react';

/**
 * Custom dropdown (replaces the native <select>) with the same look as the other fields.
 * Controlled: `value` / `onChange(value)`. `options`: [{ value, label }]; keep value types consistent.
 * `size="sm"` is the compact variant used in list footers. Give it an `id` so an <InputLabel htmlFor> targets it.
 */
export default function Select({
    id,
    value,
    onChange,
    options,
    placeholder = 'Seleccionar',
    disabled = false,
    invalid = false,
    size = 'md',
    anchor = 'bottom start',
    className = '',
    ...props
}) {
    const selected = options.find((option) => option.value === value);

    return (
        <Listbox value={value ?? ''} onChange={onChange} disabled={disabled}>
            <ListboxButton
                id={id}
                aria-invalid={invalid || undefined}
                className={`field group flex items-center justify-between gap-2 text-left data-open:border-primary data-open:ring-2 data-open:ring-primary/20 ${size === 'sm' ? 'py-1.5' : ''} ${className}`}
                {...props}
            >
                <span className={`truncate ${selected ? '' : 'text-ink-muted/70'}`}>{selected ? selected.label : placeholder}</span>
                <ChevronDown className="size-4 shrink-0 text-ink-muted transition-transform duration-150 group-data-open:rotate-180" aria-hidden="true" />
            </ListboxButton>

            <ListboxOptions
                anchor={{ to: anchor, gap: 4 }}
                transition
                className="z-50 w-(--button-width) min-w-24 [--anchor-max-height:16rem] overflow-auto rounded-lg border border-line bg-card p-1 shadow-card-hover outline-none transition duration-100 ease-out data-closed:scale-95 data-closed:opacity-0"
            >
                {options.map((option) => (
                    <ListboxOption
                        key={option.value}
                        value={option.value}
                        className="group flex cursor-pointer items-center justify-between gap-2 rounded-md px-3 py-2 text-sm text-ink select-none data-focus:bg-primary-soft data-selected:font-semibold data-selected:text-primary"
                    >
                        <span className="truncate">{option.label}</span>
                        <Check className="hidden size-4 shrink-0 group-data-selected:block" aria-hidden="true" />
                    </ListboxOption>
                ))}
            </ListboxOptions>
        </Listbox>
    );
}
