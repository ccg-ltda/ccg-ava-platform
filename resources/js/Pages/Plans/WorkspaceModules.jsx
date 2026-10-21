import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AppLayout from '@/Layouts/AppLayout';

const SUBSCRIPTION = { pending: 'Pendiente', active: 'Activa', grace: 'En gracia', suspended: 'Suspendida', cancelled: 'Cancelada', expired: 'Vencida' };
const PAYMENT = { pending: 'Pago pendiente', confirmed: 'Pago confirmado', failed: 'Pago fallido' };

/** Where the module comes from for this Workspace: its plan, or an exception authorized only for it. */
const originOf = (module, override) => {
    if (override === 'grant') return 'Concedido solo a este Workspace';
    if (override === 'revoke') return 'Retirado solo de este Workspace';
    return module.inPlan ? 'Del plan' : null;
};

/** The modules of one client Workspace: what the plan gives, the exceptions CCG authorized for it and what is available now. */
export default function WorkspaceModules({ workspace, data, canEdit }) {
    const initial = Object.fromEntries(data.modules.filter((module) => module.override).map((module) => [module.id, module.override]));
    const form = useForm({ overrides: initial, reason: '' });
    const overrides = form.data.overrides;

    // ON = in the plan and not revoked, or granted. Toggling creates or clears the exception; the plan is never changed from here.
    const isOn = (module) => overrides[module.id] === 'grant' || (module.inPlan && overrides[module.id] !== 'revoke');
    const toggle = (module) => {
        const next = { ...overrides };
        const turningOn = !isOn(module);

        if (module.inPlan) {
            if (turningOn) delete next[module.id];
            else next[module.id] = 'revoke';
        } else if (turningOn) {
            next[module.id] = 'grant';
        } else {
            delete next[module.id];
        }

        form.setData('overrides', next);
    };

    const dirty = JSON.stringify(overrides) !== JSON.stringify(initial);

    const submit = (e) => {
        e.preventDefault();
        const ids = (mode) => Object.entries(overrides).filter(([, value]) => value === mode).map(([id]) => Number(id));
        form.transform((current) => ({ granted: ids('grant'), revoked: ids('revoke'), reason: current.reason }));
        form.put(route('workspaces.modules.update', workspace.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Módulos de ${workspace.name}`} />

            <Link href={route('plans.index')} className="inline-flex items-center gap-1.5 text-sm font-medium text-ink-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Planes
            </Link>

            <PageHeader title={`Módulos de ${workspace.name}`} description={`${workspace.code} · ${workspace.organization}`}>
                {data.plan && <Badge tone="blue">Plan: {data.plan.name}</Badge>}
                {data.subscription && (
                    <Badge tone={data.subscription.status === 'active' ? 'green' : 'amber'}>
                        {SUBSCRIPTION[data.subscription.status]} · {PAYMENT[data.subscription.paymentStatus]}
                    </Badge>
                )}
            </PageHeader>

            {data.blocked === 'subscription' && <Alert tone="warning">La suscripción de este Workspace no concede acceso: no puede abrir ningún módulo, aunque el plan los incluya. Sus datos se conservan.</Alert>}
            {data.blocked === 'plan' && <Alert tone="warning">El plan de este Workspace no existe o está desactivado: no tiene módulos.</Alert>}

            <Card className="p-4 sm:p-6">
                <form onSubmit={submit}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-base font-bold text-ink">Módulos habilitados</h2>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                Una excepción afecta solo a este Workspace: no cambia el plan ni a otros clientes. Los permisos de cada rol siguen decidiendo qué hace cada persona.
                            </p>
                        </div>
                        {canEdit && (
                            <div className="flex gap-3">
                                {dirty && (
                                    <SecondaryButton type="button" onClick={() => form.setData('overrides', initial)}>
                                        Descartar
                                    </SecondaryButton>
                                )}
                                <PrimaryButton type="submit" disabled={!dirty || form.processing}>
                                    Guardar excepciones
                                </PrimaryButton>
                            </div>
                        )}
                    </div>

                    <ul className="mt-5 divide-y divide-line rounded-xl border border-line">
                        {data.modules.map((module) => {
                            const on = isOn(module);
                            const origin = originOf(module, overrides[module.id]);
                            const unusable = !module.implemented || !module.isActive;

                            return (
                                <li key={module.id} className="flex flex-wrap items-center gap-3 p-3 sm:px-4">
                                    <Checkbox id={`ws_module_${module.id}`} checked={on} disabled={!canEdit || (unusable && !on)} onChange={() => toggle(module)} />
                                    <label htmlFor={`ws_module_${module.id}`} className="min-w-0 flex-1">
                                        <span className="block text-sm font-semibold text-ink">{module.name}</span>
                                        <span className="block text-xs text-ink-muted">
                                            {module.category}
                                            {module.requires.length > 0 ? ` · necesita ${module.requires.join(', ')}` : ''}
                                        </span>
                                    </label>
                                    {origin && <Badge tone={overrides[module.id] ? 'amber' : 'neutral'}>{origin}</Badge>}
                                    {unusable && <Badge tone="neutral">{module.implemented ? 'Desactivado' : 'Pendiente de implementación'}</Badge>}
                                    {!dirty && module.effective && <Badge tone="green">Disponible</Badge>}
                                    {!dirty && !module.effective && on && !unusable && <Badge tone="amber">No operativo (dependencias o suscripción)</Badge>}
                                </li>
                            );
                        })}
                    </ul>

                    {canEdit && dirty && (
                        <div className="mt-4 max-w-xl">
                            <InputLabel htmlFor="ws_modules_reason" value="Motivo (opcional)" />
                            <TextInput id="ws_modules_reason" className="mt-1 w-full" value={form.data.reason} maxLength={500} onChange={(e) => form.setData('reason', e.target.value)} />
                            <InputError message={form.errors.reason ?? form.errors.granted ?? form.errors.revoked} className="mt-2" />
                        </div>
                    )}
                </form>
            </Card>
        </>
    );
}

WorkspaceModules.layout = (page) => <AppLayout>{page}</AppLayout>;
