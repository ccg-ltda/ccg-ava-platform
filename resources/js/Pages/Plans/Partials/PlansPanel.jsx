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
import { useToast } from '@/Components/Toast';
import OfferBadge from './OfferBadge';
import PriceSummary from './PriceSummary';
import PlanFormModal from './PlanFormModal';

/**
 * Planes tab: search, create, edit and activate/deactivate commercial plans. Search runs on the server (debounced). A plan
 * with Workspaces cannot be deactivated (the server refuses and says why); nothing is ever deleted.
 */
export default function PlansPanel({ plans, catalog, filters, can, commercial }) {
    const confirm = useConfirm();
    const toast = useToast();

    const [search, setSearch] = useState(filters.search);
    const [loading, setLoading] = useState(false);
    const first = useRef(true);

    const blankOffer = { solution_ids: [], price_monthly: '', price_yearly: '', currency: null, is_public: false, requires_quote: false, limits: { users: '', ai_executions: '' } };
    const create = useForm({ name: '', description: '', module_ids: [], ...blankOffer });
    const edit = useForm({ name: '', description: '', module_ids: [], ...blankOffer });
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
        // The editor opens with what the plan has saved: its data and its modules.
        edit.setData({
            name: plan.name,
            description: plan.description ?? '',
            module_ids: plan.moduleIds ?? [],
            solution_ids: plan.offer?.solutionIds ?? [],
            price_monthly: plan.offer?.priceMonthly ?? '',
            price_yearly: plan.offer?.priceYearly ?? '',
            currency: plan.offer?.currency ?? null,
            is_public: Boolean(plan.offer?.isPublic),
            requires_quote: Boolean(plan.offer?.requiresQuote),
            limits: { users: plan.limits?.users ?? '', ai_executions: plan.limits?.ai_executions ?? '' },
        });
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

    const newPlan = can.createPlan && (
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
                <div className={`grid gap-4 p-4 sm:p-6 md:grid-cols-2 2xl:grid-cols-3 ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                    {plans.map((plan) => (
                        <article key={plan.id} className={`flex flex-col rounded-xl border bg-card p-4 transition-shadow hover:shadow-md ${plan.isActive ? 'border-line' : 'border-line opacity-80'}`} aria-label={`Plan ${plan.name}`}>
                            <header className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link href={route('plans.show', plan.id)} className="block truncate text-base font-bold text-ink hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                                        {plan.name}
                                    </Link>
                                    <p className="mt-0.5 truncate text-xs text-ink-muted">{plan.offer?.solutionNames?.length ? plan.offer.solutionNames.join(', ') : 'Sin solución'}</p>
                                </div>
                                <Badge tone={plan.isActive ? 'green' : 'neutral'}>{plan.isActive ? 'Activo' : 'Inactivo'}</Badge>
                            </header>

                            <div className="mt-3 flex flex-wrap gap-1.5">
                                <OfferBadge offer={plan.offer} />
                                {plan.isDefault && <Badge tone="blue">Por defecto</Badge>}
                                {plan.unmet.length > 0 && <Badge tone="amber">Dependencias incompletas</Badge>}
                            </div>

                            <PriceSummary offer={plan.offer} />

                            {plan.offer?.state === 'incomplete' && <p className="mt-2 text-xs text-accent-amber">Registro público: {plan.offer.issues.join(' ')}</p>}
                            {plan.description && <p className="mt-2 line-clamp-2 text-xs text-ink-muted">{plan.description}</p>}

                            <dl className="mt-auto grid grid-cols-2 gap-3 border-t border-line pt-3 text-xs">
                                <div>
                                    <dt className="text-ink-muted">Módulos</dt>
                                    <dd className="text-sm font-semibold text-ink">{plan.modulesCount}</dd>
                                </div>
                                <div>
                                    <dt className="text-ink-muted">Workspaces</dt>
                                    <dd className="text-sm font-semibold text-ink">{plan.workspacesCount}</dd>
                                </div>
                            </dl>
                            <div className="mt-3 flex items-center justify-end gap-1">
                                <IconButton icon={Eye} label="Ver detalle" context={plan.name} onClick={() => router.visit(route('plans.show', plan.id))} />
                                {can.editPlan && <IconButton icon={Pencil} label="Editar" context={plan.name} onClick={() => openEdit(plan)} />}
                                {can.activatePlan && (
                                    <IconButton
                                        icon={Power}
                                        label={plan.isActive ? 'Desactivar' : 'Activar'}
                                        context={plan.name}
                                        tone={plan.isActive ? 'danger' : 'primary'}
                                        onClick={() => toggleStatus(plan)}
                                    />
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            )}

            <PlanFormModal show={creating} title="Nuevo plan" form={create} catalog={catalog} commercial={commercial} submitLabel="Crear plan" onClose={() => setCreating(false)} onSubmit={submitCreate} />
            <PlanFormModal show={Boolean(editing)} title="Editar plan" form={edit} catalog={catalog} commercial={commercial} status={editing?.isActive} submitLabel="Guardar cambios" onClose={() => setEditing(null)} onSubmit={submitEdit} />
        </Card>
    );
}
