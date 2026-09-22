import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BackButton from '@/Components/BackButton';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ users, roles }) {
    const { auth } = usePage().props;
    const canManageUsers = (auth?.user?.permissions || []).includes('manage-users');
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: '',
    });

    const {
        data: editData,
        setData: setEditData,
        put: editPut,
        processing: editProcessing,
        errors: editErrors,
    } = useForm({
        name: '',
        email: '',
        role: '',
    });

    const [showModal, setShowModal] = useState(false);
    const [editingUser, setEditingUser] = useState(null);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('users.store'), {
            onSuccess: () => {
                reset();
                setShowModal(false);
            },
        });
    };

    const openEdit = (user) => {
        setEditingUser(user);
        setEditData({
            name: user.name,
            email: user.email,
            role: user.roles[0] || '',
        });
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        editPut(route('users.update', editingUser.id), {
            onSuccess: () => setEditingUser(null),
        });
    };

    const handleDelete = (user) => {
        if (window.confirm(`¿Eliminar al usuario ${user.name}?`)) {
            router.delete(route('users.destroy', user.id));
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Usuarios" />
            <div className="mx-auto max-w-7xl">
                <div className="mb-8 flex items-center justify-between">
                    <div>
                        <h1 className="font-orbitron text-2xl sm:text-3xl font-extrabold text-white tracking-wider drop-shadow-[0_0_15px_rgba(0,240,255,0.5)]">
                            GESTIÓN DE USUARIOS
                        </h1>
                        <p className="mt-1 font-orbitron text-xs tracking-widest text-cyan-400/80 uppercase">
                            Administración y Control de Credenciales
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <BackButton href="/dashboard" />
                        {canManageUsers && (
                            <button
                                onClick={() => setShowModal(true)}
                                className="btn-cyber rounded-xl px-4 py-2.5 font-orbitron text-xs font-bold tracking-widest text-white uppercase cursor-pointer"
                            >
                                + Nuevo Usuario
                            </button>
                        )}
                    </div>
                </div>

                {/* Table Glassmorphism */}
                <div className="glass-panel overflow-hidden rounded-2xl relative">
                    <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-cyan-400"></div>
                    <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-cyan-400"></div>

                    <table className="min-w-full divide-y divide-cyan-500/20">
                        <thead className="bg-[#0a0f18]/90">
                            <tr>
                                <th className="px-6 py-4 text-left font-orbitron text-xs font-bold tracking-wider text-cyan-400 uppercase">
                                    Nombre
                                </th>
                                <th className="px-6 py-4 text-left font-orbitron text-xs font-bold tracking-wider text-cyan-400 uppercase">
                                    Email
                                </th>
                                <th className="px-6 py-4 text-left font-orbitron text-xs font-bold tracking-wider text-cyan-400 uppercase">
                                    Rol
                                </th>
                                <th className="px-6 py-4 text-left font-orbitron text-xs font-bold tracking-wider text-cyan-400 uppercase">
                                    Fecha Creación
                                </th>
                                {canManageUsers && (
                                    <th className="px-6 py-4 text-right font-orbitron text-xs font-bold tracking-wider text-cyan-400 uppercase">
                                        Acciones
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-cyan-500/10 bg-transparent">
                            {users.map((user) => (
                                <tr key={user.id} className="transition-colors hover:bg-cyan-500/5">
                                    <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-white">
                                        {user.name}
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-sm text-slate-300">
                                        {user.email}
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4">
                                        <span className="inline-flex rounded-full bg-cyan-500/10 border border-cyan-500/30 px-3 py-1 font-orbitron text-[10px] tracking-widest text-cyan-400 shadow-[0_0_8px_rgba(0,240,255,0.2)]">
                                            {user.roles[0] || 'SIN ROL'}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-sm text-slate-400 font-orbitron text-xs">
                                        {user.created_at}
                                    </td>
                                    <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-orbitron">
                                        {canManageUsers && (
                                            <>
                                                <button
                                                    onClick={() => openEdit(user)}
                                                    className="me-4 text-cyan-400 hover:text-cyan-300 hover:drop-shadow-[0_0_8px_rgba(0,240,255,0.8)] transition-all"
                                                >
                                                    EDITAR
                                                </button>
                                                <button
                                                    onClick={() => handleDelete(user)}
                                                    className="text-red-400 hover:text-red-300 hover:drop-shadow-[0_0_8px_rgba(239,68,68,0.8)] transition-all"
                                                >
                                                    ELIMINAR
                                                </button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Create Modal */}
                {showModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md">
                        <div className="glass-panel w-full max-w-md rounded-2xl p-8 relative shadow-2xl border border-cyan-500/40">
                            <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-cyan-400 rounded-tl-lg"></div>
                            <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-cyan-400 rounded-tr-lg"></div>

                            <div className="mb-6 flex items-center justify-between">
                                <h3 className="font-orbitron text-lg font-bold text-white tracking-wide">
                                    NUEVO USUARIO
                                </h3>
                                <button
                                    onClick={() => setShowModal(false)}
                                    className="rounded-lg p-1.5 text-slate-400 hover:bg-cyan-500/20 hover:text-cyan-300 transition-all"
                                >
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Nombre
                                    </label>
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm"
                                        required
                                    />
                                    {errors.name && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{errors.name}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Email
                                    </label>
                                    <input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm"
                                        required
                                    />
                                    {errors.email && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{errors.email}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Contraseña
                                    </label>
                                    <input
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm"
                                        required
                                    />
                                    {errors.password && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{errors.password}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Confirmar Contraseña
                                    </label>
                                    <input
                                        type="password"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm"
                                        required
                                    />
                                </div>

                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Rol
                                    </label>
                                    <select
                                        value={data.role}
                                        onChange={(e) => setData('role', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm bg-[#0f172a]"
                                        required
                                    >
                                        <option value="">Seleccionar rol</option>
                                        {roles.map((role) => (
                                            <option key={role.id} value={role.name}>
                                                {role.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.role && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{errors.role}</p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-3 pt-4">
                                    <button
                                        type="button"
                                        onClick={() => setShowModal(false)}
                                        className="rounded-xl border border-slate-700 bg-slate-800/50 px-4 py-2 font-orbitron text-xs text-slate-300 hover:bg-slate-800"
                                    >
                                        CANCELAR
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="btn-cyber rounded-xl px-5 py-2 font-orbitron text-xs font-bold tracking-wider text-white uppercase cursor-pointer disabled:opacity-50"
                                    >
                                        {processing ? 'GUARDANDO...' : 'CREAR USUARIO'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* Edit Modal */}
                {editingUser && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md">
                        <div className="glass-panel w-full max-w-md rounded-2xl p-8 relative shadow-2xl border border-cyan-500/40">
                            <div className="absolute top-0 left-0 w-3 h-3 border-t-2 border-l-2 border-cyan-400 rounded-tl-lg"></div>
                            <div className="absolute top-0 right-0 w-3 h-3 border-t-2 border-r-2 border-cyan-400 rounded-tr-lg"></div>

                            <div className="mb-6 flex items-center justify-between">
                                <h3 className="font-orbitron text-lg font-bold text-white tracking-wide">
                                    EDITAR USUARIO
                                </h3>
                                <button
                                    onClick={() => setEditingUser(null)}
                                    className="rounded-lg p-1.5 text-slate-400 hover:bg-cyan-500/20 hover:text-cyan-300 transition-all"
                                >
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <form onSubmit={handleEditSubmit} className="space-y-4">
                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Nombre
                                    </label>
                                    <input
                                        type="text"
                                        value={editData.name}
                                        onChange={(e) => setEditData('name', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm"
                                        required
                                    />
                                    {editErrors.name && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{editErrors.name}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Email
                                    </label>
                                    <input
                                        type="email"
                                        value={editData.email}
                                        onChange={(e) => setEditData('email', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm"
                                        required
                                    />
                                    {editErrors.email && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{editErrors.email}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block font-orbitron text-xs tracking-wider text-slate-300 mb-1">
                                        Rol
                                    </label>
                                    <select
                                        value={editData.role}
                                        onChange={(e) => setEditData('role', e.target.value)}
                                        className="sci-input w-full px-4 py-2.5 rounded-xl text-sm bg-[#0f172a]"
                                        required
                                    >
                                        <option value="">Seleccionar rol</option>
                                        {roles.map((role) => (
                                            <option key={role.id} value={role.name}>
                                                {role.name}
                                            </option>
                                        ))}
                                    </select>
                                    {editErrors.role && (
                                        <p className="mt-1 text-xs text-red-400 font-orbitron">{editErrors.role}</p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-3 pt-4">
                                    <button
                                        type="button"
                                        onClick={() => setEditingUser(null)}
                                        className="rounded-xl border border-slate-700 bg-slate-800/50 px-4 py-2 font-orbitron text-xs text-slate-300 hover:bg-slate-800"
                                    >
                                        CANCELAR
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={editProcessing}
                                        className="btn-cyber rounded-xl px-5 py-2 font-orbitron text-xs font-bold tracking-wider text-white uppercase cursor-pointer disabled:opacity-50"
                                    >
                                        {editProcessing ? 'GUARDANDO...' : 'GUARDAR CAMBIOS'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
