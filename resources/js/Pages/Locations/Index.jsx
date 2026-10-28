import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MapPin, Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import ExternalAgendaNotice from '@/Components/Booking/ExternalAgendaNotice';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import EntityPicker from '@/Components/EntityPicker';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import TimeField from '@/Components/TimeField';
import useAccess from '@/Hooks/useAccess';
import AppLayout from '@/Layouts/AppLayout';

const DAYS = [
    [1, 'Lunes'],
    [2, 'Martes'],
    [3, 'Miércoles'],
    [4, 'Jueves'],
    [5, 'Viernes'],
    [6, 'Sábado'],
    [7, 'Domingo'],
];

/** The week the editor starts from: what the sede already has, or (a sede with no hours yet) nothing is open until the person decides. */
const draftFrom = (hours) => DAYS.map(([weekday]) => {
    const saved = (hours ?? []).find((day) => day.weekday === weekday);

    return { weekday, open: Boolean(saved), opens: saved?.opensAt ?? '09:00', closes: saved?.closesAt ?? '18:00' };
});

/**
 * The hours of attention of the sede, by day. Off = no hours configured (the sede is not restricted and the Agenda keeps its usual scale: nothing is assumed). On = the days marked
 * open, each with its own opening and closing; an unmarked day is closed. The Agenda draws these hours and the server only offers appointments that fit inside them.
 */
function HoursEditor({ enabled, onEnabled, days, onChange, error }) {
    const edit = (weekday, patch) => onChange(days.map((day) => (day.weekday === weekday ? { ...day, ...patch } : day)));
    const copyFirstOpen = () => {
        const source = days.find((day) => day.open);

        if (source) onChange(days.map((day) => (day.open ? { ...day, opens: source.opens, closes: source.closes } : day)));
    };

    return (
        <fieldset className="rounded-lg border border-line p-3">
            <legend className="px-1 text-sm font-medium text-ink">Horario de atención</legend>
            <label className="flex items-start gap-2 text-sm text-ink">
                <input type="checkbox" className="mt-0.5 size-4 rounded border-field" checked={enabled} onChange={(event) => onEnabled(event.target.checked)} />
                <span>
                    Esta sede tiene un horario de atención
                    <span className="block text-xs text-ink-muted">Sin horario, la sede no se limita y la Agenda usa su escala habitual (8:00 a. m. – 6:00 p. m.). Con horario, la Agenda lo dibuja y solo se ofrecen citas que terminan antes del cierre.</span>
                </span>
            </label>

            {enabled && (
                <div className="mt-3 divide-y divide-line rounded-lg border border-line">
                    {days.map((day) => {
                        const label = DAYS.find(([weekday]) => weekday === day.weekday)[1];

                        return (
                            <div key={day.weekday} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2">
                                <label className="flex w-full items-center gap-2 text-sm font-medium text-ink sm:w-36">
                                    <input type="checkbox" className="size-4 rounded border-field" checked={day.open} onChange={(event) => edit(day.weekday, { open: event.target.checked })} />
                                    {label}
                                </label>
                                {day.open ? (
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <TimeField compact aria-label={`${label}: apertura`} className="min-w-[7.5rem] flex-1 sm:w-[9.5rem] sm:flex-none" value={day.opens} onChange={(time) => edit(day.weekday, { opens: time })} />
                                        <span className="text-ink-muted" aria-hidden="true">–</span>
                                        <TimeField compact aria-label={`${label}: cierre`} className="min-w-[7.5rem] flex-1 sm:w-[9.5rem] sm:flex-none" value={day.closes} onChange={(time) => edit(day.weekday, { closes: time })} />
                                    </span>
                                ) : (
                                    <span className="text-sm text-ink-muted">Cerrada</span>
                                )}
                            </div>
                        );
                    })}
                    <div className="px-3 py-2">
                        <button type="button" onClick={copyFirstOpen} className="text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                            Usar el primer horario en todos los días abiertos
                        </button>
                    </div>
                </div>
            )}
            <InputError message={error} className="mt-1" />
        </fieldset>
    );
}

function LocationModal({ location, services, onClose }) {
    const editing = Boolean(location?.id);
    const form = useForm({
        name: location?.name ?? '',
        address: location?.address ?? '',
        city: location?.city ?? '',
        phone: location?.phone ?? '',
        notes: location?.notes ?? '',
        // «Todos los servicios» is the flag, not a list: it is on when no service is chosen one by one (the server ignores any list while it is on).
        service_ids: location?.offersAllServices ? [] : (location?.serviceIds ?? []),
        offers_all_services: location ? Boolean(location.offersAllServices) : false,
        // The hours: `has_hours` false = none configured; the week below is what is sent when it is true (see `transform`).
        has_hours: Boolean(location?.hours),
        week: draftFrom(location?.hours),
    });
    const { data, setData, errors } = form;
    form.transform(({ week, ...rest }) => ({ ...rest, hours: rest.has_hours ? week.filter((day) => day.open).map((day) => ({ weekday: day.weekday, opens_at: day.opens, closes_at: day.closes })) : [] }));
    const serviceOptions = services.map((service) => ({ value: service.id, label: service.isActive ? service.name : `${service.name} (inactivo)`, disabled: !service.isActive && !data.service_ids.includes(service.id) }));

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('locations.update', location.id), options);
        else form.post(route('locations.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar sede' : 'Nueva sede'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-8">
                    <div>
                        <InputLabel htmlFor="location_name" value="Nombre" />
                        <TextInput id="location_name" className="mt-1" value={data.name} maxLength={120} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="location_address" value="Dirección (opcional)" />
                            <TextInput id="location_address" className="mt-1" value={data.address} maxLength={255} onChange={(e) => setData('address', e.target.value)} invalid={Boolean(errors.address)} />
                            <InputError message={errors.address} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="location_city" value="Ciudad (opcional)" />
                            <TextInput id="location_city" className="mt-1" value={data.city} maxLength={120} onChange={(e) => setData('city', e.target.value)} invalid={Boolean(errors.city)} />
                            <InputError message={errors.city} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="location_phone" value="Teléfono (opcional)" />
                            <TextInput id="location_phone" type="tel" className="mt-1" placeholder="+573001112233" value={data.phone} maxLength={32} onChange={(e) => setData('phone', e.target.value)} invalid={Boolean(errors.phone)} />
                            <InputError message={errors.phone} className="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel htmlFor="location_notes" value="Cómo llegar o indicaciones (opcional)" />
                        <Textarea id="location_notes" rows={2} className="mt-1" value={data.notes} maxLength={500} onChange={(e) => setData('notes', e.target.value)} />
                        <InputError message={errors.notes} className="mt-1" />
                    </div>
                    <HoursEditor enabled={data.has_hours} onEnabled={(value) => setData('has_hours', value)} days={data.week} onChange={(week) => setData('week', week)} error={errors.hours ?? errors['hours.0.opens_at'] ?? errors['hours.0.closes_at']} />
                    <div>
                        <InputLabel htmlFor="location_services" value="Servicios que ofrece" />
                        <p className="mt-0.5 mb-2 text-xs text-ink-muted">«Todos los servicios» incluye los que crees más adelante. Si excluyes alguno, la sede ofrece solo los que quedan. Los horarios de cada profesional por sede se eligen en Profesionales.</p>
                        <EntityPicker id="location_services" options={serviceOptions} value={data.service_ids} onChange={(ids) => setData((current) => ({ ...current, service_ids: ids, offers_all_services: ids.length === 0 }))} placeholder="Buscar servicio" allLabel="Todos los servicios" noneLabel={services.length === 0 ? 'Primero crea un servicio en Servicios' : 'Todos los servicios'} invalid={Boolean(errors.service_ids)} error={errors.service_ids ?? errors['service_ids.0']} />
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Guardar cambios' : 'Crear sede'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Sedes: where the business attends. Optional: a Workspace without sedes books exactly as before. */
export default function Index({ capabilities, locations, services, schemaPending }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const usable = !capabilities.external && !schemaPending;
    const canCreate = permissions.includes('create-locations') && usable;
    const canEdit = permissions.includes('edit-locations') && usable;
    const canToggle = permissions.includes('activate-locations') && usable;
    const canAct = canEdit || canToggle;
    const [editing, setEditing] = useState(null);
    const { errors } = usePage().props;

    const toggle = async (location) => {
        const deactivating = location.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `«${location.name}» dejará de ofrecerse para nuevas reservas.${location.upcomingCount > 0 ? ` Tiene ${location.upcomingCount} cita(s) próximas que seguirán vigentes, pero no se podrán reprogramar.` : ' Las citas existentes no cambian.'} Puede reactivarse.`
                : `«${location.name}» volverá a ofrecerse para reservas.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(deactivating ? 'locations.deactivate' : 'locations.activate', location.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Sedes" />

            <PageHeader title="Sedes" description="Los lugares donde atiendes. Son opcionales: sin sedes, la agenda funciona igual. Con varias, cada reserva elige una.">
                {canCreate && (
                    <PrimaryButton type="button" onClick={() => setEditing({})} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nueva sede
                    </PrimaryButton>
                )}
            </PageHeader>

            <ExternalAgendaNotice capabilities={capabilities} what="Las sedes" />
            {schemaPending && (
                <Alert tone="warning">
                    Las sedes todavía no están disponibles en este entorno: falta aplicar la última migración de la base de datos. Un administrador debe ejecutar <code>php artisan migrate</code>. Mientras tanto la agenda funciona sin sedes y no se pueden crear reservas nuevas.
                </Alert>
            )}
            {errors?.status && <InputError message={errors.status} />}

            <Card className="overflow-hidden">
                {locations.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={MapPin} title="Todavía no hay sedes" description="Crea la primera si atiendes en más de un lugar. Mientras no haya sedes, las reservas no piden ninguna.">
                            {canCreate && <PrimaryButton type="button" onClick={() => setEditing({})}>Nueva sede</PrimaryButton>}
                        </EmptyState>
                    </div>
                ) : (
                    <TableWrap>
                        <thead>
                            <tr>
                                <Th>Sede</Th>
                                <Th>Contacto</Th>
                                <Th>Servicios</Th>
                                <Th>Estado</Th>
                                {canAct && <Th className="text-center">Acciones</Th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {locations.map((location) => (
                                <tr key={location.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                    <Td>
                                        <p className="font-semibold text-ink">{location.name}</p>
                                        {(location.address || location.city) && <p className="mt-0.5 max-w-md truncate text-xs text-ink-muted">{[location.address, location.city].filter(Boolean).join(', ')}</p>}
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink-muted">{location.phone ?? '—'}</Td>
                                    <Td className="text-ink-muted">{location.serviceIds.length === 0 ? 'Todos' : `${location.serviceIds.length} servicio${location.serviceIds.length === 1 ? '' : 's'}`}</Td>
                                    <Td className="whitespace-nowrap">
                                        <Badge tone={location.isActive ? 'green' : 'neutral'}>{location.isActive ? 'Activa' : 'Inactiva'}</Badge>
                                    </Td>
                                    {canAct && (
                                        <Td>
                                            <div className="flex items-center justify-center gap-1">
                                                {canEdit && <IconButton icon={Pencil} label="Editar" context={location.name} onClick={() => setEditing(location)} />}
                                                {canToggle && <IconButton icon={Power} label={location.isActive ? 'Desactivar' : 'Activar'} context={location.name} tone={location.isActive ? 'danger' : 'primary'} onClick={() => toggle(location)} />}
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </TableWrap>
                )}
            </Card>

            {editing && <LocationModal location={editing} services={services} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
