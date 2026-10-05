import { router, useForm, usePage } from '@inertiajs/react';
import { Lock, Pencil, Plus, Power, SearchX, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import WorkspaceCombobox from '@/Components/WorkspaceCombobox';
import { useToast } from '@/Components/Toast';
import { roleTone } from '@/config/roles';
import usePagedList from '@/Hooks/usePagedList';

const FIELD = 'mt-1';

const STATUS_OPTIONS = [
    { value: 'all', label: 'Todos los estados' },
    { value: 'active', label: 'Activos' },
    { value: 'inactive', label: 'Inactivos' },
];

/** Fields shared by the create and edit forms. The Workspace is searched on the server (the list may be huge). */
function UserFields({ data, setData, errors, roles, withPassword = false, passwordOptional = false, lockIdentity = false }) {
    return (
        <div className="space-y-4">
            {lockIdentity && (
                <Alert tone="warning">
                    Esta cuenta tiene acceso a otros Workspaces o es superusuario. Desde aquí solo puedes cambiar su rol y su Workspace; el
                    nombre, el correo y la contraseña solo los modifica un superusuario.
                </Alert>
            )}

            <div>
                <InputLabel htmlFor="name" value="Nombre" />
                <TextInput id="name" className={FIELD} value={data.name} onChange={(e) => setData('name', e.target.value)} readOnly={lockIdentity} invalid={Boolean(errors.name)} required />
                <InputError message={errors.name} className="mt-1" />
            </div>

            <div>
                <InputLabel htmlFor="email" value="Email" />
                <TextInput id="email" type="email" className={FIELD} value={data.email} onChange={(e) => setData('email', e.target.value)} readOnly={lockIdentity} invalid={Boolean(errors.email)} required />
                <InputError message={errors.email} className="mt-1" />
            </div>

            {withPassword && (
                <>
                    <div>
                        <InputLabel htmlFor="password" value={passwordOptional ? 'Contraseña (opcional)' : 'Contraseña'} />
                        <TextInput
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            className={FIELD}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder={passwordOptional ? 'Dejar vacío para conservar la actual' : undefined}
                            readOnly={lockIdentity}
                            invalid={Boolean(errors.password)}
                            required={!passwordOptional}
                        />
                        <InputError message={errors.password} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="password_confirmation" value="Confirmar contraseña" />
                        <TextInput id="password_confirmation" type="password" autoComplete="new-password" className={FIELD} value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} readOnly={lockIdentity} required={!passwordOptional} />
                    </div>
                </>
            )}

            <div>
                <InputLabel htmlFor="workspace_id" value="Workspace" />
                <WorkspaceCombobox
                    id="workspace_id"
                    purpose="assign"
                    className={FIELD}
                    value={data.workspace_option}
                    onChange={(option) => setData((previous) => ({ ...previous, workspace_option: option, workspace_id: option ? String(option.id) : '', role: '' }))}
                    placeholder="Buscar Workspace por nombre o código"
                    invalid={Boolean(errors.workspace_id)}
                />
                <InputError message={errors.workspace_id} className="mt-1" />
            </div>

            <div>
                <InputLabel htmlFor="role" value="Rol" />
                <Select
                    id="role"
                    className={FIELD}
                    value={data.role}
                    onChange={(value) => setData('role', value)}
                    options={roles.map((role) => ({ value: role.name, label: role.name }))}
                    placeholder="Seleccionar rol"
                    invalid={Boolean(errors.role)}
                />
                <InputError message={errors.role} className="mt-1" />
            </div>
        </div>
    );
}

function FormModal({ show, title, onClose, onSubmit, processing, submitLabel, children }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={onSubmit} className="p-6">
                <h2 className="text-lg font-bold text-ink">{title}</h2>
                <div className="mt-4">{children}</div>
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

/** Usuarios tab: server-side search and pagination, plus create / edit / deactivate / activate. */
export default function UsersPanel({ users, filters, perPageOptions, createTargets }) {
    const { auth } = usePage().props;
    const canManageUsers = (auth?.user?.permissions || []).includes('manage-users');

    const create = useForm({ name: '', email: '', password: '', password_confirmation: '', workspace_id: '', workspace_option: null, role: '' });
    const edit = useForm({ name: '', email: '', password: '', password_confirmation: '', workspace_id: '', workspace_option: null, role: '' });
    const confirm = useConfirm();
    const toast = useToast();
    const [showCreate, setShowCreate] = useState(false);
    const [editingUser, setEditingUser] = useState(null);

    const [search, setSearch] = useState(filters.search);
    const firstRender = useRef(true);

    const list = usePagedList({
        tab: 'usuarios',
        section: 'users',
        only: ['users', 'filters', 'perPageOptions'],
        meta: users.meta,
        perPageOptions,
        extra: { search: filters.search || undefined, status: filters.status === 'all' ? undefined : filters.status },
    });

    // Debounced search; the server filters inside the active Workspace only.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return undefined;
        }

        const timer = setTimeout(() => list.visit({ search: search || undefined }, { restart: true }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const openCreate = () => {
        create.clearErrors();
        const current = createTargets.find((target) => target.isCurrent) ?? createTargets[0];
        create.setData((previous) => ({ ...previous, workspace_option: current ?? null, workspace_id: current ? String(current.id) : '' }));
        setShowCreate(true);
    };

    const rolesFor = (option) => (option?.roles ?? []).map((name) => ({ id: name, name }));

    const submitCreate = (e) => {
        e.preventDefault();
        create.transform(({ workspace_option, ...data }) => data);
        create.post(route('users.store'), {
            onSuccess: () => {
                create.reset();
                setShowCreate(false);
            },
        });
    };

    const openEdit = (user) => {
        setEditingUser(user);
        edit.clearErrors();
        const current = createTargets.find((target) => target.isCurrent);
        edit.setData({ name: user.name, email: user.email, password: '', password_confirmation: '', workspace_id: current ? String(current.id) : '', workspace_option: current ?? null, role: user.roles[0] || '' });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        edit.transform(({ workspace_option, ...data }) => ({ ...data, password: data.password || null, password_confirmation: data.password_confirmation || null }));
        edit.put(route('users.update', editingUser.id), { onSuccess: () => setEditingUser(null) });
    };

    // Users are never deleted: deactivating blocks access, keeps ID and history, and can be undone.
    const toggleStatus = async (user) => {
        const deactivating = user.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `${user.name} no podrá iniciar sesión, pero conserva su historial y puede reactivarse.`
                : `${user.name} volverá a poder iniciar sesión con su historial intacto.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        router.post(route(deactivating ? 'users.deactivate' : 'users.activate', user.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.status ?? 'No se pudo cambiar el estado.'),
        });
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    <SearchInput value={search} onChange={setSearch} placeholder="Buscar por nombre o correo" label="Buscar usuarios" className="w-full sm:w-80" />
                    <Select
                        aria-label="Filtrar por estado"
                        className="w-full sm:w-44"
                        value={filters.status}
                        onChange={(status) => list.visit({ status: status === 'all' ? undefined : status }, { restart: true })}
                        options={STATUS_OPTIONS}
                    />
                </div>
                {canManageUsers && createTargets.length > 0 && (
                    <PrimaryButton type="button" onClick={openCreate} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo usuario
                    </PrimaryButton>
                )}
            </div>

            {users.data.length === 0 ? (
                <div className="p-6">
                    {filters.search || filters.status !== 'all' ? (
                        <EmptyState icon={SearchX} title="Sin resultados" description="Ningún usuario coincide con la búsqueda o el filtro." />
                    ) : (
                        <EmptyState icon={Users} title="No hay usuarios" description="Este Workspace todavía no tiene usuarios." />
                    )}
                </div>
            ) : (
                <TableWrap busy={list.loading}>
                    <thead>
                        <tr>
                            <Th>Nombre</Th>
                            <Th>Email</Th>
                            <Th>Rol</Th>
                            <Th>Estado</Th>
                            <Th>Creación</Th>
                            {canManageUsers && <Th className="text-center">Acciones</Th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {users.data.map((user) => (
                            <tr key={user.id} className={`transition-colors duration-150 hover:bg-canvas/70 ${user.isActive ? '' : 'bg-canvas/50 text-ink-muted'}`}>
                                <Td className="font-semibold whitespace-nowrap text-ink">
                                    {user.name}
                                    {user.identityLocked && (
                                        <Lock className="ms-2 inline size-3.5 text-ink-muted" aria-label="Cuenta compartida o superusuario" />
                                    )}
                                </Td>
                                <Td className="whitespace-nowrap text-ink-muted">{user.email}</Td>
                                <Td className="whitespace-nowrap">
                                    <Badge tone={roleTone(user.roles[0])}>{user.roles[0] || 'Sin rol'}</Badge>
                                </Td>
                                <Td className="whitespace-nowrap">
                                    <Badge tone={user.isActive ? 'green' : 'neutral'}>{user.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                </Td>
                                <Td className="whitespace-nowrap text-ink-muted">{user.created_at}</Td>
                                {canManageUsers && (
                                    <Td>
                                        <div className="flex items-center justify-center gap-1">
                                            <IconButton icon={Pencil} label="Editar" context={user.name} onClick={() => openEdit(user)} />
                                            {user.canToggleStatus && (
                                                <IconButton
                                                    icon={Power}
                                                    label={user.isActive ? 'Desactivar' : 'Activar'}
                                                    context={user.name}
                                                    tone={user.isActive ? 'danger' : 'primary'}
                                                    onClick={() => toggleStatus(user)}
                                                />
                                            )}
                                        </div>
                                    </Td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <Pagination noun="usuarios" {...list.paginationProps} />

            <FormModal show={showCreate} title="Nuevo usuario" onClose={() => setShowCreate(false)} onSubmit={submitCreate} processing={create.processing} submitLabel={create.processing ? 'Guardando...' : 'Crear usuario'}>
                <UserFields data={create.data} setData={create.setData} errors={create.errors} roles={rolesFor(create.data.workspace_option)} withPassword />
            </FormModal>

            <FormModal show={Boolean(editingUser)} title="Editar usuario" onClose={() => setEditingUser(null)} onSubmit={submitEdit} processing={edit.processing} submitLabel={edit.processing ? 'Guardando...' : 'Guardar cambios'}>
                <UserFields data={edit.data} setData={edit.setData} errors={edit.errors} roles={rolesFor(edit.data.workspace_option)} withPassword passwordOptional lockIdentity={Boolean(editingUser?.identityLocked)} />
            </FormModal>
        </Card>
    );
}
