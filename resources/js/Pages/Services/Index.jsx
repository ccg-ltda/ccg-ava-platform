import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Clock, Pencil, Plus, Power, Scissors, Tag } from 'lucide-react';
import { useState } from 'react';
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
import Select from '@/Components/Select';
import SecondaryButton from '@/Components/SecondaryButton';
import Tabs from '@/Components/Tabs';
import { TableWrap, Td, Th } from '@/Components/Table';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import useAccess from '@/Hooks/useAccess';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import CategoriesPanel from './Partials/CategoriesPanel';

const money = (value, currency) => (value === null || value === undefined ? '—' : new Intl.NumberFormat('es', { style: 'currency', currency }).format(value));

function ServiceModal({ service, resources, locations = [], locationsLimited = false, categoryAdmin, onManageCategories, onClose }) {
    const editing = Boolean(service?.id);
    const form = useForm({
        name: service?.name ?? '',
        description: service?.description ?? '',
        category_id: service?.categoryId ?? '',
        duration_minutes: service?.durationMinutes ?? 30,
        buffer_minutes: service?.bufferMinutes ?? 0,
        price: service?.price ?? '',
        resource_ids: service?.resourceIds ?? [],
        location_ids: service?.locationIds ?? [],
    });
    const { data, setData, errors } = form;
    const locationOptions = locations.map((location) => ({ value: location.id, label: location.name }));
    // Active categories only; the one the service already has stays listed even if it was switched off, so editing never drops it silently.
    const categoryOptions = [{ value: '', label: 'Sin categoría' }, ...(categoryAdmin?.data ?? []).filter((category) => category.isActive || category.id === service?.categoryId).map((category) => ({ value: String(category.id), label: category.isActive ? category.name : `${category.name} (inactiva)` }))];
    const resourceOptions = resources.map((resource) => ({ value: resource.id, label: resource.name }));

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('services.update', service.id), options);
        else form.post(route('services.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
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
                            <InputLabel htmlFor="service_buffer" value="Descanso después de cada cita (min)" />
                            <TextInput id="service_buffer" type="number" min={0} max={240} step={5} className="mt-1" value={data.buffer_minutes} onChange={(e) => setData('buffer_minutes', e.target.value)} invalid={Boolean(errors.buffer_minutes)} />
                            <p className="mt-1 text-xs text-ink-muted">Tiempo que queda bloqueado para el profesional y el recurso después de cada cita de este servicio (descanso, limpieza o traslado). Un cambio solo afecta a las citas nuevas.</p>
                            <InputError message={errors.buffer_minutes} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="service_price" value="Precio (opcional)" />
                            <TextInput id="service_price" type="number" min={0} step="0.01" className="mt-1" value={data.price} onChange={(e) => setData('price', e.target.value)} invalid={Boolean(errors.price)} />
                            <InputError message={errors.price} className="mt-1" />
                        </div>
                        <div>
                            <div className="flex items-center justify-between gap-2">
                                <InputLabel htmlFor="service_category" value="Categoría (opcional)" />
                                {categoryAdmin && (categoryAdmin.canCreate || categoryAdmin.canEdit) && (
                                    <button type="button" onClick={onManageCategories} className="inline-flex items-center gap-1 text-xs font-semibold text-accent-blue hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                                        <Tag className="size-3" aria-hidden="true" />
                                        Administrar categorías
                                    </button>
                                )}
                            </div>
                            {categoryAdmin ? (
                                <Select id="service_category" className="mt-1" value={data.category_id === '' || data.category_id === null ? '' : String(data.category_id)} onChange={(value) => setData('category_id', value === '' ? '' : Number(value))} options={categoryOptions} />
                            ) : (
                                <p className="mt-1 text-xs text-ink-muted">Las categorías estarán disponibles al aplicar la última migración.</p>
                            )}
                            <InputError message={errors.category_id ?? errors.category} className="mt-1" />
                        </div>
                    </div>
                    {(locations.length > 0 || resources.length > 0) && (
                        <div className="grid gap-4 md:grid-cols-2">
                            {locations.length > 0 && (
                                <div>
                                    <InputLabel htmlFor="service_locations" value="Sedes donde se ofrece" />
                                    <p className="mt-0.5 mb-2 text-xs text-ink-muted">
                                        {service?.canManage === false ? 'Este servicio se ofrece en sedes que no son tuyas: no puedes cambiarlo.' : 'Si no eliges ninguna, el servicio se ofrece en todas las sedes. Cada profesional sigue ofreciendo solo los servicios que tiene asignados.'}
                                    </p>
                                    <EntityPicker id="service_locations" options={locationOptions} value={data.location_ids} onChange={(ids) => setData('location_ids', ids)} placeholder="Buscar sede" allLabel={locationsLimited ? null : 'Todas las sedes'} noneLabel={locationsLimited ? 'Elige al menos una de tus sedes' : 'Todas las sedes'} disabled={service?.canManage === false} error={errors.location_ids ?? errors['location_ids.0']} />
                                </div>
                            )}
                            {resources.length > 0 && (
                                <div>
                                    <InputLabel htmlFor="service_resources" value="Recursos que necesita (opcional)" />
                                    <p className="mt-0.5 mb-2 text-xs text-ink-muted">Si eliges alguno, la cita solo se ofrece cuando uno de ellos está libre.</p>
                                    <EntityPicker id="service_resources" options={resourceOptions} value={data.resource_ids} onChange={(ids) => setData('resource_ids', ids)} placeholder="Buscar recurso" noneLabel="Ningún recurso" error={errors.resource_ids} />
                                </div>
                            )}
                        </div>
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
export default function Index({ capabilities, services, external, externalError, resources, locations = [], locationsLimited = false, categoryAdmin = null, currency }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const canCreate = permissions.includes('create-services') && !capabilities.external;
    const canEdit = permissions.includes('edit-services') && !capabilities.external;
    const canToggle = permissions.includes('activate-services') && !capabilities.external;
    const canAct = canEdit || canToggle;
    const [editing, setEditing] = useState(null);
    const [tab, selectTab] = useUrlTab(['servicios', 'categorias']);
    const tabs = [{ id: 'servicios', label: 'Servicios', icon: Scissors, count: services.length }, { id: 'categorias', label: 'Categorías', icon: Tag, count: categoryAdmin?.data.length }];
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

            {(() => {
                const servicesPanel = (
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
                                {!capabilities.external && locations.length > 0 && <Th>Sedes</Th>}
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
                                    {!capabilities.external && locations.length > 0 && (
                                        <Td className="text-ink-muted">
                                            {service.offeredEverywhere ? <Badge tone="neutral">Todas las sedes</Badge> : locations.filter((location) => service.locationIds.includes(location.id)).map((location) => location.name).join(', ') || '—'}
                                        </Td>
                                    )}
                                    {!capabilities.external && <Td className="text-ink-muted">{service.professionalsCount}</Td>}
                                    {!capabilities.external && (
                                        <Td className="whitespace-nowrap">
                                            <Badge tone={service.isActive ? 'green' : 'neutral'}>{service.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                        </Td>
                                    )}
                                    {canAct && (
                                        <Td>
                                            <div className="flex items-center justify-center gap-1">
                                                {canEdit && service.canManage !== false && <IconButton icon={Pencil} label="Editar" context={service.name} onClick={() => setEditing(service)} />}
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
                );

                return categoryAdmin && !capabilities.external ? <Tabs tabs={tabs} selectedIndex={tab} onChange={selectTab}>{servicesPanel}<CategoriesPanel admin={categoryAdmin} /></Tabs> : servicesPanel;
            })()}

            {editing && <ServiceModal service={editing} resources={resources} locations={locations} locationsLimited={locationsLimited} categoryAdmin={categoryAdmin} onManageCategories={() => { setEditing(null); selectTab(1); }} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
