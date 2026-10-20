import { Field, Label, Switch } from '@headlessui/react';

/**
 * Active / inactive switch: green with «Activo» when on, red with «Inactivo» when off (the colour never carries the meaning alone: the word is always shown).
 * Controlled: `checked` / `onChange(boolean)`. `description` is the help line under it. The server validates the state again; this is only the control.
 */
export default function StatusSwitch({ checked, onChange, disabled = false, description, activeLabel = 'Activo', inactiveLabel = 'Inactivo', className = '', ...props }) {
    return (
        <Field disabled={disabled} className={`flex items-start gap-3 data-disabled:opacity-60 ${className}`}>
            <Switch
                checked={checked}
                onChange={onChange}
                {...props}
                className="group relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full bg-danger transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary data-checked:bg-accent-green data-disabled:cursor-not-allowed"
            >
                <span aria-hidden="true" className="pointer-events-none mt-0.5 ml-0.5 inline-block size-5 rounded-full bg-white shadow transition-transform duration-150 group-data-checked:translate-x-5" />
            </Switch>
            <span className="min-w-0">
                <Label className={`text-sm font-semibold ${checked ? 'text-accent-green' : 'text-danger'}`}>{checked ? activeLabel : inactiveLabel}</Label>
                {description && <span className="mt-0.5 block text-xs text-ink-muted">{description}</span>}
            </span>
        </Field>
    );
}
