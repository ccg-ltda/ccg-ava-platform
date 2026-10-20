import { router, usePage } from '@inertiajs/react';
import { Ban, CalendarClock, CalendarDays, CalendarRange, Check, ChevronLeft, ChevronRight, RefreshCw, UserCheck, UserX, X } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import CancelDialog from './CancelDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import { addDays, dayLabel, money, statusTones, syncErrors, syncLabels } from '@/config/bookings';
import AppointmentDialog from './AppointmentDialog';

/** "Recordatorio (1 día antes)", "Confirmación"... for a notification of the Agenda. */
const notificationLabel = (item) => {
    const names = { received: 'Recepción', confirmed: 'Confirmación', reminder: 'Recordatorio' };
    const when = item.kind === 'reminder' && item.offset ? ` (${item.offset % 1440 === 0 ? `${item.offset / 1440} d` : item.offset % 60 === 0 ? `${item.offset / 60} h` : `${item.offset} min`} antes)` : '';

    return `${names[item.kind] ?? item.kind}${when}`;
};

const RANGES = [{ value: 1, label: 'Día' }, { value: 7, label: 'Semana' }, { value: 31, label: 'Mes' }];
const ORIGINS = [{ value: '', label: 'Todos los orígenes' }, { value: 'admin', label: 'Agenda del negocio' }, { value: 'web', label: 'Página de reservas' }, { value: 'chat', label: 'Asistente' }];
const STATUSES = [{ value: '', label: 'Todos los estados' }, { value: 'requested', label: 'Por confirmar' }, { value: 'confirmed', label: 'Confirmadas' }, { value: 'completed', label: 'Realizadas' }, { value: 'no_show', label: 'No asistió' }, { value: 'cancelled', label: 'Canceladas' }, { value: 'failed', label: 'Rechazadas' }];

/** The agenda: appointments of the chosen days grouped by day, with the actions each one allows. */
export default function AgendaPanel({ appointments, filters, options, capabilities, today, can }) {
    const [dialog, setDialog] = useState(null); // {} = new, an appointment = reschedule
    const [cancelling, setCancelling] = useState(null);

    const { errors = {} } = usePage().props;
    const clean = (params) => Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
    const visit = (params) =>
        router.get(route('bookings.index'), clean({ ...filters, ...params, tab: 'agenda', search: undefined }), { only: ['appointments', 'filters', 'errors'], preserveState: true, preserveScroll: true, replace: true });
    // A range is stepped by its own length; a custom «Hasta» moves along with «Desde».
    const step = (direction) => visit({ from: addDays(filters.from, direction * filters.days), to: filters.to ? addDays(filters.to, direction * filters.days) : undefined });
    const showingToday = filters.from === today && filters.to === today;
    const backwards = Boolean(filters.to) && filters.from > filters.to;
    const changeFrom = (value) => value && visit({ from: value });
    const changeTo = (value) => visit({ to: value || undefined });
    const act = (appointment, path, payload = {}) => router.post(route(path, appointment.id), payload, { preserveScroll: true });

    const days = appointments.reduce((groups, appointment) => ({ ...groups, [appointment.date]: [...(groups[appointment.date] ?? []), appointment] }), {});
    const started = (appointment) => new Date(appointment.startsAt) <= new Date();
    const canCreate = can.create && capabilities.connected && capabilities.availability && capabilities.book;

    return (
        <div className="space-y-4">
            {capabilities.external && !capabilities.book && (
                <p className="rounded-lg border border-line bg-canvas/60 p-3 text-sm text-ink-muted">
                    Con {capabilities.label} como agenda, Ava todavía no puede ofrecer horarios ni crear citas: esas operaciones no están verificadas con ese proveedor. Las citas que ya existan se pueden consultar y cancelar.
                </p>
            )}

            <Card className="p-4 sm:p-5">
                <div className="grid items-end gap-x-3 gap-y-4 sm:grid-cols-2 xl:grid-cols-[auto_8rem_repeat(3,minmax(0,1fr))]">
                    <div className="flex min-w-0 items-end gap-1 sm:col-span-2 xl:col-span-1">
                        <IconButton icon={ChevronLeft} label="Anterior" onClick={() => step(-1)} />
                        <div className="min-w-0 flex-1 sm:flex-none">
                            <InputLabel htmlFor="agenda_from" value="Desde" />
                            <TextInput id="agenda_from" type="date" className="mt-1 w-full sm:w-36" value={filters.from} max={filters.to || undefined} onChange={(e) => changeFrom(e.target.value)} invalid={backwards} />
                        </div>
                        <div className="min-w-0 flex-1 sm:flex-none">
                            <InputLabel htmlFor="agenda_to" value="Hasta" />
                            <TextInput id="agenda_to" type="date" className="mt-1 w-full sm:w-36" value={filters.to ?? ''} min={filters.from} onChange={(e) => changeTo(e.target.value)} invalid={Boolean(errors.to) || backwards} aria-describedby="agenda_to_error" />
                        </div>
                        <IconButton icon={ChevronRight} label="Siguiente" onClick={() => step(1)} />
                    </div>
                    <div>
                        <InputLabel htmlFor="agenda_days" value="Ver" />
                        <Select id="agenda_days" className="mt-1" value={filters.to ? '' : filters.days} onChange={(value) => visit({ days: value, to: undefined })} options={RANGES} placeholder="Personalizado" />
                    </div>
                    <div>
                        <InputLabel htmlFor="agenda_pro" value="Profesional" />
                        <SearchableSelect id="agenda_pro" className="mt-1" value={filters.professional ?? ''} onChange={(value) => visit({ professional: value || undefined })} options={[{ value: '', label: 'Todos' }, ...options.professionals.filter((p) => typeof p.id === 'number').map((p) => ({ value: p.id, label: p.name }))]} placeholder="Escribe para buscar" />
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

                <div id="agenda_to_error" aria-live="polite">
                    {backwards && <InputError message="La fecha inicial no puede ser posterior a la final." className="mt-2" />}
                    {!backwards && <InputError message={errors.to} className="mt-2" />}
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <SecondaryButton type="button" aria-pressed={showingToday} onClick={() => visit({ from: today, to: today, days: 1 })} className={`gap-2 ${showingToday ? 'border-primary bg-primary-soft text-accent-blue' : ''}`}>
                            <CalendarRange className="size-4" aria-hidden="true" />
                            Hoy
                        </SecondaryButton>
                        {showingToday && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-primary-soft py-1 pr-1 pl-3 text-xs font-semibold text-accent-blue" role="status">
                                Mostrando solo las citas de hoy
                                <button type="button" onClick={() => visit({ from: today, to: undefined, days: 7 })} aria-label="Quitar el filtro de hoy y volver a la vista de la semana" className="grid size-5 place-items-center rounded-full hover:bg-primary/15 focus-visible:outline-2 focus-visible:outline-primary">
                                    <X className="size-3.5" aria-hidden="true" />
                                </button>
                            </span>
                        )}
                    </div>
                    {canCreate && (
                        <PrimaryButton type="button" onClick={() => setDialog({})} className="gap-2">
                            Nueva cita
                        </PrimaryButton>
                    )}
                </div>
            </Card>

            {appointments.length === 0 ? (
                <EmptyState icon={CalendarDays} title="Sin citas en este periodo" description="Cambia las fechas o los filtros, o crea una cita nueva." />
            ) : (
                <div className="space-y-4">
                    {Object.entries(days).map(([date, items]) => (
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
                                        {(can.edit || can.cancel) && appointment.active && (
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
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ))}
                </div>
            )}

            {cancelling && <CancelDialog appointment={cancelling} onClose={() => setCancelling(null)} />}
            {dialog && <AppointmentDialog appointment={dialog.id ? dialog : null} options={options} today={today} onClose={() => setDialog(null)} />}
        </div>
    );
}
