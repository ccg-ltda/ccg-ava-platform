import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Clock } from 'lucide-react';
import { useId, useState } from 'react';
import { isTime, joinTime, minutesFor, splitTime, timeOutside } from '@/lib/times';
import InputError from './InputError';
import InputLabel from './InputLabel';

const HOURS = Array.from({ length: 24 }, (_, hour) => hour);
const column = 'relative max-h-56 space-y-0.5 overflow-y-auto pr-1';
const cell = (selected, blocked) =>
    `block w-full rounded-lg px-3 py-1.5 text-center text-sm tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-primary ${
        selected ? 'bg-primary font-semibold text-white' : 'text-ink hover:bg-primary-soft'
    } ${blocked ? 'cursor-not-allowed opacity-30 hover:bg-transparent' : ''}`;
/** Brings the chosen option into view inside its own list (never scrolls the page). */
const reveal = (selected) => (element) => {
    if (selected && element) element.parentElement.scrollTop = element.offsetTop - element.parentElement.clientHeight / 2 + element.offsetHeight / 2;
};
const two = (number) => String(number).padStart(2, '0');

function TimePicker({ value, step, min, max, clearable, onPick, close }) {
    const current = splitTime(value);
    const [hour, setHour] = useState(current?.hour ?? null);
    const confirm = (nextHour, nextMinute) => {
        onPick(joinTime(nextHour, nextMinute));
        close();
    };

    return (
        <div className="w-56 max-w-full">
            <p className="pb-2 text-xs text-ink-muted">{isTime(value) ? `Seleccionada: ${value}` : 'Elige la hora y luego los minutos'}</p>
            <div className="grid grid-cols-2 gap-3">
                <div role="listbox" aria-label="Hora" className={column}>
                    {HOURS.map((option) => (
                        <button key={option} ref={reveal(option === hour)} type="button" role="option" aria-selected={option === hour} onClick={() => setHour(option)} className={cell(option === hour, false)}>
                            {two(option)}
                        </button>
                    ))}
                </div>
                <div role="listbox" aria-label="Minutos" className={column}>
                    {minutesFor(current?.minute, step).map((option) => {
                        const blocked = hour === null || timeOutside(joinTime(hour, option), { min, max });
                        const selected = hour === current?.hour && option === current?.minute;

                        return (
                            <button key={option} ref={reveal(selected)} type="button" role="option" aria-selected={selected} disabled={blocked} onClick={() => confirm(hour, option)} className={cell(selected, blocked)}>
                                {two(option)}
                            </button>
                        );
                    })}
                </div>
            </div>
            {clearable && (
                <div className="mt-3 flex justify-end border-t border-line pt-3">
                    <button type="button" onClick={() => { onPick(''); close(); }} className="rounded-lg px-3 py-1.5 text-sm font-semibold text-ink-muted transition-colors hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        Limpiar
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * The one time field of the application (hours and minutes only, never a date): a button that opens the shared picker. Choose an hour, then a minute: the minute
 * confirms the time, writes the value and closes it. The value is an `HH:MM` string (empty = none) and `onChange(value)` receives it. `step` is the minute interval
 * offered (default 5; a stored minute outside it stays selectable so editing never loses data), `min` / `max` are optional `HH:MM` bounds. `clearable` (default
 * false: schedules need both ends) adds «Limpiar». `compact` is the dense variant for schedule rows. The server decides what is valid; nothing is silently corrected.
 */
export default function TimeField({ id, label, value, onChange, step = 5, min, max, clearable = false, error, disabled = false, help, placeholder = '--:--', className = '', compact = false, 'aria-label': ariaLabel }) {
    const generated = useId();
    const fieldId = id ?? generated;
    const problem = value && !isTime(value) ? 'Escribe una hora válida.' : timeOutside(value, { min, max }) ? 'La hora está fuera del rango permitido.' : null;
    const message = error ?? problem;

    return (
        <div className={`min-w-0 ${className}`}>
            {label && <InputLabel htmlFor={fieldId} value={label} />}
            <Popover className={`relative ${label ? 'mt-1' : ''}`}>
                {({ close }) => (
                    <>
                        <PopoverButton
                            id={fieldId}
                            disabled={disabled}
                            aria-label={ariaLabel}
                            aria-invalid={Boolean(message) || undefined}
                            aria-describedby={message ? `${fieldId}-message` : help ? `${fieldId}-help` : undefined}
                            className={`field flex items-center justify-between gap-2 text-left ${compact ? '!px-2 !py-1 !text-[13px] sm:!px-3 sm:!text-sm' : ''}`}
                        >
                            <span className={`tabular-nums ${value ? '' : 'text-ink-muted/70'}`}>{value || placeholder}</span>
                            <Clock className="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                        </PopoverButton>
                        <PopoverPanel anchor={{ to: 'bottom start', gap: 4, padding: 8 }} className="z-50 rounded-xl border border-line bg-card p-3 shadow-card-hover [--anchor-max-height:22rem]">
                            <TimePicker value={value} step={step} min={min} max={max} clearable={clearable} onPick={onChange} close={close} />
                        </PopoverPanel>
                    </>
                )}
            </Popover>
            {message ? <InputError id={`${fieldId}-message`} message={message} className="mt-1" /> : help ? <p id={`${fieldId}-help`} className="mt-1 text-xs text-ink-muted">{help}</p> : null}
        </div>
    );
}
