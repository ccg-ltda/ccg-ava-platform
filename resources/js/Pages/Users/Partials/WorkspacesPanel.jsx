import { router, useForm, usePage } from '@inertiajs/react';
import { Building2, Pencil, Plus, Power, UserMinus, UsersRound } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
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
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';
import { roleTone } from '@/config/roles';
import usePagedList from '@/Hooks/usePagedList';

const FIELD = 'mt-1';

function FormModal({ show, title, onClose, onSubmit, processing, submitLabel, children }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={onSubmit} className="p-6">
                <h2 className="text-lg font-bold text-ink">{title}</h2>
                <div className="mt-4 space-y-4">{children}</div>
                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={processing}>
                        {submitLabel}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Members of the selected Workspace: change role, remove the membership (never the account), add an existing account. */
function MembersModal({ workspace, canAdd, listMeta, perPageOptions, onClose }) {
    const { errors } = usePage().props;
    const confirm = useConfirm();
    const add = useForm({ email: '', role: '' });
    // Members page on the server like every list; the Workspaces list keeps its own page and size meanwhile.
    const members = usePagedList({
        tab: 'workspaces',
        section: 'members',
        only: ['workspaces'],
        meta: workspace.members.meta,
        perPageOptions,
        extra: { workspace: workspace.id, workspaces_page: listMeta.current_page, workspaces_per_page: listMeta.per_page },
    });
    const roleOptions = (current) =>
        [...new Set([current, ...workspace.assignableRoles].filter(Boolean))].map((role) => ({ value: role, label: role }));

    const submitAdd = (e) => {
        e.preventDefault();
        add.post(route('workspaces.members.store', workspace.id), { preserveScroll: true, onSuccess: () => add.reset() });
    };

    const changeRole = (member, role) =>
        router.put(route('workspaces.members.update', [workspace.id, member.id]), { role }, { preserveScroll: true });

    const removeMember = async (member) => {
        const confirmed = await confirm({
            description: `${member.name} dejará de pertenecer a ${workspace.name}. Su cuenta no se elimina y conserva el acceso a otros Workspaces.`,
            confirmLabel: 'Quitar',
        });

        if (confirmed) {
            router.delete(route('workspaces.members.destroy', [workspace.id, member.id]), { preserveScroll: true });
        }
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <div className="p-6">
                <h2 className="text-lg font-bold text-ink">Miembros de {workspace.name}</h2>

                {(errors.member || errors.role) && (
                    <Alert tone="danger" className="mt-4">
                        {errors.member ?? errors.role}
                    </Alert>
                )}

                <div className="mt-4 overflow-hidden rounded-lg border border-line">
                    {workspace.members.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState icon={UsersRound} title="Sin miembros" description="Este Workspace todavía no tiene usuarios." />
                        </div>
                    ) : (
                        <TableWrap busy={members.loading}>
                            <thead>
                                <tr>
                                    <Th>Usuario</Th>
                                    <Th>Rol</Th>
                                    <Th className="text-center">Acciones</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {workspace.members.data.map((member) => (
                                    <tr key={member.id}>
                                        <Td>
                                            <div className="font-semibold text-ink">
                                                {member.name}
                                                {!member.isActive && <Badge className="ms-2">Inactivo</Badge>}
                                            </div>
                                            <div className="text-xs text-ink-muted">{member.email}</div>
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {member.canManage && !member.isSelf ? (
                                                <Select
                                                    size="sm"
                                                    aria-label={`Rol de ${member.name}`}
                                                    className="min-w-32"
                                                    value={member.role}
                                                    onChange={(role) => changeRole(member, role)}
                                                    options={roleOptions(member.role)}
                                                />
                                            ) : (
                                                <Badge tone={roleTone(member.role)}>{member.role}</Badge>
                                            )}
                                        </Td>
                                        <Td>
                                            <div className="flex items-center justify-center gap-1">
                                                {member.canManage && !member.isSelf && (
                                                    <IconButton icon={UserMinus} label="Quitar del Workspace" context={member.name} tone="danger" onClick={() => removeMember(member)} />
                                                )}
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </TableWrap>
                    )}
                    <Pagination noun="miembros" {...members.paginationProps} />
                </div>

                {canAdd && (
                    <form onSubmit={submitAdd} className="mt-6 grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-end">
                        <div>
                            <InputLabel htmlFor="member_email" value="Añadir usuario existente (correo)" />
                            <TextInput id="member_email" type="email" className={FIELD} value={add.data.email} onChange={(e) => add.setData('email', e.target.value)} invalid={Boolean(add.errors.email)} required />
                        </div>
                        <div>
                            <InputLabel htmlFor="member_role" value="Rol" />
                            <Select
                                id="member_role"
                                className={FIELD}
                                value={add.data.role}
                                onChange={(role) => add.setData('role', role)}
                                options={workspace.assignableRoles.map((role) => ({ value: role, label: role }))}
                                invalid={Boolean(add.errors.role)}
                            />
                        </div>
                        <PrimaryButton type="submit" disabled={add.processing} className="justify-center">
                            Añadir
                        </PrimaryButton>
                        <div className="sm:col-span-3">
                            <InputError message={add.errors.email || add.errors.role} />
                        </div>
                    </form>
                )}

                <div className="mt-6 flex justify-end">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cerrar
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}

/**
 * Workspaces tab: Organization -> Workspaces -> members. Superusers create/edit Workspaces and add existing
 * accounts; admins of a Workspace manage the roles and memberships of the Workspaces they administer.
 * Nothing here deletes users or Workspaces; the server enforces every rule.
 */
export default function WorkspacesPanel({ workspaces, perPageOptions }) {
    const { list: workspaceList, organizationOptions, canManage, selected } = workspaces;
    const confirm = useConfirm();
    const toast = useToast();

    const paged = usePagedList({ tab: 'workspaces', section: 'workspaces', only: ['workspaces'], meta: workspaceList.meta, perPageOptions });

    const create = useForm({ organization_id: '', code: '', name: '' });
    const edit = useForm({ name: '', code: '' });
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);

    const openMembers = (id) => paged.visit({ workspace: id });
    const closeMembers = () => paged.visit({ workspace: undefined });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('workspaces.store'), {
            onSuccess: () => {
                create.reset();
                setCreating(false);
            },
        });
    };

    const openEdit = (workspace) => {
        setEditing(workspace);
        edit.clearErrors();
        edit.setData({ name: workspace.name, code: workspace.code });
    };

    const normalizedCode = edit.data.code.trim().toUpperCase();
    const codeChanged = Boolean(editing) && normalizedCode !== editing.code;

    const submitEdit = async (e) => {
        e.preventDefault();

        if (codeChanged) {
            const confirmed = await confirm({
                description: `Vas a cambiar el código de ${editing.code} a ${normalizedCode}. El código anterior dejará de funcionar en el acceso (PreLogin). Los miembros y roles no cambian.`,
                confirmLabel: 'Cambiar código',
            });

            if (!confirmed) return;
        }

        edit.put(route('workspaces.update', editing.id), { onSuccess: () => setEditing(null) });
    };

    // Disabling is not deleting: members, roles and history stay, and it can be undone.
    const toggleStatus = async (workspace) => {
        const deactivating = workspace.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `Nadie podrá ingresar a ${workspace.name} ni se le asignarán usuarios, pero conserva miembros e historial y puede reactivarse.`
                : `${workspace.name} volverá a permitir el ingreso y las asignaciones, con sus miembros intactos.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        router.post(route(deactivating ? 'workspaces.deactivate' : 'workspaces.activate', workspace.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.status ?? 'No se pudo cambiar el estado.'),
        });
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <p className="max-w-2xl text-sm text-ink-muted">
                    {canManage
                        ? 'Como superusuario administras todos los Workspaces. Quitar a un usuario solo elimina su membresía.'
                        : 'Workspaces donde administras usuarios. Quitar a un usuario solo elimina su membresía.'}
                </p>
                {canManage && (
                    <PrimaryButton type="button" onClick={() => setCreating(true)} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo Workspace
                    </PrimaryButton>
                )}
            </div>

            {workspaceList.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Building2} title="Sin Workspaces" description="No administras ningún Workspace." />
                </div>
            ) : (
                <TableWrap busy={paged.loading}>
                    <thead>
                        <tr>
                            <Th>Workspace</Th>
                            <Th>Código</Th>
                            <Th>Organización</Th>
                            <Th>Estado</Th>
                            <Th>Miembros</Th>
                            <Th className="text-center">Acciones</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {workspaceList.data.map((workspace) => (
                            <tr key={workspace.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td className="font-semibold whitespace-nowrap text-ink">{workspace.name}</Td>
                                <Td className="whitespace-nowrap text-ink-muted">{workspace.code}</Td>
                                <Td className="whitespace-nowrap text-ink-muted">{workspace.organization}</Td>
                                <Td className="whitespace-nowrap">
                                    <Badge tone={workspace.isActive ? 'green' : 'neutral'}>{workspace.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                </Td>
                                <Td className="text-ink-muted">{workspace.membersCount}</Td>
                                <Td>
                                    <div className="flex items-center justify-center gap-1">
                                        <IconButton icon={UsersRound} label="Miembros" context={workspace.name} onClick={() => openMembers(workspace.id)} />
                                        {canManage && (
                                            <>
                                                <IconButton icon={Pencil} label="Editar" context={workspace.name} onClick={() => openEdit(workspace)} />
                                                <IconButton
                                                    icon={Power}
                                                    label={workspace.isActive ? 'Desactivar' : 'Activar'}
                                                    context={workspace.name}
                                                    tone={workspace.isActive ? 'danger' : 'primary'}
                                                    onClick={() => toggleStatus(workspace)}
                                                />
                                            </>
                                        )}
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <Pagination noun="Workspaces" {...paged.paginationProps} />

            {selected && <MembersModal key={selected.id} workspace={selected} canAdd={canManage} listMeta={workspaceList.meta} perPageOptions={perPageOptions} onClose={closeMembers} />}

            <FormModal show={creating} title="Nuevo Workspace" onClose={() => setCreating(false)} onSubmit={submitCreate} processing={create.processing} submitLabel="Crear Workspace">
                <div>
                    <InputLabel htmlFor="ws_organization" value="Organización" />
                    <Select
                        id="ws_organization"
                        className={FIELD}
                        value={create.data.organization_id}
                        onChange={(value) => create.setData('organization_id', value)}
                        options={organizationOptions.map((organization) => ({ value: String(organization.id), label: organization.name }))}
                        placeholder="Seleccionar organización"
                        invalid={Boolean(create.errors.organization_id)}
                    />
                    <InputError message={create.errors.organization_id} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="ws_code" value="Código (se usa para ingresar)" />
                    <TextInput id="ws_code" className={FIELD} value={create.data.code} onChange={(e) => create.setData('code', e.target.value)} invalid={Boolean(create.errors.code)} required />
                    <InputError message={create.errors.code} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="ws_name" value="Nombre" />
                    <TextInput id="ws_name" className={FIELD} value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} invalid={Boolean(create.errors.name)} required />
                    <InputError message={create.errors.name} className="mt-1" />
                </div>
            </FormModal>

            <FormModal show={Boolean(editing)} title="Editar Workspace" onClose={() => setEditing(null)} onSubmit={submitEdit} processing={edit.processing} submitLabel="Guardar cambios">
                <div>
                    <InputLabel htmlFor="ws_edit_name" value="Nombre" />
                    <TextInput id="ws_edit_name" className={FIELD} value={edit.data.name} onChange={(e) => edit.setData('name', e.target.value)} invalid={Boolean(edit.errors.name)} required />
                    <InputError message={edit.errors.name} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="ws_edit_code" value="Código (se usa para ingresar)" />
                    <TextInput id="ws_edit_code" className={FIELD} value={edit.data.code} onChange={(e) => edit.setData('code', e.target.value)} invalid={Boolean(edit.errors.code)} required />
                    <InputError message={edit.errors.code} className="mt-1" />
                    {codeChanged && (
                        <Alert tone="warning" className="mt-2">
                            El código anterior ({editing.code}) dejará de funcionar para ingresar. Los miembros, roles e historial no cambian.
                        </Alert>
                    )}
                </div>
            </FormModal>
        </Card>
    );
}
