import { monthGrid, monthOf, monthTitle } from '@/lib/dates';

const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const dayName = (date) => new Date(`${date}T12:00:00Z`).toLocaleDateString('es', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' });

/**
 * Month calendar of the Agenda: every day of the month (also the ones without appointments) as a grid cell with a compact counter. The counts come from the
 * server (one aggregate over the filters the person chose, their Workspace and their locations); the counter is also the control that opens the detail of that
 * day, so a day with many appointments never fills the grid. A day without appointments has no control: there is nothing to open.
 */
export default function MonthCalendar({ from, counts, today, selected, onOpen }) {
    const month = monthOf(from);
    const cells = monthGrid(month);

    return (
        <div className="rounded-xl border border-line bg-card p-3 sm:p-4" role="group" aria-label={`Calendario de ${monthTitle(month)}`}>
            <div className="grid grid-cols-7 gap-1.5 sm:gap-2">
                {WEEKDAYS.map((name) => (
                    <span key={name} className="pb-1 text-center text-[11px] font-semibold tracking-wide text-ink-muted uppercase sm:text-xs">{name}</span>
                ))}
                {cells.map(({ date, inMonth }) => {
                    const count = inMonth ? (counts[date] ?? 0) : 0;
                    const isToday = date === today;
                    const isSelected = date === selected;

                    return (
                        <div
                            key={date}
                            data-day={date}
                            className={`flex min-h-16 flex-col items-center justify-between rounded-xl border p-1.5 sm:min-h-20 sm:items-stretch sm:p-2 ${
                                !inMonth ? 'border-transparent opacity-30' : isSelected ? 'border-primary bg-primary-soft/40' : count > 0 ? 'border-line bg-card' : 'border-line/60 bg-canvas/40'
                            }`}
                        >
                            <span className={`inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums ${isToday ? 'bg-primary text-white' : 'text-ink'}`} aria-current={isToday ? 'date' : undefined}>
                                {Number(date.slice(8, 10))}
                            </span>
                            {count > 0 && (
                                <button
                                    type="button"
                                    onClick={() => onOpen(date)}
                                    aria-label={`${count} ${count === 1 ? 'cita' : 'citas'} el ${dayName(date)}. Ver detalle`}
                                    aria-pressed={isSelected}
                                    className="mx-auto mb-0.5 inline-flex min-w-8 items-center justify-center rounded-full bg-primary-soft px-2 py-0.5 text-sm font-bold text-accent-blue tabular-nums transition-colors hover:bg-primary hover:text-white focus-visible:outline-2 focus-visible:outline-primary sm:mx-0 sm:self-end"
                                >
                                    {count}
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
