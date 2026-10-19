import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Clock, Pencil, Plus, Power, Scissors } from 'lucide-react';
import { useState } from 'react';
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

const money = (value, currency) => (value === null || value === undefined ? '—' : new Intl.NumberFormat('es', { style: 'currency', currency }).format(value));

function ServiceModal({ service, resources, onClose }) {
    const editing = Boolean(service?.id);
    const form = useForm({
        name: service?.name ?? '',
        description: service?.description ?? '',
        category: service?.category ?? '',
        duration_minutes: service?.durationMinutes ?? 30,
        buffer_minutes: service?.bufferMinutes ?? 0,
        price: service?.price ?? '',
        resource_ids: service?.resourceIds ?? [],
    });
    const { data, setData, errors } = form;
    const toggleResource = (id) => setData('resource_ids', data.resource_ids.includes(id) ? data.resource_ids.filter((item) => item !== id) : [...data.resource_ids, id]);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('services.update', service.id), options);
        else form.post(route('services.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar servicio' : 'Nuevo servicio'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-8">
                    <div>
                        <InputLabel htmlFor="service_name" value="Nombre" />
                        <TextInput id="service_name" className="mt-1" value={data.name} maxLength={120} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="service_description" value="Descripción (opcional)" />
                        <Textarea id="service_description" className="mt-1" value={data.description} maxLength={1000} onChange={(e) => setData('description', e.target.value)} />
                        <InputError message={errors.description} className="mt-1" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="service_duration" value="Duración (minutos)" />
                            <TextInput id="service_duration" type="number" min={5} max={720} step={5} className="mt-1" value={data.duration_minutes} onChange={(e) => setData('duration_minutes', e.target.value)} invalid={Boolean(errors.duration_minutes)} />
                            <InputError message={errors.duration_minutes} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="service_buffer" value="Tiempo entre citas (min)" />
                            <TextInput id="service_buffer" type="number" min={0} max={240} step={5} className="mt-1" value={data.buffer_minutes} onChange={(e) => setData('buffer_minutes', e.target.value)} invalid={Boolean(errors.buffer_minutes)} />
                            <InputError message={errors.buffer_minutes} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="service_price" value="Precio (opcional)" />
                            <TextInput id="service_price" type="number" min={0} step="0.01" className="mt-1" value={data.price} onChange={(e) => setData('price', e.target.value)} invalid={Boolean(errors.price)} />
                            <InputError message={errors.price} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="service_category" value="Categoría (opcional)" />
                            <TextInput id="service_category" className="mt-1" value={data.category} maxLength={80} onChange={(e) => setData('category', e.target.value)} />
                            <InputError message={errors.category} className="mt-1" />
                        </div>
                    </div>
                    {resources.length > 0 && (
                        <fieldset>
                            <legend className="text-sm font-medium text-ink">Recursos que necesita (opcional)</legend>
                            <p className="mt-0.5 text-xs text-ink-muted">Si eliges alguno, la cita solo se ofrece cuando uno de ellos está libre.</p>
                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                {resources.map((resource) => (
                                    <label key={resource.id} className="flex cursor-pointer items-center gap-2 rounded-lg border border-field px-3 py-2 text-sm text-ink has-checked:border-primary/50 has-checked:bg-primary-soft/40">
                                        <input type="checkbox" className="size-4 accent-primary" checked={data.resource_ids.includes(resource.id)} onChange={() => toggleResource(resource.id)} />
                                        {resource.name}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.resource_ids} className="mt-1" />
                        </fieldset>
                    )}
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Guardar cambios' : 'Crear servicio'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Servicios: what the Workspace offers to be booked (its own, or read-only from its external agenda). */
export default function Index({ capabilities, services, external, externalError, resources, currency }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const canCreate = permissions.includes('create-services') && !capabilities.external;
    const canEdit = permissions.includes('edit-services') && !capabilities.external;
    const canToggle = permissions.includes('activate-services') && !capabilities.external;
    const canAct = canEdit || canToggle;
    const [editing, setEditing] = useState(null);
    const { errors } = usePage().props;

    const toggle = async (service) => {
        const deactivating = service.isActive;
        const confirmed = await confirm({
            description: deactivating ? `«${service.name}» dejará de ofrecerse para nuevas reservas.${service.upcomingCount > 0 ? ` Tiene ${service.upcomingCount} cita(s) próximas que seguirán vigentes.` : ' Las citas existentes no cambian.'} Puede reactivarse.` : `«${service.name}» volverá a ofrecerse para reservas.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(deactivating ? 'services.deactivate' : 'services.activate', service.id), {}, { preserveScroll: true });
    };

    const list = capabilities.external ? (external ?? []) : services;

    return (
        <>
            <Head title="Servicios" />

            <PageHeader title="Servicios" description="Lo que tu negocio ofrece para reservar: duración, precio opcional y tiempo entre citas.">
                {canCreate && (
                    <PrimaryButton type="button" onClick={() => setEditing({})} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo servicio
                    </PrimaryButton>
                )}
            </PageHeader>

            <ExternalAgendaNotice capabilities={capabilities} error={externalError} what="Los servicios" />
            {errors?.status && <InputError message={errors.status} />}

            <Card className="overflow-hidden">
                {list.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={Scissors} title="Todavía no hay servicios" description={capabilities.external ? 'La agenda externa no devolvió servicios reservables.' : 'Crea el primero para poder reservarlo.'}>
                            {canCreate && <PrimaryButton type="button" onClick={() => setEditing({})}>Nuevo servicio</PrimaryButton>}
                        </EmptyState>
                    </div>
                ) : (
                    <TableWrap>
                        <thead>
                            <tr>
                                <Th>Servicio</Th>
                                <Th>Duración</Th>
                                <Th>Precio</Th>
                                {!capabilities.external && <Th>Profesionales</Th>}
                                {!capabilities.external && <Th>Estado</Th>}
                                {canAct && <Th className="text-center">Acciones</Th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {list.map((service) => (
                                <tr key={service.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                    <Td>
                                        <p className="font-semibold text-ink">{service.name}</p>
                                        {(service.category || service.description) && <p className="mt-0.5 max-w-md truncate text-xs text-ink-muted">{[service.category, service.description].filter(Boolean).join(' · ')}</p>}
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink-muted">
                                        <span className="inline-flex items-center gap-1.5">
                                            <Clock className="size-3.5" aria-hidden="true" />
                                            {(service.durationMinutes ?? service.duration_minutes) ? `${service.durationMinutes ?? service.duration_minutes} min` : '—'}
                                            {service.bufferMinutes > 0 && <span className="text-xs">(+{service.bufferMinutes})</span>}
                                        </span>
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink-muted">{money(service.price, currency)}</Td>
                                    {!capabilities.external && <Td className="text-ink-muted">{service.professionalsCount}</Td>}
                                    {!capabilities.external && (
                                        <Td className="whitespace-nowrap">
                                            <Badge tone={service.isActive ? 'green' : 'neutral'}>{service.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                        </Td>
                                    )}
                                    {canAct && (
                                        <Td>
                                            <div className="flex items-center justify-center gap-1">
                                                {canEdit && <IconButton icon={Pencil} label="Editar" context={service.name} onClick={() => setEditing(service)} />}
                                                {canToggle && <IconButton icon={Power} label={service.isActive ? 'Desactivar' : 'Activar'} context={service.name} tone={service.isActive ? 'danger' : 'primary'} onClick={() => toggle(service)} />}
                                            </div>
                                        </Td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </TableWrap>
                )}
            </Card>

            {editing && <ServiceModal service={editing} resources={resources} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
