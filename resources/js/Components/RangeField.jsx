import { useId } from 'react';
import InputError from './InputError';
import Tooltip from './Tooltip';

/**
 * A setting that moves along a scale, drawn as a slider (same look as the corner radius one). Controlled: `value` /
 * `onChange(number)`. The current value is always visible in the label (`format(value)`, "60 px" by default with
 * `unit`), the ends of the scale are written under the bar, and `describe(value)` is the tooltip: it follows the
 * value, so it also works for a scale of named steps (give `format` the names).
 */
export default function RangeField({ label, value, onChange, min, max, step = 1, unit = '', format, describe, error, disabled = false, className = '' }) {
    const id = useId();
    const show = format ?? ((current) => `${current}${unit ? ` ${unit}` : ''}`);
    const description = describe?.(value);

    return (
        <div className={`min-w-0 ${className}`}>
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}: <span className="font-semibold text-primary">{show(value)}</span>
            </label>
            <Tooltip content={description} className="mt-2 w-full">
                {(aria) => (
                    <input
                        {...aria}
                        id={id}
                        type="range"
                        min={min}
                        max={max}
                        step={step}
                        value={value}
                        onChange={(event) => onChange(Number(event.target.value))}
                        disabled={disabled}
                        aria-valuetext={show(value)}
                        aria-invalid={error ? true : undefined}
                        className="w-full accent-primary"
                    />
                )}
            </Tooltip>
            <div className="mt-0.5 flex justify-between text-xs text-ink-muted" aria-hidden="true">
                <span>{show(min)}</span>
                <span>{show(max)}</span>
            </div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}
