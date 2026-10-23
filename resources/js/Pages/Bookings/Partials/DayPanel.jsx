import { X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import IconButton from '@/Components/IconButton';
import { getJson, longDayLabel, money, statusTones } from '@/config/bookings';

/**
 * Detail of one day of the month calendar, read on demand when its counter is pressed (the month itself only carries counts). It lists every appointment of that
 * day that the filters and the person's locations allow, by start time, with its own scroll; the month stays visible beside it (a sheet over the page on a phone).
 * The amount is the price recorded when the appointment was booked: Ava does not record payments, so it is never shown as «cobrado».
 */
export default function DayPanel({ date, filters, onClose, onSelect }) {
    const [state, setState] = useState({ loading: true, error: null, data: null });
    const heading = useRef(null);

    useEffect(() => {
        let live = true;
        setState({ loading: true, error: null, data: null });
        getJson(route('bookings.day'), { date, professional: filters.professional, location: filters.location, service: filters.service, status: filters.status, origin: filters.origin })
            .then((data) => live && setState({ loading: false, error: null, data }))
            .catch((error) => live && setState({ loading: false, error: error.message, data: null }));

        return () => {
            live = false;
        };
    }, [date, filters.professional, filters.location, filters.service, filters.status, filters.origin]);

    useEffect(() => {
        heading.current?.focus();

        const onKey = (event) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [date, onClose]);

    const { data } = state;

    return (
        <aside
            aria-label={`Citas del ${longDayLabel(date)}`}
            className="fixed inset-x-0 bottom-0 z-40 flex max-h-[85dvh] flex-col rounded-t-2xl border border-line bg-card shadow-xl lg:sticky lg:top-4 lg:z-auto lg:max-h-[calc(100dvh-8rem)] lg:rounded-xl lg:shadow-none"
        >
            <header className="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div className="min-w-0">
                    <h3 ref={heading} tabIndex={-1} className="truncate text-base font-bold text-ink outline-none">{longDayLabel(date)}</h3>
                    <p className="text-sm text-ink-muted" role="status" aria-live="polite">
                        {state.loading ? 'Cargando citas…' : data ? `${data.total} ${data.total === 1 ? 'cita' : 'citas'}` : ''}
                    </p>
                </div>
                <IconButton icon={X} label="Cerrar el detalle del día" onClick={onClose} />
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {state.error && <div className="p-4"><Alert tone="warning">{state.error}</Alert></div>}
                {data && data.appointments.length === 0 && <p className="p-4 text-sm text-ink-muted">No hay citas este día con los filtros actuales.</p>}
                {data && data.truncated && <p className="border-b border-line bg-canvas/60 px-4 py-2 text-xs text-ink-muted">Se muestran las primeras {data.appointments.length} de {data.total}: usa los filtros para acotar.</p>}
                <ul className="divide-y divide-line">
                    {data?.appointments.map((item) => (
                        <li key={item.id}>
                            <button type="button" onClick={() => onSelect(item)} className={`block w-full px-4 py-3 text-left hover:bg-canvas focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset ${item.active ? '' : 'opacity-60'}`}>
                                <span className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-bold text-ink tabular-nums">{item.endLabel ? `${item.timeLabel}–${item.endLabel}` : item.timeLabel}</span>
                                    <Badge tone={statusTones[item.status]}>{item.statusLabel}</Badge>
                                </span>
                                <span className="mt-1 block truncate text-sm font-semibold text-ink">{item.service}</span>
                                <span className="block truncate text-sm text-ink-muted">{item.customer?.name ?? 'Sin cliente'}{item.professional && ` · ${item.professional}`}</span>
                                {item.price !== null && item.currency && <span className="block text-xs text-ink-muted">Precio registrado: <span className="font-semibold text-ink">{money(item.price, item.currency)}</span></span>}
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
        </aside>
    );
}
