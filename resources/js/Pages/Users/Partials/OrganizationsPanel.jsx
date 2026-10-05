import { router, useForm } from '@inertiajs/react';
import { Building, Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';
import usePagedList from '@/Hooks/usePagedList';

function NameModal({ show, title, label, inputId, form, submitLabel, onClose, onSubmit }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={onSubmit} className="p-6">
                <h2 className="text-lg font-bold text-ink">{title}</h2>
                <div className="mt-4">
                    <InputLabel htmlFor={inputId} value={label} />
                    <TextInput id={inputId} className="mt-1" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} invalid={Boolean(form.errors.name)} required />
                    <InputError message={form.errors.name} className="mt-1" />
                </div>
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

/**
 * Organizaciones tab (superusers): the companies/clients that own Workspaces. Create, rename and
 * enable/disable only; nothing is ever deleted and the name never changes the ID or any relation.
 */
export default function OrganizationsPanel({ organizations, perPageOptions }) {
    const confirm = useConfirm();
    const toast = useToast();
    const list = usePagedList({ tab: 'organizaciones', section: 'organizations', only: ['organizations'], meta: organizations.meta, perPageOptions });

    const create = useForm({ name: '' });
    const edit = useForm({ name: '' });
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('organizations.store'), {
            onSuccess: () => {
                create.reset();
                setCreating(false);
            },
        });
    };

    const openEdit = (organization) => {
        setEditing(organization);
        edit.clearErrors();
        edit.setData({ name: organization.name });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        edit.put(route('organizations.update', editing.id), { onSuccess: () => setEditing(null) });
    };

    const toggleStatus = async (organization) => {
        const deactivating = organization.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `Ninguno de los Workspaces de ${organization.name} podrá usarse para ingresar, pero se conservan Workspaces, miembros e historial y puede reactivarse.`
                : `Los Workspaces de ${organization.name} volverán a poder usarse para ingresar.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        router.post(route(deactivating ? 'organizations.deactivate' : 'organizations.activate', organization.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.status ?? 'No se pudo cambiar el estado.'),
        });
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <p className="max-w-2xl text-sm text-ink-muted">Cada organización es una empresa o cliente y agrupa sus Workspaces. No se eliminan.</p>
                <PrimaryButton type="button" onClick={() => setCreating(true)} className="gap-2">
                    <Plus className="size-4" aria-hidden="true" />
                    Nueva organización
                </PrimaryButton>
            </div>

            {organizations.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Building} title="Sin organizaciones" description="Todavía no hay organizaciones registradas." />
                </div>
            ) : (
                <TableWrap busy={list.loading}>
                    <thead>
                        <tr>
                            <Th>Organización</Th>
                            <Th>Estado</Th>
                            <Th>Workspaces</Th>
                            <Th className="text-center">Acciones</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {organizations.data.map((organization) => (
                            <tr key={organization.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td className="font-semibold whitespace-nowrap text-ink">{organization.name}</Td>
                                <Td className="whitespace-nowrap">
                                    <Badge tone={organization.isActive ? 'green' : 'neutral'}>{organization.isActive ? 'Activa' : 'Inactiva'}</Badge>
                                </Td>
                                <Td className="text-ink-muted">{organization.workspacesCount}</Td>
                                <Td>
                                    <div className="flex items-center justify-center gap-1">
                                        <IconButton icon={Pencil} label="Editar" context={organization.name} onClick={() => openEdit(organization)} />
                                        <IconButton
                                            icon={Power}
                                            label={organization.isActive ? 'Desactivar' : 'Activar'}
                                            context={organization.name}
                                            tone={organization.isActive ? 'danger' : 'primary'}
                                            onClick={() => toggleStatus(organization)}
                                        />
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <Pagination noun="organizaciones" {...list.paginationProps} />

            <NameModal show={creating} title="Nueva organización" label="Nombre de la empresa" inputId="org_name" form={create} submitLabel="Crear organización" onClose={() => setCreating(false)} onSubmit={submitCreate} />
            <NameModal show={Boolean(editing)} title="Editar organización" label="Nombre de la empresa" inputId="org_edit_name" form={edit} submitLabel="Guardar cambios" onClose={() => setEditing(null)} onSubmit={submitEdit} />
        </Card>
    );
}
