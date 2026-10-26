import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Building2, Pencil, Power } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import { useToast } from '@/Components/Toast';
import WorkspaceCombobox from '@/Components/WorkspaceCombobox';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import ModuleChecklist from './Partials/ModuleChecklist';
import OfferBadge from './Partials/OfferBadge';
import PriceSummary from './Partials/PriceSummary';
import PlanFormModal from './Partials/PlanFormModal';

const sameIds = (a, b) => a.length === b.length && a.every((id) => b.includes(id));

/** The modules of the plan: a checklist by category with one explicit save, and a warning before taking modules away from Workspaces. */
function ModulesCard({ plan, catalog, canEdit }) {
    const confirm = useConfirm();
    const form = useForm({ module_ids: plan.moduleIds });
    const dirty = !sameIds(form.data.module_ids, plan.moduleIds);

    const submit = async (e) => {
        e.preventDefault();
        const removed = plan.moduleIds.filter((id) => !form.data.module_ids.includes(id));

        if (removed.length > 0 && plan.workspacesCount > 0) {
            const confirmed = await confirm({
                title: 'Quitar módulos del plan',
                description: `${plan.workspacesCount} Workspace(s) usan este plan y perderán el acceso a los módulos que quites. Sus datos se conservan y vuelven a estar disponibles si los incluyes de nuevo.`,
                confirmLabel: 'Quitar módulos',
            });

            if (!confirmed) return;
        }

        form.put(route('plans.modules.update', plan.id), { preserveScroll: true });
    };

    return (
        <Card className="p-4 sm:p-6">
            <form onSubmit={submit}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-bold text-ink">Módulos incluidos</h2>
                        <p className="mt-0.5 text-sm text-ink-muted">{form.data.module_ids.length} seleccionados. Los permisos de cada rol siguen decidiendo qué puede hacer cada persona.</p>
                    </div>
                    {canEdit && (
                        <div className="flex gap-3">
                            {dirty && (
                                <SecondaryButton type="button" onClick={() => form.setData('module_ids', plan.moduleIds)}>
                                    Descartar
                                </SecondaryButton>
                            )}
                            <PrimaryButton type="submit" disabled={!dirty || form.processing}>
                                Guardar módulos
                            </PrimaryButton>
                        </div>
                    )}
                </div>

                <Alert tone="info" className="mt-4">
                    Los módulos «Pendientes de implementación» pueden incluirse en el plan, pero no estarán disponibles para los Workspaces hasta que se desarrollen.
                </Alert>

                <div className="mt-5">
                    <ModuleChecklist catalog={catalog} selected={form.data.module_ids} onChange={(ids) => form.setData('module_ids', ids)} error={form.errors.module_ids} idPrefix="plan_module" />
                </div>
            </form>
        </Card>
    );
}

/** Workspaces that use the plan, with the control that assigns another Workspace to it. Assigning moves it away from its previous plan. */
function WorkspacesCard({ plan, workspaces, perPageOptions, canAssign }) {
    const toast = useToast();
    const confirm = useConfirm();
    const list = usePagedList({ section: 'workspaces', only: ['workspaces', 'plan'], meta: workspaces.meta, perPageOptions, routeName: 'plans.show', routeParams: plan.id });
    const [selected, setSelected] = useState(null);
    const [assigning, setAssigning] = useState(false);

    const assign = async () => {
        const confirmed = await confirm({
            title: 'Asignar plan',
            description: `«${selected.name}» pasará a usar «${plan.name}». Perderá los módulos de su plan anterior que este plan no incluya; no se borra ningún dato.`,
            confirmLabel: 'Asignar plan',
        });

        if (!confirmed) return;

        router.put(route('workspaces.plan.update', selected.id), { plan_id: plan.id }, {
            preserveScroll: true,
            onStart: () => setAssigning(true),
            onFinish: () => setAssigning(false),
            onSuccess: () => setSelected(null),
            onError: (errors) => toast.error(errors.workspace_id ?? errors.plan_id ?? 'No se pudo asignar el plan.'),
        });
    };

    return (
        <Card className="overflow-hidden">
            <div className="border-b border-line p-4 sm:px-6">
                <h2 className="text-base font-bold text-ink">Workspaces asociados</h2>
                <p className="mt-0.5 text-sm text-ink-muted">
                    {plan.isDefault ? 'Incluye los Workspaces sin plan asignado, que usan este plan por defecto. ' : ''}
                    El Workspace administrativo no tiene plan: siempre dispone de todos los módulos.
                </p>

                {canAssign && (
                    <div className="mt-4 flex flex-wrap items-end gap-3">
                        <div className="w-full sm:w-96">
                            <InputLabel htmlFor="plan_assign_workspace" value="Asignar un Workspace a este plan" />
                            <WorkspaceCombobox id="plan_assign_workspace" purpose="view" value={selected} onChange={setSelected} disabled={!plan.isActive} className="mt-1" />
                        </div>
                        <PrimaryButton type="button" onClick={assign} disabled={!selected || !plan.isActive || assigning}>
                            Asignar
                        </PrimaryButton>
                    </div>
                )}
                {canAssign && !plan.isActive && <InputError message="Activa el plan para poder asignarlo a Workspaces." className="mt-2" />}
            </div>

            {workspaces.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Building2} title="Sin Workspaces" description="Ningún Workspace usa este plan todavía." />
                </div>
            ) : (
                <TableWrap busy={list.loading}>
                    <thead>
                        <tr>
                            <Th>Workspace</Th>
                            <Th>Código</Th>
                            <Th>Organización</Th>
                            <Th>Estado</Th>
                            <Th>Asignación</Th>
                            <Th>Módulos</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {workspaces.data.map((workspace) => (
                            <tr key={workspace.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td className="font-semibold text-ink">{workspace.name}</Td>
                                <Td className="font-mono text-xs text-ink-muted">{workspace.code}</Td>
                                <Td className="text-ink-muted">{workspace.organization}</Td>
                                <Td className="whitespace-nowrap">
                                    <Badge tone={workspace.isActive ? 'green' : 'neutral'}>{workspace.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                </Td>
                                <Td className="whitespace-nowrap text-ink-muted">{workspace.explicit ? 'Asignado' : 'Por defecto'}</Td>
                                <Td className="whitespace-nowrap">
                                    <Link href={route('workspaces.modules.show', workspace.id)} className="text-sm font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                                        Ver y personalizar
                                    </Link>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <Pagination noun="Workspaces" {...list.paginationProps} />
        </Card>
    );
}

/** The commercial offer at a glance: how it is sold, under which solution and at which price per period, with the reasons when it is not shown to the public. */
function OfferCard({ plan }) {
    const offer = plan.offer;

    return (
        <Card className="p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-ink">Oferta comercial</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Lo que el registro público ofrece de este plan.</p>
                </div>
                <OfferBadge offer={offer} />
            </div>
            <dl className="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt className="text-xs text-ink-muted">{offer.solutionNames?.length > 1 ? 'Soluciones' : 'Solución'}</dt>
                    <dd className="text-sm font-semibold text-ink">{offer.solutionNames?.length ? offer.solutionNames.join(', ') : 'Sin solución'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-ink-muted">Moneda</dt>
                    <dd className="text-sm font-semibold text-ink">{offer.currency ?? 'Sin moneda'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-ink-muted">Precios</dt>
                    <dd><PriceSummary offer={offer} className="!mt-0.5" /></dd>
                </div>
            </dl>
            {offer.state === 'incomplete' && <Alert tone="warning" className="mt-4">No se muestra al público: {offer.issues.join(' ')}</Alert>}
        </Card>
    );
}

/** One plan: its data, its modules and the Workspaces that use it. */
export default function Show({ can, plan, catalog, commercial, workspaces, perPageOptions }) {
    const confirm = useConfirm();
    const toast = useToast();
    const edit = useForm({
        name: plan.name,
        description: plan.description ?? '',
        solution_ids: plan.offer?.solutionIds ?? [],
        price_monthly: plan.offer?.priceMonthly ?? '',
        price_yearly: plan.offer?.priceYearly ?? '',
        currency: plan.offer?.currency ?? null,
        is_public: Boolean(plan.offer?.isPublic),
        requires_quote: Boolean(plan.offer?.requiresQuote),
        limits: { users: plan.limits?.users ?? '', ai_executions: plan.limits?.ai_executions ?? '' },
    });
    const [editing, setEditing] = useState(false);

    const submitEdit = (e) => {
        e.preventDefault();
        edit.put(route('plans.update', plan.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const toggleStatus = async () => {
        const deactivating = plan.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `«${plan.name}» dejará de poder asignarse a Workspaces. Se conservan sus módulos y puede reactivarse.`
                : `«${plan.name}» volverá a poder asignarse a Workspaces.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        router.post(route(deactivating ? 'plans.deactivate' : 'plans.activate', plan.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.status ?? 'No se pudo cambiar el estado.'),
        });
    };

    return (
        <>
            <Head title={plan.name} />

            <Link href={route('plans.index')} className="inline-flex items-center gap-1.5 text-sm font-medium text-ink-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Planes
            </Link>

            <PageHeader title={plan.name} description={plan.description ?? 'Sin descripción.'}>
                <Badge tone={plan.isActive ? 'green' : 'neutral'}>{plan.isActive ? 'Activo' : 'Inactivo'}</Badge>
                {plan.isDefault && <Badge tone="blue">Por defecto</Badge>}
                <OfferBadge offer={plan.offer} />
                {can.editPlan && (
                    <SecondaryButton type="button" onClick={() => setEditing(true)} className="gap-2">
                        <Pencil className="size-4" aria-hidden="true" />
                        Editar
                    </SecondaryButton>
                )}
                {can.activatePlan && (
                    <SecondaryButton type="button" onClick={toggleStatus} className="gap-2">
                        <Power className="size-4" aria-hidden="true" />
                        {plan.isActive ? 'Desactivar' : 'Activar'}
                    </SecondaryButton>
                )}
            </PageHeader>

            <OfferCard plan={plan} />
            <ModulesCard key={plan.moduleIds.join(',')} plan={plan} catalog={catalog} canEdit={can.editPlan} />
            <WorkspacesCard plan={plan} workspaces={workspaces} perPageOptions={perPageOptions} canAssign={can.assignPlan} />

            <PlanFormModal show={editing} title="Editar plan" form={edit} commercial={commercial} status={plan.isActive} submitLabel="Guardar cambios" onClose={() => setEditing(false)} onSubmit={submitEdit} />
        </>
    );
}

Show.layout = (page) => <AppLayout>{page}</AppLayout>;
