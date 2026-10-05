import { useForm } from '@inertiajs/react';
import { Lock, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import Checkbox from '@/Components/Checkbox';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import { roleTone } from '@/config/roles';
import usePagedList from '@/Hooks/usePagedList';

const VISIBLE_PERMISSIONS = 3;

/** Checkbox list of the permission catalog (permissions are defined by the system, never created here). */
function PermissionPicker({ permissions, selected, onChange, error }) {
    const toggle = (name) => onChange(selected.includes(name) ? selected.filter((item) => item !== name) : [...selected, name]);

    return (
        <fieldset>
            <legend className="text-sm font-medium text-ink">Permisos</legend>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {permissions.map((name) => (
                    <label key={name} className="flex cursor-pointer items-center gap-2 rounded-lg border border-field px-3 py-2 text-sm text-ink transition-colors duration-150 hover:border-primary/40 has-checked:border-primary/50 has-checked:bg-primary-soft/40">
                        <Checkbox checked={selected.includes(name)} onChange={() => toggle(name)} />
                        {name}
                    </label>
                ))}
            </div>
            <InputError message={error} className="mt-1" />
        </fieldset>
    );
}

function RoleModal({ show, title, onClose, onSubmit, processing, children }) {
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
                        Guardar
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/**
 * Roles tab: the global role catalog. Everyone who manages users sees it; only superusers
 * (`canManage`) can create roles or change their permissions (roles are never deleted), because the catalog
 * is shared by every Workspace. The server enforces all of it.
 */
export default function RolesPanel({ roles, permissionNames, perPageOptions, canManage }) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);

    const list = usePagedList({ tab: 'roles', section: 'roles', only: ['roles'], meta: roles.meta, perPageOptions });

    const create = useForm({ name: '', permissions: [] });
    const edit = useForm({ permissions: [] });

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('roles.store'), {
            onSuccess: () => {
                create.reset();
                setCreating(false);
            },
        });
    };

    const openEdit = (role) => {
        setEditing(role);
        edit.clearErrors();
        edit.setData({ permissions: role.permissions });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        edit.put(route('roles.update', editing.id), { onSuccess: () => setEditing(null) });
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <p className="max-w-2xl text-sm text-ink-muted">
                    Los roles son compartidos por todos los Workspaces.{' '}
                    {canManage ? 'Como superusuario puedes crearlos y ajustar sus permisos.' : 'Solo un superusuario puede modificarlos.'}
                </p>
                {canManage && (
                    <PrimaryButton type="button" onClick={() => setCreating(true)} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo rol
                    </PrimaryButton>
                )}
            </div>

            <TableWrap busy={list.loading}>
                <thead>
                    <tr>
                        <Th>Rol</Th>
                        <Th>Permisos</Th>
                        <Th>Usuarios aquí</Th>
                        {canManage && <Th className="text-center">Acciones</Th>}
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {roles.data.map((role) => (
                        <tr key={role.id} className="transition-colors duration-150 hover:bg-canvas/70">
                            <Td className="whitespace-nowrap">
                                <span className="flex items-center gap-2">
                                    <Badge tone={roleTone(role.name)}>{role.name}</Badge>
                                    {role.protected && <Lock className="size-3.5 text-ink-muted" aria-label="Rol del sistema" />}
                                </span>
                            </Td>
                            <Td>
                                <div className="flex flex-wrap gap-1.5">
                                    {role.permissions.length === 0 && <span className="text-xs text-ink-muted">Sin permisos</span>}
                                    {role.permissions.slice(0, VISIBLE_PERMISSIONS).map((permission) => (
                                        <Badge key={permission} className="normal-case tracking-normal">
                                            {permission}
                                        </Badge>
                                    ))}
                                    {role.permissions.length > VISIBLE_PERMISSIONS && (
                                        <Badge className="normal-case tracking-normal" title={role.permissions.join(', ')}>
                                            +{role.permissions.length - VISIBLE_PERMISSIONS}
                                        </Badge>
                                    )}
                                </div>
                            </Td>
                            <Td className="text-ink-muted">{role.usersInWorkspace}</Td>
                            {canManage && (
                                <Td>
                                    <div className="flex items-center justify-center gap-1">
                                        {role.protected ? (
                                            <span className="text-xs text-ink-muted">Rol del sistema</span>
                                        ) : (
                                            <IconButton icon={Pencil} label="Editar permisos" context={role.name} onClick={() => openEdit(role)} />
                                        )}
                                    </div>
                                </Td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </TableWrap>

            <Pagination noun="roles" {...list.paginationProps} />

            <RoleModal show={creating} title="Nuevo rol" onClose={() => setCreating(false)} onSubmit={submitCreate} processing={create.processing}>
                <div>
                    <InputLabel htmlFor="role_name" value="Nombre del rol" />
                    <TextInput id="role_name" className="mt-1" value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} placeholder="ejemplo: coordinador" invalid={Boolean(create.errors.name)} required />
                    <InputError message={create.errors.name} className="mt-1" />
                </div>
                <PermissionPicker permissions={permissionNames} selected={create.data.permissions} onChange={(value) => create.setData('permissions', value)} error={create.errors.permissions} />
            </RoleModal>

            <RoleModal show={Boolean(editing)} title={`Permisos de ${editing?.name ?? ''}`} onClose={() => setEditing(null)} onSubmit={submitEdit} processing={edit.processing}>
                <PermissionPicker permissions={permissionNames} selected={edit.data.permissions} onChange={(value) => edit.setData('permissions', value)} error={edit.errors.permissions} />
            </RoleModal>
        </Card>
    );
}
