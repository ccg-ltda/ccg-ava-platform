import { router, usePage } from '@inertiajs/react';
import DateField from '@/Components/DateField';
import { Ban, CalendarClock, CalendarDays, CalendarRange, Check, ChevronLeft, ChevronRight, LayoutList, RefreshCw, UserCheck, UserX, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import CalendarGrid from './CalendarGrid';
import CancelDialog from './CancelDialog';
import DayPanel from './DayPanel';
import MonthCalendar from './MonthCalendar';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import { addDays, dayLabel, money, statusTones, syncErrors, syncLabels } from '@/config/bookings';
import { gridDays, lastDayOf, periodStart, rangeLabel, shiftMonthStart } from '@/lib/calendarLayout';
import { monthOf, monthTitle } from '@/lib/dates';
import AppointmentDialog from './AppointmentDialog';

/** "Recordatorio (1 día antes)", "Confirmación"... for a notification of the Agenda. */
const notificationLabel = (item) => {
    const names = { received: 'Recepción', confirmed: 'Confirmación', reminder: 'Recordatorio' };
    const when = item.kind === 'reminder' && item.offset ? ` (${item.offset % 1440 === 0 ? `${item.offset / 1440} d` : item.offset % 60 === 0 ? `${item.offset / 60} h` : `${item.offset} min`} antes)` : '';

    return `${names[item.kind] ?? item.kind}${when}`;
};

const RANGES = [{ value: 1, label: 'Día' }, { value: 7, label: 'Semana' }, { value: 31, label: 'Mes' }];
const PERIOD_OF_DAYS = { 1: 'day', 7: 'week', 31: 'month' };
const ORIGINS = [{ value: '', label: 'Todos los orígenes' }, { value: 'admin', label: 'Agenda del negocio' }, { value: 'web', label: 'Página de reservas' }, { value: 'chat', label: 'Asistente' }];
const STATUSES = [{ value: '', label: 'Todos los estados' }, { value: 'requested', label: 'Por confirmar' }, { value: 'confirmed', label: 'Confirmadas' }, { value: 'completed', label: 'Realizadas' }, { value: 'no_show', label: 'No asistió' }, { value: 'cancelled', label: 'Canceladas' }, { value: 'failed', label: 'Rechazadas' }];

/** The agenda: appointments of the chosen days grouped by day, with the actions each one allows. */
const VIEW_KEY = 'ava.agenda.view';

/**
 * Calendar or list. What the address says (`view`) wins; otherwise the choice remembered in this browser (a convenience only: the page works without it), and on
 * a phone the list. A custom range (a typed «Hasta») has no calendar.
 */
function useAgendaView(period, requested) {
    const [view, setView] = useState(requested ?? 'calendar');

    useEffect(() => {
        if (requested) {
            setView(requested);

            return;
        }

        let saved = null;

        try {
            saved = window.localStorage.getItem(VIEW_KEY);
        } catch {
            saved = null;
        }

        setView(saved === 'list' || saved === 'calendar' ? saved : window.matchMedia('(max-width: 639px)').matches ? 'list' : 'calendar');
    }, [requested]);

    const choose = (next) => {
        setView(next);

        try {
            window.localStorage.setItem(VIEW_KEY, next);
        } catch {
            /* ignore: the choice just is not remembered */
        }
    };

    return [period === 'custom' ? 'list' : view, choose];
}

export default function AgendaPanel({ appointments, dayCounts, filters, options, capabilities, today, can, listLimit = 500 }) {
    const [dialog, setDialog] = useState(null); // {} = new, an appointment = reschedule
    const [cancelling, setCancelling] = useState(null);
    const [detail, setDetail] = useState(null); // an appointment opened from the calendar
    const [crowd, setCrowd] = useState(null); // the appointments hidden behind a «+N» of a crowded moment
    const [openDay, setOpenDay] = useState(null); // a day of the month calendar whose appointments are open in the side panel
    const period = filters.period ?? (filters.to ? 'custom' : 'week');
    const [view, chooseView] = useAgendaView(period, filters.view);

    const { errors = {} } = usePage().props;
    const clean = (params) => Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
    // The filters travel with every change; `period` is derived by the server and `view` is sent so the month calendar only asks for the counts it draws.
    const visit = (params) =>
        router.get(route('bookings.index'), clean({ ...filters, period: undefined, view, ...params, tab: 'agenda', search: undefined }), { only: ['appointments', 'dayCounts', 'filters', 'errors', 'listLimit'], preserveState: true, preserveScroll: true, replace: true, onSuccess: () => setOpenDay(null) });
    // A day or a week moves by its length, a month by calendar months and a custom range by its own length (its «Hasta» moves along with «Desde»).
    const step = (direction) => visit(period === 'month'
        ? { from: shiftMonthStart(filters.from, direction), days: 31 }
        : { from: addDays(filters.from, direction * filters.days), to: filters.to ? addDays(filters.to, direction * filters.days) : undefined });
    // «Hoy» keeps the kind of period and the filters: it only moves the dates (the day, its week or its month); a custom range becomes just today.
    const goToday = () => visit(period === 'custom' ? { from: today, to: today } : { from: periodStart(period, today), days: filters.days > 7 ? 31 : filters.days });
    const changePeriod = (value) => visit({ days: value, to: undefined, from: periodStart(PERIOD_OF_DAYS[value], filters.from <= today && today <= lastDayOf(filters) ? today : filters.from) });
    const showingToday = filters.from <= today && today <= lastDayOf(filters);
    const backwards = Boolean(filters.to) && filters.from > filters.to;
    const changeFrom = (value) => value && visit({ from: value });
    const changeTo = (value) => visit({ to: value || undefined });
    const act = (appointment, path, payload = {}) => router.post(route(path, appointment.id), payload, { preserveScroll: true });

    const listDays = appointments.reduce((groups, appointment) => ({ ...groups, [appointment.date]: [...(groups[appointment.date] ?? []), appointment] }), {});
    const started = (appointment) => new Date(appointment.startsAt) <= new Date();
    const canCreate = can.create && capabilities.connected && capabilities.availability && capabilities.book;

    // Where to filter: one location = nothing to choose (the member only has that one); the professionals and services listed are the ones that really go with
    // the chosen location (and each other), as the server described them.
    const locations = options.locations ?? [];
    const place = filters.location ? Number(filters.location) : null;
    const serviceFilter = filters.service ? Number(filters.service) : null;
    const professionals = useMemo(() => (options.professionals ?? []).filter((professional) => (
        (place === null || professional.everywhere !== false && (professional.everywhere || professional.locationIds?.includes(place)) || professional.everywhere === undefined)
        && (serviceFilter === null || !professional.serviceIds || professional.serviceIds.includes(serviceFilter))
    )), [options.professionals, place, serviceFilter]);
    const serviceOptions = useMemo(() => (options.services ?? []).filter((service) => place === null || service.everywhere === undefined || service.everywhere || service.locationIds?.includes(place)), [options.services, place]);
    const last = lastDayOf(filters);
    const gridCount = view === 'calendar' && (period === 'day' || period === 'week') ? filters.days : 0;
    const days = gridCount > 0 ? gridDays(filters.from, gridCount) : [];
    const monthCalendar = view === 'calendar' && period === 'month';
    const onlyToday = filters.from === today && last === today;
    const filtered = Boolean(filters.professional || filters.location || filters.service || filters.status || filters.origin);

    const actions = (appointment) => (can.edit || can.cancel) && appointment.active && (
                <div className="flex items-center gap-0.5">
                    {can.edit && !appointment.external && !appointment.customerConfirmed && <IconButton icon={UserCheck} label="Registrar que el cliente confirmó" context={appointment.service} onClick={() => act(appointment, 'bookings.customer-confirmation')} />}
                    {can.edit && appointment.status === 'requested' && !appointment.external && <IconButton icon={Check} label="Confirmar" context={appointment.service} onClick={() => act(appointment, 'bookings.status', { status: 'confirmed' })} />}
                    {can.edit && appointment.status === 'confirmed' && !appointment.external && started(appointment) && (
                        <>
                            <IconButton icon={Check} label="Marcar como realizada" context={appointment.service} onClick={() => act(appointment, 'bookings.status', { status: 'completed' })} />
                            <IconButton icon={UserX} label="Marcar como no asistió" context={appointment.service} tone="danger" onClick={() => act(appointment, 'bookings.status', { status: 'no_show' })} />
                        </>
                    )}
                    {can.edit && capabilities.reschedule && appointment.provider === 'internal' && <IconButton icon={CalendarClock} label="Reprogramar" context={appointment.service} onClick={() => setDialog(appointment)} />}
                    {can.edit && appointment.external && <IconButton icon={RefreshCw} label="Consultar estado en la agenda externa" context={appointment.service} onClick={() => act(appointment, 'bookings.refresh')} />}
                    {can.cancel && <IconButton icon={Ban} label="Cancelar" context={appointment.service} tone="danger" onClick={() => setCancelling(appointment)} />}
                </div>
    );

    return (
        <div className="space-y-4">
            {capabilities.external && !capabilities.book && (
                <p className="rounded-lg border border-line bg-canvas/60 p-3 text-sm text-ink-muted">
                    Con {capabilities.label} como agenda, Ava todavía no puede ofrecer horarios ni crear citas: esas operaciones no están verificadas con ese proveedor. Las citas que ya existan se pueden consultar y cancelar.
                </p>
            )}

            <Card className="p-4 sm:p-5">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex min-w-0 flex-wrap items-end gap-2">
                        <IconButton icon={ChevronLeft} label="Anterior" onClick={() => step(-1)} />
                        <SecondaryButton type="button" aria-pressed={showingToday} onClick={goToday} className={`gap-2 ${showingToday ? 'border-primary bg-primary-soft text-accent-blue' : ''}`}>
                            <CalendarRange className="size-4" aria-hidden="true" />
                            Hoy
                        </SecondaryButton>
                        <IconButton icon={ChevronRight} label="Siguiente" onClick={() => step(1)} />
                        <div className="min-w-0 px-1" role="status" aria-live="polite">
                            <p className="text-xs text-ink-muted">Mostrando</p>
                            <p className="truncate text-base font-bold text-ink capitalize">{period === 'month' ? monthTitle(monthOf(filters.from)) : rangeLabel(filters.from, last)}</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="w-32">
                            <InputLabel htmlFor="agenda_days" value="Ver" />
                            <Select id="agenda_days" className="mt-1" value={filters.to ? '' : filters.days} onChange={changePeriod} options={RANGES} placeholder="Personalizado" />
                        </div>
                        {period !== 'custom' ? (
                            <div className="inline-flex overflow-hidden rounded-lg border border-field" role="group" aria-label="Forma de ver las citas">
                                <button type="button" aria-pressed={view === 'calendar'} onClick={() => chooseView('calendar')} className={`inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold ${view === 'calendar' ? 'bg-primary-soft text-accent-blue' : 'text-ink-muted hover:bg-canvas'}`}>
                                    <CalendarDays className="size-4" aria-hidden="true" />
                                    Calendario
                                </button>
                                <button type="button" aria-pressed={view === 'list'} onClick={() => chooseView('list')} className={`inline-flex items-center gap-1.5 border-l border-field px-3 py-2 text-sm font-semibold ${view === 'list' ? 'bg-primary-soft text-accent-blue' : 'text-ink-muted hover:bg-canvas'}`}>
                                    <LayoutList className="size-4" aria-hidden="true" />
                                    Lista
                                </button>
                            </div>
                        ) : null}
                        {canCreate && (
                            <PrimaryButton type="button" onClick={() => setDialog({})} className="gap-2">
                                Nueva cita
                            </PrimaryButton>
                        )}
                    </div>
                </div>

                <div className="mt-4 grid items-end gap-x-3 gap-y-3 border-t border-line pt-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <DateField id="agenda_from" label="Desde" value={filters.from} max={filters.to || undefined} today={today} onChange={changeFrom} error={backwards ? 'La fecha inicial no puede ser posterior a la final.' : undefined} />
                    <DateField id="agenda_to" label="Hasta" value={filters.to ?? ''} min={filters.from} today={today} onChange={changeTo} error={errors.to} />
                    {locations.length > 1 && (
                        <div>
                            <InputLabel htmlFor="agenda_location" value="Sede" />
                            <Select id="agenda_location" className="mt-1" value={filters.location ?? ''} onChange={(value) => visit({ location: value || undefined, professional: undefined })} options={[{ value: '', label: 'Todas mis sedes' }, ...locations.map((location) => ({ value: location.id, label: location.name }))]} />
                        </div>
                    )}
                    {locations.length === 1 && (
                        <div>
                            <p className="text-sm font-medium text-ink">Sede</p>
                            <p className="mt-2 text-sm text-ink-muted">{locations[0].name}</p>
                        </div>
                    )}
                    <div>
                        <InputLabel htmlFor="agenda_service" value="Servicio" />
                        <SearchableSelect id="agenda_service" className="mt-1" value={filters.service ?? ''} onChange={(value) => visit({ service: value || undefined, professional: undefined })} options={[{ value: '', label: 'Todos' }, ...serviceOptions.map((service) => ({ value: service.id, label: service.name }))]} />
                    </div>
                    <div>
                        <InputLabel htmlFor="agenda_pro" value="Profesional" />
                        <SearchableSelect id="agenda_pro" className="mt-1" value={filters.professional ?? ''} onChange={(value) => visit({ professional: value || undefined })} options={[{ value: '', label: 'Todos' }, ...professionals.map((professional) => ({ value: professional.id, label: professional.name }))]} />
                    </div>
                    <div>
                        <InputLabel htmlFor="agenda_status" value="Estado" />
                        <Select id="agenda_status" className="mt-1" value={filters.status ?? ''} onChange={(value) => visit({ status: value || undefined })} options={STATUSES} />
                    </div>
                    <div>
                        <InputLabel htmlFor="agenda_origin" value="Origen" />
                        <Select id="agenda_origin" className="mt-1" value={filters.origin ?? ''} onChange={(value) => visit({ origin: value || undefined })} options={ORIGINS} />
                    </div>
                </div>


                {(filtered || onlyToday) && (
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {onlyToday && <span className="inline-flex items-center rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-accent-blue" role="status">Solo las citas de hoy</span>}
                        {filtered && (
                            <button type="button" onClick={() => visit({ professional: undefined, location: undefined, service: undefined, status: undefined, origin: undefined })} className="inline-flex items-center gap-1 rounded-full border border-field px-3 py-1 text-xs font-semibold text-ink-muted hover:bg-canvas">
                                <X className="size-3.5" aria-hidden="true" />
                                Quitar los filtros
                            </button>
                        )}
                    </div>
                )}
            </Card>

            {appointments.length >= listLimit && (
                <p className="rounded-lg border border-line bg-canvas/60 p-3 text-sm text-ink-muted" role="status">
                    Se muestran las primeras {listLimit} citas de este periodo: acorta las fechas o usa los filtros para ver el resto.
                </p>
            )}

            {monthCalendar ? (
                <div className={openDay ? 'grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]' : ''}>
                    <MonthCalendar from={filters.from} counts={dayCounts ?? {}} today={today} selected={openDay} onOpen={setOpenDay} />
                    {openDay && <DayPanel date={openDay} filters={filters} onClose={() => setOpenDay(null)} onSelect={setDetail} />}
                </div>
            ) : view === 'calendar' ? (
                <>
                    {appointments.length === 0 && <p className="text-sm text-ink-muted" role="status">{filtered ? 'Ninguna cita coincide con los filtros en este periodo.' : 'Sin citas en este periodo.'}</p>}
                    <CalendarGrid appointments={appointments} days={days} today={today} onSelect={setDetail} onSelectMany={setCrowd} locationHours={options.locationHours ?? null} place={place} />
                </>
            ) : appointments.length === 0 ? (
                <EmptyState icon={CalendarDays} title={filtered ? 'Ninguna cita coincide con los filtros' : 'Sin citas en este periodo'} description={filtered ? 'Quita algún filtro o cambia las fechas.' : 'Cambia las fechas o los filtros, o crea una cita nueva.'} />
            ) : (
                <div className="space-y-4">
                    {Object.entries(listDays).map(([date, items]) => (
                        <Card key={date} className="overflow-hidden">
                            <h3 className="border-b border-line bg-canvas px-4 py-2.5 text-sm font-bold text-ink capitalize sm:px-6">{dayLabel(date)}</h3>
                            <ul className="divide-y divide-line">
                                {items.map((appointment) => (
                                    <li key={appointment.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
                                        <span className="w-24 shrink-0 text-sm font-semibold text-ink tabular-nums">{appointment.timeLabel}</span>
                                        <div className="min-w-[12rem] flex-1">
                                            <p className="text-sm font-semibold text-ink">{appointment.service}{appointment.professional && <span className="font-normal text-ink-muted"> · {appointment.professional}</span>}</p>
                                            <p className="text-sm text-ink-muted">
                                                {appointment.customer?.name}
                                                {appointment.customer?.phone && ` · ${appointment.customer.phone}`}
                                            </p>
                                            <p className="text-xs text-ink-muted">
                                                {[appointment.location, appointment.price !== null && appointment.currency ? money(appointment.price, appointment.currency) : null, appointment.originLabel].filter(Boolean).join(' · ')}
                                            </p>
                                            {appointment.notes && <p className="mt-0.5 text-xs text-ink-muted">Nota: {appointment.notes}</p>}
                                            {appointment.notifications?.length > 0 && (
                                                <p className="mt-0.5 text-xs text-ink-muted">
                                                    {appointment.notifications.map((item, index) => (
                                                        <span key={`${item.kind}-${item.offset}-${index}`} title={item.reason ?? undefined}>
                                                            {index > 0 && ' · '}
                                                            {notificationLabel(item)}: {item.statusLabel}
                                                        </span>
                                                    ))}
                                                </p>
                                            )}
                                            {appointment.status === 'cancelled' && appointment.cancelReason && <p className="mt-0.5 text-xs text-ink-muted">Motivo: {appointment.cancelReason}</p>}
                                            {appointment.syncStatus === 'failed' && <p className="mt-0.5 text-xs text-danger">{syncErrors[appointment.syncError] ?? syncLabels.failed}</p>}
                                            {appointment.syncStatus === 'uncertain' && <p className="mt-0.5 text-xs text-accent-amber">{syncLabels.uncertain}. {syncErrors[appointment.syncError] ?? ''}</p>}
                                            {appointment.external && appointment.syncStatus === 'pending' && <p className="mt-0.5 text-xs text-ink-muted">{syncLabels.pending}</p>}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge tone={statusTones[appointment.status]}>{appointment.statusLabel}</Badge>
                                            {appointment.external && <Badge tone="violet">{capabilities.label}</Badge>}
                                            {appointment.active && appointment.customerConfirmed && <Badge tone="green">Cliente confirmó</Badge>}
                                            {appointment.active && !appointment.customerConfirmed && appointment.notifications?.some((item) => item.kind === 'reminder' && ['sent', 'delivered', 'read'].includes(item.status)) && <Badge tone="amber">Sin respuesta</Badge>}
                                        </div>
                                        {actions(appointment)}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ))}
                </div>
            )}

            <Modal show={Boolean(crowd)} onClose={() => setCrowd(null)} maxWidth="md">
                {crowd && (
                    <div className="p-6">
                        <h2 className="text-lg font-bold text-ink">Citas en este horario</h2>
                        <p className="mt-1 text-sm text-ink-muted">Estas citas coinciden en el tiempo: elige una para ver su detalle.</p>
                        <ul className="mt-4 max-h-80 divide-y divide-line overflow-y-auto rounded-lg border border-line">
                            {crowd.map((item) => (
                                <li key={item.id}>
                                    <button type="button" onClick={() => { setCrowd(null); setDetail(item); }} className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-canvas focus-visible:ring-2 focus-visible:ring-primary">
                                        <span className="min-w-0">
                                            <span className="block truncate font-semibold text-ink">{item.timeLabel} · {item.service}</span>
                                            <span className="block truncate text-xs text-ink-muted">{[item.professional, item.customer?.name, item.location].filter(Boolean).join(' · ')}</span>
                                        </span>
                                        <Badge tone={statusTones[item.status]}>{item.statusLabel}</Badge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </Modal>

            <Modal show={Boolean(detail)} onClose={() => setDetail(null)} maxWidth="md">
                {detail && (
                    <div className="p-6">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs text-ink-muted capitalize">{dayLabel(detail.date)} · {detail.endLabel ? `${detail.timeLabel}–${detail.endLabel}` : detail.timeLabel}</p>
                                <h2 className="text-lg font-bold text-ink">{detail.service}</h2>
                            </div>
                            <Badge tone={statusTones[detail.status]}>{detail.statusLabel}</Badge>
                        </div>
                        <dl className="mt-4 space-y-2 text-sm">
                            {detail.professional && <div className="flex justify-between gap-4"><dt className="text-ink-muted">Profesional</dt><dd className="text-right font-medium text-ink">{detail.professional}</dd></div>}
                            {detail.location && <div className="flex justify-between gap-4"><dt className="text-ink-muted">Sede</dt><dd className="text-right font-medium text-ink">{detail.location}</dd></div>}
                            <div className="flex justify-between gap-4"><dt className="text-ink-muted">Cliente</dt><dd className="text-right font-medium text-ink">{detail.customer?.name}{detail.customer?.phone && ` · ${detail.customer.phone}`}</dd></div>
                            {detail.restMinutes > 0 && <div className="flex justify-between gap-4"><dt className="text-ink-muted">Descanso después</dt><dd className="text-right font-medium text-ink">{detail.restMinutes} min</dd></div>}
                            {detail.price !== null && detail.currency && <div className="flex justify-between gap-4"><dt className="text-ink-muted">Precio registrado</dt><dd className="text-right font-medium text-ink">{money(detail.price, detail.currency)}</dd></div>}
                            <div className="flex justify-between gap-4"><dt className="text-ink-muted">Origen</dt><dd className="text-right font-medium text-ink">{detail.originLabel}</dd></div>
                            {detail.notes && <div><dt className="text-ink-muted">Nota</dt><dd className="mt-0.5 text-ink">{detail.notes}</dd></div>}
                            {detail.notifications?.length > 0 && (
                                <div><dt className="text-ink-muted">Avisos</dt><dd className="mt-0.5 text-ink">{detail.notifications.map((item) => `${notificationLabel(item)}: ${item.statusLabel}`).join(' · ')}</dd></div>
                            )}
                            {detail.active && detail.customerConfirmed && <div><Badge tone="green">Cliente confirmó</Badge></div>}
                        </dl>
                        <div className="mt-5 flex items-center justify-between gap-3 border-t border-line pt-4" onClickCapture={() => setTimeout(() => setDetail(null), 0)}>
                            <span className="text-xs text-ink-muted">Acciones</span>
                            {actions(detail) || <span className="text-xs text-ink-muted">Esta cita no admite cambios.</span>}
                        </div>
                    </div>
                )}
            </Modal>

            {cancelling && <CancelDialog appointment={cancelling} onClose={() => setCancelling(null)} />}
            {dialog && <AppointmentDialog appointment={dialog.id ? dialog : null} options={options} today={today} onClose={() => setDialog(null)} />}
        </div>
    );
}
