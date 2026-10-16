import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MapPin, Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import ExternalAgendaNotice from '@/Components/Booking/ExternalAgendaNotice';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
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
import useAccess from '@/Hooks/useAccess';
import AppLayout from '@/Layouts/AppLayout';

function LocationModal({ location, services, onClose }) {
    const editing = Boolean(location?.id);
    const form = useForm({
        name: location?.name ?? '',
        address: location?.address ?? '',
        city: location?.city ?? '',
        phone: location?.phone ?? '',
        notes: location?.notes ?? '',
        service_ids: location?.serviceIds ?? [],
    });
    const { data, setData, errors } = form;
    const toggleService = (id) => setData('service_ids', data.service_ids.includes(id) ? data.service_ids.filter((item) => item !== id) : [...data.service_ids, id]);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('locations.update', location.id), options);
        else form.post(route('locations.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" scrollable>
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
                    {services.length > 0 && (
                        <fieldset>
                            <legend className="text-sm font-medium text-ink">Servicios que ofrece (opcional)</legend>
                            <p className="mt-0.5 text-xs text-ink-muted">Si no marcas ninguno, esta sede ofrece todos los servicios. Los horarios de cada profesional por sede se eligen en Profesionales.</p>
                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                {services.map((service) => (
                                    <label key={service.id} className="flex cursor-pointer items-center gap-2 rounded-lg border border-field px-3 py-2 text-sm text-ink has-checked:border-primary/50 has-checked:bg-primary-soft/40">
                                        <input type="checkbox" className="size-4 accent-primary" checked={data.service_ids.includes(service.id)} onChange={() => toggleService(service.id)} />
                                        {service.name}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.service_ids ?? errors['service_ids.0']} className="mt-1" />
                        </fieldset>
                    )}
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
    const canManage = permissions.includes('manage-services') && !capabilities.external && !schemaPending;
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
                {canManage && (
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
                            {canManage && <PrimaryButton type="button" onClick={() => setEditing({})}>Nueva sede</PrimaryButton>}
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
                                {canManage && <Th className="text-center">Acciones</Th>}
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
                                    {canManage && (
                                        <Td>
                                            <div className="flex items-center justify-center gap-1">
                                                <IconButton icon={Pencil} label="Editar" context={location.name} onClick={() => setEditing(location)} />
                                                <IconButton icon={Power} label={location.isActive ? 'Desactivar' : 'Activar'} context={location.name} tone={location.isActive ? 'danger' : 'primary'} onClick={() => toggle(location)} />
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
