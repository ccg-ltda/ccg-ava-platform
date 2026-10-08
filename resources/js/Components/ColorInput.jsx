import InputError from './InputError';
import InputLabel from './InputLabel';
import TextInput from './TextInput';
import { isHexColor } from '@/lib/theme';

/** A color as a picker plus its #RRGGBB value, so it can be chosen or typed. Controlled: `value` / `onChange(hex)`. */
export default function ColorInput({ id, label, value, onChange, error, disabled = false, className = '' }) {
    const valid = isHexColor(value);

    return (
        <div className={className}>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-2 flex items-center gap-3">
                <input
                    type="color"
                    aria-label={`Elegir ${label.toLowerCase()}`}
                    value={valid ? value : '#000000'}
                    onChange={(event) => onChange(event.target.value)}
                    disabled={disabled}
                    className="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-field bg-card p-1 disabled:cursor-not-allowed disabled:opacity-60"
                />
                <TextInput
                    id={id}
                    className="min-w-0 max-w-36 font-mono uppercase"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    invalid={Boolean(error) || !valid}
                    maxLength={7}
                    disabled={disabled}
                />
            </div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}
