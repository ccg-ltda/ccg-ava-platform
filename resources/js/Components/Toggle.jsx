import { Field, Label, Switch } from '@headlessui/react';

/** On/off switch with a visible label. Controlled: `checked` / `onChange(boolean)`. */
export default function Toggle({ checked, onChange, label, description, className = '' }) {
    return (
        <Field className={`flex items-start gap-3 ${className}`}>
            <Switch
                checked={checked}
                onChange={onChange}
                className="group relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full bg-field transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary data-checked:bg-primary"
            >
                <span
                    aria-hidden="true"
                    className="pointer-events-none mt-0.5 ml-0.5 inline-block size-5 rounded-full bg-white shadow transition-transform duration-150 group-data-checked:translate-x-5"
                />
            </Switch>
            <span className="min-w-0">
                <Label className="cursor-pointer text-sm font-medium text-ink">{label}</Label>
                {description && <span className="mt-0.5 block text-sm text-ink-muted">{description}</span>}
            </span>
        </Field>
    );
}
