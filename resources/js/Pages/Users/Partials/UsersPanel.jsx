import { router, useForm, usePage } from '@inertiajs/react';
import { Lock, Plus, SearchX, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import { roleTone } from '@/config/roles';

const FIELD = 'mt-1 block w-full';
const READ_ONLY = 'read-only:bg-canvas read-only:text-ink-muted';

/** Fields shared by the create and edit forms. */
function UserFields({ data, setData, errors, roles, withPassword = false, lockIdentity = false }) {
    return (
        <div className="space-y-4">
            {lockIdentity && (
                <Alert tone="warning">
                    Esta cuenta tiene acceso a otros Workspaces o es superusuario. Desde aquí solo puedes cambiar su rol; el nombre y
                    el correo solo los modifica un superusuario.
                </Alert>
            )}

            <div>
                <InputLabel htmlFor="name" value="Nombre" />
                <TextInput id="name" className={`${FIELD} ${READ_ONLY}`} value={data.name} onChange={(e) => setData('name', e.target.value)} readOnly={lockIdentity} required />
                <InputError message={errors.name} className="mt-1" />
            </div>

            <div>
                <InputLabel htmlFor="email" value="Email" />
                <TextInput id="email" type="email" className={`${FIELD} ${READ_ONLY}`} value={data.email} onChange={(e) => setData('email', e.target.value)} readOnly={lockIdentity} required />
                <InputError message={errors.email} className="mt-1" />
            </div>

            {withPassword && (
                <>
                    <div>
                        <InputLabel htmlFor="password" value="Contraseña" />
                        <TextInput id="password" type="password" className={FIELD} value={data.password} onChange={(e) => setData('password', e.target.value)} required />
                        <InputError message={errors.password} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="password_confirmation" value="Confirmar contraseña" />
                        <TextInput id="password_confirmation" type="password" className={FIELD} value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} required />
                    </div>
                </>
            )}

            <div>
                <InputLabel htmlFor="role" value="Rol" />
                <select
                    id="role"
                    value={data.role}
                    onChange={(e) => setData('role', e.target.value)}
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary"
                    required
                >
                    <option value="">Seleccionar rol</option>
                    {roles.map((role) => (
                        <option key={role.id} value={role.name}>
                            {role.name}
                        </option>
                    ))}
                </select>
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

/** Usuarios tab: server-side search and pagination, plus create / edit / remove. */
export default function UsersPanel({ users, filters, assignableRoles }) {
    const { auth } = usePage().props;
    const canManageUsers = (auth?.user?.permissions || []).includes('manage-users');

    const create = useForm({ name: '', email: '', password: '', password_confirmation: '', role: '' });
    const edit = useForm({ name: '', email: '', role: '' });
    const [showCreate, setShowCreate] = useState(false);
    const [editingUser, setEditingUser] = useState(null);

    const [search, setSearch] = useState(filters.search);
    const [loading, setLoading] = useState(false);
    const firstRender = useRef(true);

    const visit = (params) =>
        router.get(route('users.index'), { tab: 'usuarios', ...params }, {
            only: ['users', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    // Debounced search; the server filters inside the active Workspace only.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return undefined;
        }

        const timer = setTimeout(() => visit({ search: search || undefined }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const submitCreate = (e) => {
        e.preventDefault();
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
        edit.setData({ name: user.name, email: user.email, role: user.roles[0] || '' });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        edit.put(route('users.update', editingUser.id), { onSuccess: () => setEditingUser(null) });
    };

    const remove = (user) => {
        if (window.confirm(`¿Quitar a ${user.name} de este Workspace?`)) {
            router.delete(route('users.destroy', user.id), { preserveScroll: true });
        }
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <SearchInput value={search} onChange={setSearch} placeholder="Buscar por nombre o correo" label="Buscar usuarios" className="w-full sm:w-80" />
                {canManageUsers && (
                    <PrimaryButton type="button" onClick={() => setShowCreate(true)} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo usuario
                    </PrimaryButton>
                )}
            </div>

            {users.data.length === 0 ? (
                <div className="p-6">
                    {filters.search ? (
                        <EmptyState icon={SearchX} title="Sin resultados" description={`Ningún usuario coincide con "${filters.search}".`} />
                    ) : (
                        <EmptyState icon={Users} title="No hay usuarios" description="Este Workspace todavía no tiene usuarios." />
                    )}
                </div>
            ) : (
                <TableWrap busy={loading}>
                    <thead>
                        <tr>
                            <Th>Nombre</Th>
                            <Th>Email</Th>
                            <Th>Rol</Th>
                            <Th>Creación</Th>
                            {canManageUsers && <Th className="text-right">Acciones</Th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {users.data.map((user) => (
                            <tr key={user.id} className="transition-colors duration-150 hover:bg-canvas/70">
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
                                <Td className="whitespace-nowrap text-ink-muted">{user.created_at}</Td>
                                {canManageUsers && (
                                    <Td className="text-right whitespace-nowrap">
                                        <button type="button" onClick={() => openEdit(user)} className="me-4 font-semibold text-primary hover:underline">
                                            Editar
                                        </button>
                                        <button type="button" onClick={() => remove(user)} className="font-semibold text-danger hover:underline">
                                            Quitar
                                        </button>
                                    </Td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <Pagination meta={users.meta} noun="usuarios" onPage={(page) => visit({ search: filters.search || undefined, page })} />

            <FormModal show={showCreate} title="Nuevo usuario" onClose={() => setShowCreate(false)} onSubmit={submitCreate} processing={create.processing} submitLabel={create.processing ? 'Guardando...' : 'Crear usuario'}>
                <UserFields data={create.data} setData={create.setData} errors={create.errors} roles={assignableRoles} withPassword />
            </FormModal>

            <FormModal show={Boolean(editingUser)} title="Editar usuario" onClose={() => setEditingUser(null)} onSubmit={submitEdit} processing={edit.processing} submitLabel={edit.processing ? 'Guardando...' : 'Guardar cambios'}>
                <UserFields data={edit.data} setData={edit.setData} errors={edit.errors} roles={assignableRoles} lockIdentity={Boolean(editingUser?.identityLocked)} />
            </FormModal>
        </Card>
    );
}
