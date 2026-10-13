import { Link, router, useForm } from '@inertiajs/react';
import { Eye, Layers, Pencil, Plus, Power } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import { TableWrap, Td, Th } from '@/Components/Table';
import { useToast } from '@/Components/Toast';
import PlanFormModal from './PlanFormModal';

/**
 * Planes tab: search, create, edit and activate/deactivate commercial plans. Search runs on the server (debounced). A plan
 * with Workspaces cannot be deactivated (the server refuses and says why); nothing is ever deleted.
 */
export default function PlansPanel({ plans, catalog, filters }) {
    const confirm = useConfirm();
    const toast = useToast();

    const [search, setSearch] = useState(filters.search);
    const [loading, setLoading] = useState(false);
    const first = useRef(true);

    const create = useForm({ name: '', description: '', module_ids: [] });
    const edit = useForm({ name: '', description: '' });
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState(null);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return undefined;
        }

        const timer = setTimeout(() => {
            router.get(route('plans.index'), { tab: 'planes', search: search || undefined }, {
                only: ['plans', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        }, 300);

        return () => clearTimeout(timer);
    }, [search]);

    const submitCreate = (e) => {
        e.preventDefault();
        create.post(route('plans.store'), { onSuccess: () => create.reset() });
    };

    const openEdit = (plan) => {
        setEditing(plan);
        edit.clearErrors();
        edit.setData({ name: plan.name, description: plan.description ?? '' });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        edit.put(route('plans.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
    };

    const toggleStatus = async (plan) => {
        const deactivating = plan.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `«${plan.name}» dejará de poder asignarse a Workspaces. Se conservan sus módulos y su historial, y puede reactivarse.`
                : `«${plan.name}» volverá a poder asignarse a Workspaces.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        router.post(route(deactivating ? 'plans.deactivate' : 'plans.activate', plan.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.status ?? 'No se pudo cambiar el estado.'),
        });
    };

    const newPlan = (
        <PrimaryButton type="button" onClick={() => setCreating(true)} className="gap-2">
            <Plus className="size-4" aria-hidden="true" />
            Nuevo plan
        </PrimaryButton>
    );

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <SearchInput className="w-full sm:w-72" value={search} onChange={setSearch} placeholder="Buscar plan" label="Buscar plan" />
                {newPlan}
            </div>

            {plans.length === 0 ? (
                <div className="p-6">
                    <EmptyState
                        icon={Layers}
                        title={filters.search ? 'Ningún plan coincide' : 'Todavía no hay planes'}
                        description={filters.search ? 'Prueba con otro nombre.' : 'Crea el primero y elige los módulos que incluye.'}
                    >
                        {!filters.search && newPlan}
                    </EmptyState>
                </div>
            ) : (
                <TableWrap busy={loading}>
                    <thead>
                        <tr>
                            <Th>Plan</Th>
                            <Th>Estado</Th>
                            <Th>Módulos</Th>
                            <Th>Workspaces</Th>
                            <Th className="text-center">Acciones</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {plans.map((plan) => (
                            <tr key={plan.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td>
                                    <Link href={route('plans.show', plan.id)} className="font-semibold text-ink hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                                        {plan.name}
                                    </Link>
                                    {plan.isDefault && <Badge tone="blue" className="ml-2">Por defecto</Badge>}
                                    {plan.unmet.length > 0 && <Badge tone="amber" className="ml-2">Dependencias incompletas</Badge>}
                                    {plan.description && <p className="mt-0.5 max-w-md truncate text-xs text-ink-muted">{plan.description}</p>}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    <Badge tone={plan.isActive ? 'green' : 'neutral'}>{plan.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                </Td>
                                <Td className="text-ink-muted">{plan.modulesCount}</Td>
                                <Td className="text-ink-muted">{plan.workspacesCount}</Td>
                                <Td>
                                    <div className="flex items-center justify-center gap-1">
                                        <IconButton icon={Eye} label="Ver detalle" context={plan.name} onClick={() => router.visit(route('plans.show', plan.id))} />
                                        <IconButton icon={Pencil} label="Editar" context={plan.name} onClick={() => openEdit(plan)} />
                                        <IconButton
                                            icon={Power}
                                            label={plan.isActive ? 'Desactivar' : 'Activar'}
                                            context={plan.name}
                                            tone={plan.isActive ? 'danger' : 'primary'}
                                            onClick={() => toggleStatus(plan)}
                                        />
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <PlanFormModal show={creating} title="Nuevo plan" form={create} catalog={catalog} submitLabel="Crear plan" onClose={() => setCreating(false)} onSubmit={submitCreate} />
            <PlanFormModal show={Boolean(editing)} title="Editar plan" form={edit} submitLabel="Guardar cambios" onClose={() => setEditing(null)} onSubmit={submitEdit} />
        </Card>
    );
}
