import { useId } from 'react';
import Tooltip from './Tooltip';

/**
 * A small set of mutually exclusive choices shown side by side (radio group). Controlled: `value` / `onChange(value)`.
 * `options`: [{ value, label, description? }]; a `description` is shown as a tooltip (hover, focus or tap) saying what
 * the choice does. Meant for 2-4 short options; longer lists belong in a Select.
 */
export default function SegmentedControl({ label, options, value, onChange, disabled = false, hint, className = '' }) {
    const name = useId();

    return (
        <fieldset disabled={disabled} className={`min-w-0 ${className}`}>
            <legend className="text-sm font-medium text-ink">{label}</legend>
            <div className="mt-2 flex flex-wrap gap-2">
                {options.map((option) => (
                    <Tooltip key={option.value} content={option.description}>
                        {(aria) => (
                            <label className="flex cursor-pointer items-center rounded-lg border border-field px-3 py-2 text-sm font-medium text-ink transition-colors duration-150 hover:border-primary/40 has-checked:border-primary has-checked:bg-primary-soft has-checked:text-primary has-focus-visible:outline-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed has-disabled:opacity-60">
                                <input {...aria} type="radio" name={name} value={option.value} checked={value === option.value} onChange={() => onChange(option.value)} className="sr-only" />
                                {option.label}
                            </label>
                        )}
                    </Tooltip>
                ))}
            </div>
            {hint && <p className="mt-1.5 text-xs text-ink-muted">{hint}</p>}
        </fieldset>
    );
}
