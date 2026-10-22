import { useMemo } from 'react';
import { byDay, collapseLanes, eventBox, HOUR_PX, hourRange, layoutDay, toneHue } from '@/lib/calendarLayout';
import { dayLabel } from '@/config/bookings';

/**
 * Day or week calendar of the Agenda. It draws what the server already filtered (the member's locations, the chosen filters): it never decides who may see
 * what. Appointments that overlap share the width of ONE column (a clinic with many professionals does not get a column each), the color says who, and the
 * lighter band under an appointment is the rest the service reserves after it. Wider than the screen scrolls INSIDE the calendar, never the page.
 */
export default function CalendarGrid({ appointments, days, today, onSelect, onSelectMany }) {
    const grouped = useMemo(() => byDay(appointments), [appointments]);
    const { startHour, endHour } = useMemo(() => hourRange(appointments), [appointments]);
    const hours = Array.from({ length: endHour - startHour }, (_, index) => startHour + index);
    const height = hours.length * HOUR_PX;
    const columns = useMemo(() => Object.fromEntries(days.map((day) => [day, collapseLanes(layoutDay(grouped[day] ?? []), days.length > 1 ? 2 : 4)])), [grouped, days]);
    const week = days.length > 1;

    return (
        <div className="overflow-auto rounded-xl border border-line bg-card" style={{ maxHeight: '70vh' }} role="group" aria-label={week ? 'Calendario de la semana' : 'Calendario del día'}>
            <div className={week ? 'min-w-[44rem]' : 'min-w-[18rem]'}>
                <div className="sticky top-0 z-20 grid border-b border-line bg-canvas" style={{ gridTemplateColumns: `3.5rem repeat(${days.length}, minmax(0, 1fr))` }}>
                    <div />
                    {days.map((day) => (
                        <div key={day} className={`border-l border-line px-2 py-2 text-center text-xs font-bold capitalize ${day === today ? 'text-accent-blue' : 'text-ink'}`}>
                            {dayLabel(day)}
                            {day === today && <span className="ml-1 rounded-full bg-primary-soft px-1.5 py-0.5 text-[10px] font-semibold">Hoy</span>}
                        </div>
                    ))}
                </div>

                <div className="grid" style={{ gridTemplateColumns: `3.5rem repeat(${days.length}, minmax(0, 1fr))` }}>
                    <div className="relative" style={{ height }} aria-hidden="true">
                        {hours.map((hour, index) => (
                            <span key={hour} className="absolute right-2 -translate-y-2 text-[11px] text-ink-muted tabular-nums" style={{ top: index * HOUR_PX }}>
                                {index === 0 ? '' : `${String(hour).padStart(2, '0')}:00`}
                            </span>
                        ))}
                    </div>

                    {days.map((day) => (
                        <div key={day} className={`relative border-l border-line ${day === today ? 'bg-primary-soft/20' : ''}`} style={{ height }}>
                            {hours.map((hour, index) => (
                                <div key={hour} className="absolute inset-x-0 border-t border-line/70" style={{ top: index * HOUR_PX }} aria-hidden="true" />
                            ))}

                            {columns[day].visible.map((item) => {
                                const box = eventBox(item, startHour);
                                const hue = toneHue(item.professionalId ?? item.id);
                                const rest = (item.restMinutes / 60) * HOUR_PX;
                                const cancelled = !item.active;

                                return (
                                    <div key={item.id} className="absolute px-0.5" style={{ top: box.top, left: box.left, width: box.width, height: box.height + rest }}>
                                        <button
                                            type="button"
                                            onClick={() => onSelect(item)}
                                            className={`block w-full overflow-hidden rounded-md border-l-4 px-1.5 py-1 text-left text-[11px] leading-tight text-ink shadow-sm outline-none hover:brightness-95 focus-visible:ring-2 focus-visible:ring-primary ${cancelled ? 'opacity-50 line-through' : ''}`}
                                            style={{ height: box.height, borderLeftColor: `hsl(${hue} 65% 45%)`, background: `color-mix(in srgb, hsl(${hue} 65% 50%) 20%, var(--color-card))` }}
                                            aria-label={`${item.timeLabel}, ${item.service}${item.professional ? `, ${item.professional}` : ''}, ${item.customer?.name ?? ''}, ${item.statusLabel}`}
                                        >
                                            <span className="block truncate font-semibold">{item.timeLabel}</span>
                                            {box.height >= 40 && <span className="block truncate">{item.service}</span>}
                                            {box.height >= 56 && <span className="block truncate text-ink-muted">{[item.professional, item.customer?.name].filter(Boolean).join(' · ')}</span>}
                                            {box.height >= 80 && item.location && <span className="block truncate text-ink-muted">{item.location}</span>}
                                        </button>
                                        {rest > 0 && <div className="mt-px rounded-b-md opacity-60" style={{ height: rest - 1, backgroundImage: `repeating-linear-gradient(135deg, hsl(${hue} 65% 50% / 0.25) 0 4px, transparent 4px 8px)` }} title="Tiempo de descanso después de la cita" aria-hidden="true" />}
                                    </div>
                                );
                            })}

                            {columns[day].overflow.map((group) => {
                                const box = eventBox({ ...group, durationMinutes: Math.max(group.durationMinutes, 30) }, startHour);

                                return (
                                    <div key={`more-${group.id}`} className="absolute px-0.5" style={{ top: box.top, left: box.left, width: box.width, height: box.height }}>
                                        <button type="button" onClick={() => onSelectMany(group.events)} className="flex size-full flex-col items-center justify-center rounded-md border border-dashed border-field bg-canvas text-[11px] font-bold text-ink-muted outline-none hover:bg-primary-soft focus-visible:ring-2 focus-visible:ring-primary" aria-label={`${group.events.length} citas más en este horario`}>
                                            +{group.events.length}
                                            <span className="font-medium">más</span>
                                        </button>
                                    </div>
                                );
                            })}

                            {columns[day].visible.length === 0 && columns[day].overflow.length === 0 && (
                                <p className="pointer-events-none absolute inset-x-0 top-3 text-center text-xs text-ink-muted">Sin citas</p>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
