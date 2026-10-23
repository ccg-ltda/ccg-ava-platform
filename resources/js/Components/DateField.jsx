import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { useId, useState } from 'react';
import { dateProblem, formatDate, isRealDate, monthGrid, monthOf, monthTitle, outsideBounds, shiftMonth } from '@/lib/dates';
import InputError from './InputError';
import InputLabel from './InputLabel';

const WEEKDAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
const navigation = 'inline-flex size-8 items-center justify-center rounded-lg text-ink-muted transition-colors hover:bg-primary-soft hover:text-primary focus-visible:outline-2 focus-visible:outline-primary';

function Calendar({ value, today, min, max, clearable, onPick, close }) {
    const todayDate = isRealDate(today) ? today : new Date().toISOString().slice(0, 10);
    const [month, setMonth] = useState(monthOf(value) ?? monthOf(todayDate));
    const pick = (date) => {
        onPick(date);
        close();
    };
    const step = (delta) => setMonth((current) => shiftMonth(current, delta));

    return (
        <div className="w-72 max-w-full">
            <div className="flex items-center justify-between gap-2 pb-2">
                <button type="button" className={navigation} onClick={() => step(-1)} aria-label="Mes anterior">
                    <ChevronLeft className="size-4" aria-hidden="true" />
                </button>
                <p className="text-sm font-semibold text-ink first-letter:uppercase" aria-live="polite">{monthTitle(month)}</p>
                <button type="button" className={navigation} onClick={() => step(1)} aria-label="Mes siguiente">
                    <ChevronRight className="size-4" aria-hidden="true" />
                </button>
            </div>
            <p className="pb-2 text-xs text-ink-muted">{value ? `Seleccionada: ${formatDate(value)}` : 'Ninguna fecha seleccionada'}</p>
            <div className="grid grid-cols-7 gap-1 text-center" role="grid" aria-label={monthTitle(month)}>
                {WEEKDAYS.map((day) => (
                    <span key={day} className="py-1 text-[11px] font-semibold text-ink-muted" role="columnheader">{day}</span>
                ))}
                {monthGrid(month).map(({ date, inMonth }) => {
                    const disabled = outsideBounds(date, { min, max });
                    const selected = date === value;
                    const isToday = date === todayDate;
                    const tone = selected
                        ? 'bg-primary font-semibold text-white'
                        : isToday
                          ? 'font-semibold text-primary ring-1 ring-primary/40 hover:bg-primary-soft'
                          : inMonth
                            ? 'text-ink hover:bg-primary-soft'
                            : 'text-ink-muted/50 hover:bg-primary-soft';

                    return (
                        <button
                            key={date}
                            type="button"
                            role="gridcell"
                            disabled={disabled}
                            aria-selected={selected}
                            aria-current={isToday ? 'date' : undefined}
                            aria-label={formatDate(date)}
                            onClick={() => pick(date)}
                            className={`inline-flex size-9 items-center justify-center rounded-lg text-sm tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-30 ${tone}`}
                        >
                            {Number(date.slice(8, 10))}
                        </button>
                    );
                })}
            </div>
            <div className="mt-3 flex items-center justify-between border-t border-line pt-3">
                <button
                    type="button"
                    disabled={outsideBounds(todayDate, { min, max })}
                    onClick={() => pick(todayDate)}
                    className="rounded-lg px-3 py-1.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40"
                >
                    Hoy
                </button>
                {clearable && (
                    <button type="button" onClick={() => pick('')} className="rounded-lg px-3 py-1.5 text-sm font-semibold text-ink-muted transition-colors hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        Limpiar
                    </button>
                )}
            </div>
        </div>
    );
}

/**
 * The one date field of the application's filters and forms: label, a button that opens the shared calendar (picking a day writes the value and closes it; «Hoy»
 * and «Limpiar» act directly, there is no «Guardar»), min / max and the message under it. The value is a `YYYY-MM-DD` string (empty = no date) and
 * `onChange(value)` receives it. Without a value the calendar opens on the current month (`today` is the server's local day when the page knows it, else the
 * browser's); the value itself is never filled in for the user. `clearable` (default true) offers «Limpiar»; pass false for a required date. States are the
 * shared `.field` ones in light and dark. The bounds are explained here but the server decides what is valid: nothing is silently corrected.
 */
export default function DateField({ id, label, value, onChange, min, max, today, error, disabled = false, readOnly = false, clearable = true, help, placeholder = 'Elegir fecha', className = '' }) {
    const generated = useId();
    const fieldId = id ?? generated;
    const message = error ?? dateProblem(value, { min, max });

    return (
        <div className={`min-w-0 ${className}`}>
            <InputLabel htmlFor={fieldId} value={label} />
            <Popover className="relative mt-1">
                {({ close }) => (
                    <>
                        <PopoverButton
                            id={fieldId}
                            disabled={disabled || readOnly}
                            aria-invalid={Boolean(message) || undefined}
                            aria-describedby={message ? `${fieldId}-message` : help ? `${fieldId}-help` : undefined}
                            className="field flex items-center justify-between gap-2 text-left"
                        >
                            <span className={`truncate ${value ? '' : 'text-ink-muted/70'}`}>{value ? formatDate(value) || value : placeholder}</span>
                            <CalendarDays className="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                        </PopoverButton>
                        <PopoverPanel anchor={{ to: 'bottom start', gap: 4, padding: 8 }} className="z-50 rounded-xl border border-line bg-card p-3 shadow-card-hover [--anchor-max-height:26rem]">
                            <Calendar value={value} today={today} min={min} max={max} clearable={clearable} onPick={onChange} close={close} />
                        </PopoverPanel>
                    </>
                )}
            </Popover>
            {message ? <InputError id={`${fieldId}-message`} message={message} className="mt-1" /> : help ? <p id={`${fieldId}-help`} className="mt-1 text-xs text-ink-muted">{help}</p> : null}
        </div>
    );
}
