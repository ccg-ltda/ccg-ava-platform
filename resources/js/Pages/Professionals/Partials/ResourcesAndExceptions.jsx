import { router, useForm } from '@inertiajs/react';
import { CalendarOff, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';

function SmallModal({ title, form, onClose, onSubmit, submitLabel, children }) {
    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={onSubmit} noValidate className="p-6">
                <h2 className="text-lg font-bold text-ink">{title}</h2>
                <div className="mt-4 space-y-4">{children}</div>
                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {submitLabel}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Optional resources (a chair, a room): a service that lists some is only offered while one of them is free. */
export function ResourcesPanel({ resources, can }) {
    const canAct = can.edit || can.toggle;
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null);
    const form = useForm({ name: '', kind: '' });

    const open = (resource = {}) => {
        form.clearErrors();
        form.setData({ name: resource.name ?? '', kind: resource.kind ?? '' });
        setEditing(resource);
    };
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing.id) form.put(route('professionals.resources.update', editing.id), options);
        else form.post(route('professionals.resources.store'), options);
    };
    const toggle = async (resource) => {
        const confirmed = await confirm({ description: resource.isActive ? `«${resource.name}» dejará de contar como libre para nuevas reservas.` : `«${resource.name}» volverá a usarse.`, confirmLabel: resource.isActive ? 'Desactivar' : 'Activar' });

        if (confirmed) router.post(route(resource.isActive ? 'professionals.resources.deactivate' : 'professionals.resources.activate', resource.id), {}, { preserveScroll: true });
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <p className="max-w-xl text-sm text-ink-muted">Recursos opcionales (una silla, una sala, un equipo). Solo los necesitas si dos citas no pueden usar lo mismo a la vez.</p>
                {can.create && (
                    <PrimaryButton type="button" onClick={() => open()} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo recurso
                    </PrimaryButton>
                )}
            </div>
            {resources.length === 0 ? (
                <div className="p-6">
                    <EmptyState title="Sin recursos" description="Tu negocio no los necesita hasta que lo decidas: las citas solo dependen del profesional." />
                </div>
            ) : (
                <TableWrap>
                    <thead>
                        <tr>
                            <Th>Recurso</Th>
                            <Th>Tipo</Th>
                            <Th>Estado</Th>
                            {canAct && <Th className="text-center">Acciones</Th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {resources.map((resource) => (
                            <tr key={resource.id} className="hover:bg-canvas/70">
                                <Td className="font-semibold text-ink">{resource.name}</Td>
                                <Td className="text-ink-muted">{resource.kind ?? '—'}</Td>
                                <Td>
                                    <Badge tone={resource.isActive ? 'green' : 'neutral'}>{resource.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                </Td>
                                {canAct && (
                                    <Td>
                                        <div className="flex items-center justify-center gap-1">
                                            {can.edit && <IconButton icon={Pencil} label="Editar" context={resource.name} onClick={() => open(resource)} />}
                                            {can.toggle && <IconButton icon={Power} label={resource.isActive ? 'Desactivar' : 'Activar'} context={resource.name} tone={resource.isActive ? 'danger' : 'primary'} onClick={() => toggle(resource)} />}
                                        </div>
                                    </Td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}
            {editing && (
                <SmallModal title={editing.id ? 'Editar recurso' : 'Nuevo recurso'} form={form} onClose={() => setEditing(null)} onSubmit={submit} submitLabel={editing.id ? 'Guardar cambios' : 'Crear recurso'}>
                    <div>
                        <InputLabel htmlFor="resource_name" value="Nombre" />
                        <TextInput id="resource_name" className="mt-1" value={form.data.name} maxLength={120} onChange={(e) => form.setData('name', e.target.value)} invalid={Boolean(form.errors.name)} />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="resource_kind" value="Tipo (opcional)" />
                        <TextInput id="resource_kind" className="mt-1" value={form.data.kind} maxLength={80} onChange={(e) => form.setData('kind', e.target.value)} />
                    </div>
                </SmallModal>
            )}
        </Card>
    );
}

/** Days (or hours) nobody can be booked: a holiday of the whole business, a professional's vacation, a blocked hour. */
export function ExceptionsPanel({ exceptions, professionals, canManage }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null);
    const form = useForm({ professional_id: '', date_from: '', date_to: '', starts_at: '', ends_at: '', reason: '' });
    const { data, setData, errors } = form;

    const open = (exception = {}) => {
        form.clearErrors();
        form.setData({ professional_id: exception.professionalId ?? '', date_from: exception.dateFrom ?? '', date_to: exception.dateTo ?? '', starts_at: exception.startsAt ?? '', ends_at: exception.endsAt ?? '', reason: exception.reason ?? '' });
        setEditing(exception);
    };
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing.id) form.put(route('professionals.exceptions.update', editing.id), options);
        else form.post(route('professionals.exceptions.store'), options);
    };
    const remove = async (exception) => {
        const confirmed = await confirm({ description: 'Ese día volverá a ofrecerse según el horario semanal.', confirmLabel: 'Quitar excepción' });

        if (confirmed) router.delete(route('professionals.exceptions.destroy', exception.id), { preserveScroll: true });
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <p className="max-w-xl text-sm text-ink-muted">Festivos, vacaciones o una hora bloqueada. Sin profesional, aplica a todo el negocio.</p>
                {canManage && (
                    <PrimaryButton type="button" onClick={() => open()} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nueva excepción
                    </PrimaryButton>
                )}
            </div>
            {exceptions.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={CalendarOff} title="Sin excepciones" description="Todos los días siguen el horario semanal." />
                </div>
            ) : (
                <TableWrap>
                    <thead>
                        <tr>
                            <Th>Cuándo</Th>
                            <Th>A quién</Th>
                            <Th>Motivo</Th>
                            {canManage && <Th className="text-center">Acciones</Th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {exceptions.map((exception) => (
                            <tr key={exception.id} className="hover:bg-canvas/70">
                                <Td className="whitespace-nowrap font-semibold text-ink">
                                    {exception.dateFrom === exception.dateTo ? exception.dateFrom : `${exception.dateFrom} → ${exception.dateTo}`}
                                    {exception.startsAt && <span className="ml-2 text-xs font-normal text-ink-muted">{exception.startsAt}–{exception.endsAt}</span>}
                                </Td>
                                <Td className="text-ink-muted">{exception.professional ?? 'Todo el negocio'}</Td>
                                <Td className="text-ink-muted">{exception.reason ?? '—'}</Td>
                                {canManage && (
                                    <Td>
                                        <div className="flex items-center justify-center gap-1">
                                            <IconButton icon={Pencil} label="Editar" context={exception.dateFrom} onClick={() => open(exception)} />
                                            <IconButton icon={Trash2} label="Quitar" context={exception.dateFrom} tone="danger" onClick={() => remove(exception)} />
                                        </div>
                                    </Td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}
            {editing && (
                <SmallModal title={editing.id ? 'Editar excepción' : 'Nueva excepción'} form={form} onClose={() => setEditing(null)} onSubmit={submit} submitLabel="Guardar">
                    <div>
                        <InputLabel htmlFor="exc_pro" value="A quién aplica" />
                        <Select id="exc_pro" className="mt-1" value={data.professional_id} onChange={(value) => setData('professional_id', value)} options={[{ value: '', label: 'Todo el negocio' }, ...professionals.map((p) => ({ value: p.id, label: p.name }))]} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="exc_from" value="Desde" />
                            <TextInput id="exc_from" type="date" className="mt-1" value={data.date_from} onChange={(e) => setData('date_from', e.target.value)} invalid={Boolean(errors.date_from)} />
                            <InputError message={errors.date_from} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="exc_to" value="Hasta" />
                            <TextInput id="exc_to" type="date" className="mt-1" value={data.date_to} onChange={(e) => setData('date_to', e.target.value)} invalid={Boolean(errors.date_to)} />
                            <InputError message={errors.date_to} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="exc_start" value="Hora inicial (opcional)" />
                            <TextInput id="exc_start" type="time" className="mt-1" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} invalid={Boolean(errors.starts_at)} />
                            <InputError message={errors.starts_at} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="exc_end" value="Hora final (opcional)" />
                            <TextInput id="exc_end" type="time" className="mt-1" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} invalid={Boolean(errors.ends_at)} />
                            <InputError message={errors.ends_at} className="mt-1" />
                        </div>
                    </div>
                    <p className="text-xs text-ink-muted">Sin horas, se bloquea el día completo.</p>
                    <div>
                        <InputLabel htmlFor="exc_reason" value="Motivo (opcional)" />
                        <TextInput id="exc_reason" className="mt-1" value={data.reason} maxLength={160} onChange={(e) => setData('reason', e.target.value)} />
                    </div>
                </SmallModal>
            )}
        </Card>
    );
}
