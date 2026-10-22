import { useId } from 'react';
import { dateProblem, todayAllowed } from '@/lib/dates';
import InputError from './InputError';
import InputLabel from './InputLabel';
import TextInput from './TextInput';

/**
 * The one date field of the application's filters and forms: label, native date input (keyboard, screen readers and the phone's own picker keep working),
 * min / max, optional «Hoy» quick action and the message under it. States are the shared `.field` ones (focus, invalid, disabled, read only) in light and dark.
 * `today` is the server's local day (never the browser's) and only needed for the quick action; `error` is the server's message for this field. The bounds are
 * explained here but the server decides what is valid: nothing is silently corrected.
 */
export default function DateField({ id, label, value, onChange, min, max, type = 'date', today, error, disabled = false, readOnly = false, help, className = '', ...props }) {
    const generated = useId();
    const fieldId = id ?? generated;
    const problem = type === 'date' ? dateProblem(value, { min, max }) : null;
    const message = error ?? problem;
    const showToday = type === 'date' && today && !disabled && !readOnly && todayAllowed(today, { min, max }) && value !== today;

    return (
        <div className={`min-w-0 ${className}`}>
            <div className="flex items-end justify-between gap-2">
                <InputLabel htmlFor={fieldId} value={label} />
                {showToday && (
                    <button type="button" onClick={() => onChange(today)} aria-label={`Elegir hoy en ${label}`} className="rounded text-xs font-semibold text-accent-blue hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                        Hoy
                    </button>
                )}
            </div>
            <TextInput
                id={fieldId}
                type={type}
                className="mt-1 w-full min-w-0"
                value={value ?? ''}
                min={min}
                max={max}
                disabled={disabled}
                readOnly={readOnly}
                invalid={Boolean(message)}
                aria-describedby={message ? `${fieldId}-message` : help ? `${fieldId}-help` : undefined}
                onChange={(event) => onChange(event.target.value)}
                {...props}
            />
            {message ? <InputError id={`${fieldId}-message`} message={message} className="mt-1" /> : help ? <p id={`${fieldId}-help`} className="mt-1 text-xs text-ink-muted">{help}</p> : null}
        </div>
    );
}
