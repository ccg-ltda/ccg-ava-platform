import { Head, Link, router, useForm } from '@inertiajs/react';
import DateField from '@/Components/DateField';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import Checkbox from '@/Components/Checkbox';
import { useConfirm } from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/format';

const SUBSCRIPTION = { pending: 'Pendiente', trialing: 'Prueba gratuita', scheduled: 'Prueba programada', schedule_cancelled: 'Prueba programada cancelada', active: 'Activa', grace: 'En gracia', suspended: 'Suspendida', cancelled: 'Cancelada', expired: 'Vencida' };
const statusText = (subscription) => (['trialing', 'scheduled', 'schedule_cancelled'].includes(subscription.status) ? SUBSCRIPTION[subscription.status] : `${SUBSCRIPTION[subscription.status]} · ${PAYMENT[subscription.paymentStatus]}`);
const PAYMENT = { pending: 'Pago pendiente', confirmed: 'Pago confirmado', failed: 'Pago fallido' };

const dateOf = (iso) => (iso ? new Date(iso).toLocaleDateString('es-CO', { year: 'numeric', month: 'short', day: 'numeric' }) : null);

/**
 * The license of the Workspace in one place: who it is, which plan and solution it uses, the state of its subscription, what is effectively available and,
 * for who may assign plans, the control that changes the plan. Dates appear only when the subscription holds them: there is no trial or usage limit in the
 * model, so none is shown.
 */
function LicenseCard({ workspace, data, canEdit, plans }) {
    const confirm = useConfirm();
    const toast = useToast();
    const [next, setNext] = useState('');
    const [saving, setSaving] = useState(false);
    const subscription = data.subscription;
    const total = data.modules.length;
    const rows = [
        { label: 'Organización', value: workspace.organization },
        { label: 'Plan', value: data.plan ? `${data.plan.name}${data.plan.explicit ? '' : ' (por defecto)'}` : 'Sin plan' },
        { label: 'Solución', value: data.plan?.solution ?? 'Sin solución' },
        { label: 'Workspace', value: data.isActive ? 'Activo' : 'Inactivo' },
        { label: 'Módulos disponibles', value: `${data.effectiveCount} de ${total}` },
        { label: 'Activada', value: dateOf(subscription?.activatedAt) },
        { label: 'Gracia hasta', value: dateOf(subscription?.graceEndsAt) },
        { label: 'Termina', value: dateOf(subscription?.endsAt) },
    ].filter((row) => row.value);

    const change = async () => {
        const target = plans.find((plan) => String(plan.value) === String(next));
        const confirmed = await confirm({
            title: 'Cambiar el plan',
            description: `«${workspace.name}» pasará a usar «${target?.label}». Perderá los módulos del plan actual que el nuevo no incluya; las excepciones de este Workspace se conservan y no se borra ningún dato.`,
            confirmLabel: 'Cambiar plan',
        });

        if (!confirmed) return;

        router.put(route('workspaces.plan.update', workspace.id), { plan_id: next }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => setNext(''),
            onError: (errors) => toast.error(errors.plan_id ?? errors.workspace_id ?? 'No se pudo cambiar el plan.'),
        });
    };

    return (
        <Card className="p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-ink">Licencia del Workspace</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">{subscription ? 'Estado de su suscripción y del plan que usa.' : 'Sin suscripción registrada: se rige solo por su plan.'}</p>
                </div>
                {subscription && <Badge tone={subscription.status === 'active' ? 'green' : 'amber'}>{statusText(subscription)}</Badge>}
            </div>

            <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {rows.map((row) => (
                    <div key={row.label}>
                        <dt className="text-xs text-ink-muted">{row.label}</dt>
                        <dd className="text-sm font-semibold text-ink">{row.value}</dd>
                    </div>
                ))}
            </dl>

            {canEdit && plans.length > 0 && (
                <div className="mt-5 flex flex-wrap items-end gap-3 border-t border-line pt-4">
                    <div className="w-full sm:w-96">
                        <InputLabel htmlFor="ws_change_plan" value="Cambiar el plan de este Workspace" />
                        <Select id="ws_change_plan" className="mt-1" value={next} onChange={setNext} options={[{ value: '', label: 'Elige un plan' }, ...plans.filter((plan) => plan.value !== data.plan?.id)]} />
                    </div>
                    <PrimaryButton type="button" onClick={change} disabled={!next || saving}>
                        Cambiar plan
                    </PrimaryButton>
                </div>
            )}
        </Card>
    );
}

const SOURCE = { workspace: ['amber', 'Particular de este Workspace'], contract: ['violet', 'Congelado en la suscripción'], plan: ['blue', 'Heredado del plan'], none: ['neutral', 'Sin límite definido'], administrative: ['neutral', 'Sin límites'] };

/** One limit: the standard of the plan, what applies to THIS Workspace, the real usage, and (for who may assign plans) the control for a particular limit with its reason. */
function LimitRow({ workspace, metric, canEdit }) {
    const confirm = useConfirm();
    const overridden = metric.source === 'workspace';
    const form = useForm({ metric: metric.key, mode: overridden && metric.value === null ? 'unlimited' : 'set', value: overridden && metric.value !== null ? String(metric.value) : '', reason: '' });
    const [tone, label] = SOURCE[metric.source] ?? SOURCE.none;
    const over = metric.value !== null && metric.usage > metric.value;

    const save = (e) => {
        e.preventDefault();
        form.put(route('workspaces.limits.update', workspace.id), { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
    };

    const reset = async () => {
        const confirmed = await confirm({
            title: 'Restablecer al estándar',
            description: `«${workspace.name}» volverá a usar el límite de su plan${metric.standard === null ? ' (el plan no define ninguno)' : ` (${metric.standard})`}. Queda registrado en la auditoría.`,
            confirmLabel: 'Restablecer',
        });

        if (confirmed) {
            form.transform((data) => ({ ...data, mode: 'reset' }));
            form.put(route('workspaces.limits.update', workspace.id), { preserveScroll: true, onFinish: () => form.transform((data) => data) });
        }
    };

    return (
        <li className="rounded-xl border border-line bg-canvas/50 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-bold text-ink">{metric.label}</h3>
                    <p className="mt-0.5 max-w-2xl text-xs text-ink-muted">{metric.help}</p>
                </div>
                <Badge tone={tone}>{label}</Badge>
            </div>

            <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <dt className="text-xs text-ink-muted">{metric.frozen ? 'Congelado en la suscripción' : 'Estándar del plan'}</dt>
                    <dd className="text-sm font-semibold text-ink">{metric.standard ?? 'Sin límite definido'}</dd>
                    {metric.frozen && metric.planNow !== metric.standard && <dd className="mt-0.5 text-xs text-ink-muted">El plan hoy: {metric.planNow ?? 'sin límite definido'} (solo nuevas contrataciones)</dd>}
                </div>
                <div>
                    <dt className="text-xs text-ink-muted">Aplica a este Workspace</dt>
                    <dd className="text-sm font-semibold text-ink">{metric.source === 'administrative' ? 'Sin límites' : (metric.value ?? (metric.source === 'workspace' ? 'Sin límite (autorizado)' : 'Sin límite definido'))}</dd>
                </div>
                <div>
                    <dt className="text-xs text-ink-muted">Uso actual</dt>
                    <dd className={`text-sm font-semibold ${over ? 'text-danger' : 'text-ink'}`}>
                        {metric.usage}
                        {metric.value !== null ? ` de ${metric.value}` : ''}
                    </dd>
                </div>
            </dl>
            {over && <p className="mt-2 text-xs text-danger">Supera el máximo: no se pueden añadir más; los existentes no se tocan.</p>}
            {overridden && metric.override && (
                <p className="mt-2 text-xs text-ink-muted">
                    Motivo: «{metric.override.reason}»{metric.override.author ? ` · ${metric.override.author}` : ''}
                    {metric.override.at ? ` · ${dateOf(metric.override.at)}` : ''}
                </p>
            )}

            {canEdit && metric.source !== 'administrative' && (
                <form onSubmit={save} className="mt-4 grid gap-3 border-t border-line pt-4 md:grid-cols-[auto_8rem_1fr_auto] md:items-end">
                    <div>
                        <InputLabel htmlFor={`limit_mode_${metric.key}`} value="Para este Workspace" />
                        <Select id={`limit_mode_${metric.key}`} className="mt-1" value={form.data.mode} onChange={(value) => form.setData('mode', value)} options={[{ value: 'set', label: 'Un máximo particular' }, { value: 'unlimited', label: 'Sin límite' }]} />
                    </div>
                    <div>
                        <InputLabel htmlFor={`limit_value_${metric.key}`} value="Máximo" />
                        <TextInput id={`limit_value_${metric.key}`} className="mt-1" type="number" min={1} step={1} disabled={form.data.mode !== 'set'} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} invalid={Boolean(form.errors.value)} />
                        <InputError message={form.errors.value} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor={`limit_reason_${metric.key}`} value="Motivo (obligatorio)" />
                        <TextInput id={`limit_reason_${metric.key}`} className="mt-1" maxLength={500} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} invalid={Boolean(form.errors.reason)} />
                        <InputError message={form.errors.reason ?? form.errors.metric ?? form.errors.workspace_id} className="mt-1" />
                    </div>
                    <div className="flex gap-2">
                        <PrimaryButton type="submit" disabled={form.processing}>
                            Guardar excepción
                        </PrimaryButton>
                        {overridden && (
                            <SecondaryButton type="button" onClick={reset} disabled={form.processing}>
                                Restablecer
                            </SecondaryButton>
                        )}
                    </div>
                </form>
            )}
        </li>
    );
}

/** Limits of the Workspace: standard (plan) against particular, with the usage that is really counted. Usage that is only measured has no limit to edit. */
function LimitsCard({ workspace, limits, canEdit }) {
    return (
        <Card className="p-4 sm:p-6">
            <h2 className="text-base font-bold text-ink">Límites de uso</h2>
            <p className="mt-0.5 text-sm text-ink-muted">
                El plan fija el estándar y este Workspace lo hereda. Una excepción vale solo para este Workspace, no cambia el plan ni a otros clientes y deja su motivo y autor. Un límite no concede módulos ni permisos.
            </p>

            {!limits.ready ? (
                <Alert tone="warning" className="mt-4">
                    Los límites todavía no están disponibles en esta base de datos (falta aplicar la migración pendiente): no se aplica ninguno.
                </Alert>
            ) : (
                <ul className="mt-4 space-y-3">
                    {limits.metrics.map((metric) => (
                        <LimitRow key={`${metric.key}-${metric.source}-${metric.value}`} workspace={workspace} metric={metric} canEdit={canEdit} />
                    ))}
                </ul>
            )}

            <p className="mt-4 text-xs text-ink-muted">
                Las ejecuciones de IA se cuentan por mes calendario y se renuevan el {dateOf(limits.period.renews)}. Al llegar al máximo el asistente deja de responder y la conversación pasa a una persona; el historial y el resto del panel siguen funcionando.
            </p>
        </Card>
    );
}

const TERMS_SOURCE = { signup: 'Contratado en el registro', manual: 'Asignado por un administrador', trial: 'Prueba gratuita', frozen_current: 'Congeladas por un administrador', agreement: 'Acuerdo comercial registrado' };
const AGREEMENT_ORIGIN = { contract: 'Contrato firmado', proposal: 'Propuesta aceptada', communication: 'Correo o comunicación del cliente', other: 'Otro' };
const PERIOD_TEXT = { monthly: 'al mes', yearly: 'al año' };
const money = (cents, currency) => (cents === null || cents === undefined ? null : formatMoney(cents / 100, currency));

/** D-CON1: the price agreed outside the public sign-up, recorded on purpose over the frozen conditions (history kept, audited). */
function AgreementForm({ workspace, terms }) {
    const form = useForm({ amount: '', currency: terms.price?.currency ?? 'COP', period: terms.price?.period ?? 'monthly', origin: 'contract', reason: '' });
    const agreements = terms.agreements ?? [];

    const submit = (e) => {
        e.preventDefault();
        form.post(route('workspaces.terms.agreement', workspace.id), { preserveScroll: true, onSuccess: () => form.reset('amount', 'reason') });
    };

    return (
        <div className="mt-4 rounded-xl border border-line bg-canvas/50 p-4">
            <h3 className="text-sm font-bold text-ink">Registrar un precio acordado</h3>
            <p className="mt-0.5 text-xs text-ink-muted">Para un acuerdo hecho fuera del registro público. Reemplaza solo el precio; los módulos y límites no cambian y el acuerdo anterior queda en el historial.</p>
            <form onSubmit={submit} className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <InputLabel htmlFor="agr-amount" value="Importe" />
                    <TextInput id="agr-amount" type="number" min="0" step="0.01" className="mt-1 block w-full" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                    <InputError message={form.errors.amount} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="agr-currency" value="Moneda" />
                    <TextInput id="agr-currency" maxLength={3} className="mt-1 block w-full uppercase" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} required />
                    <InputError message={form.errors.currency} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="agr-period" value="Periodo de facturación" />
                    <Select id="agr-period" className="mt-1" value={form.data.period} onChange={(value) => form.setData('period', value)} options={[{ value: 'monthly', label: 'Mensual' }, { value: 'yearly', label: 'Anual' }]} />
                </div>
                <div>
                    <InputLabel htmlFor="agr-origin" value="Origen del acuerdo" />
                    <Select id="agr-origin" className="mt-1" value={form.data.origin} onChange={(value) => form.setData('origin', value)} options={Object.entries(AGREEMENT_ORIGIN).map(([value, label]) => ({ value, label }))} />
                </div>
                <div className="sm:col-span-2 lg:col-span-4">
                    <InputLabel htmlFor="agr-reason" value="Motivo (obligatorio)" />
                    <TextInput id="agr-reason" className="mt-1 block w-full" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                    <InputError message={form.errors.reason ?? form.errors.agreement} className="mt-1" />
                </div>
                <div className="sm:col-span-2 lg:col-span-4"><PrimaryButton disabled={form.processing}>Registrar acuerdo</PrimaryButton></div>
            </form>
            {agreements.length > 0 && (
                <ul className="mt-4 space-y-2 text-xs text-ink-muted">
                    {[...agreements].reverse().map((a, i) => (
                        <li key={i} className="rounded-lg border border-line px-3 py-2">
                            <span className="font-semibold text-ink">{money(a.price.amount_cents, a.price.currency)} {a.price.currency} {PERIOD_TEXT[a.price.period] ?? ''}</span> · {AGREEMENT_ORIGIN[a.origin] ?? a.origin} · {dateOf(a.agreed_at)} — {a.reason}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** What each condition was when the customer contracted (frozen), next to what the plan offers today: editing the plan never changes the first. */
function ContractCard({ workspace, data, canEdit }) {
    const confirm = useConfirm();
    const subscription = data.subscription;
    const terms = subscription?.terms;

    const freeze = async () => {
        const confirmed = await confirm({
            title: 'Congelar las condiciones actuales',
            description: `Se guardarán como las condiciones de «${workspace.name}» los módulos y límites que su plan tiene HOY (sin precio: no se puede recuperar el de un contrato histórico). Queda registrado en la auditoría con tu nombre.`,
            confirmLabel: 'Congelar',
        });

        if (confirmed) router.post(route('workspaces.terms.freeze', workspace.id), {}, { preserveScroll: true });
    };

    const now = data.plan?.offer;

    return (
        <Card className="p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-ink">Condiciones contratadas</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Lo que este Workspace aceptó al contratar. Cambiar el plan en el catálogo solo afecta a nuevas contrataciones; estas condiciones no se modifican solas.</p>
                </div>
                {subscription && terms && <Badge tone="violet">{TERMS_SOURCE[subscription.termsSource] ?? 'Congeladas'}</Badge>}
                {subscription && !terms && subscription.termsReady && <Badge tone="amber">Históricas incompletas</Badge>}
            </div>

            {!subscription && <p className="mt-4 text-sm text-ink-muted">Sin suscripción registrada: este Workspace se rige solo por su plan, sin condiciones congeladas.</p>}
            {subscription && !subscription.termsReady && (
                <Alert tone="warning" className="mt-4">Las condiciones congeladas todavía no están disponibles en esta base de datos (falta aplicar la migración pendiente): el Workspace sigue rigiéndose por su plan.</Alert>
            )}

            {subscription && subscription.termsReady && !terms && (
                <div className="mt-4 space-y-3">
                    <Alert tone="warning">
                        Las condiciones que aceptó esta suscripción nunca se registraron: no se inventan ni se le atribuyen las del catálogo. Mientras tanto sigue usando su plan «{data.plan?.name}» tal como está hoy. Pendiente de revisión.
                    </Alert>
                    {canEdit && (
                        <SecondaryButton type="button" onClick={freeze}>
                            Congelar las condiciones actuales del plan
                        </SecondaryButton>
                    )}
                </div>
            )}

            {terms && (
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <div className="rounded-xl border border-line bg-canvas/50 p-4">
                        <h3 className="text-sm font-bold text-ink">Congeladas en la suscripción</h3>
                        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt className="text-xs text-ink-muted">Plan</dt><dd className="font-semibold text-ink">{terms.plan?.name}</dd></div>
                            <div><dt className="text-xs text-ink-muted">Solución</dt><dd className="font-semibold text-ink">{terms.solution?.name ?? 'Sin solución'}</dd></div>
                            <div>
                                <dt className="text-xs text-ink-muted">Precio acordado</dt>
                                <dd className="font-semibold text-ink">{terms.price ? `${money(terms.price.amount_cents, terms.price.currency)} ${terms.price.currency} ${PERIOD_TEXT[terms.price.period] ?? ''}` : 'Sin precio acordado en línea'}</dd>
                            </div>
                            <div><dt className="text-xs text-ink-muted">Módulos</dt><dd className="font-semibold text-ink">{terms.modules.length}</dd></div>
                            {Object.entries(terms.limits ?? {}).map(([key, value]) => (
                                <div key={key}><dt className="text-xs text-ink-muted">{LIMIT_LABEL[key] ?? key}</dt><dd className="font-semibold text-ink">{value ?? 'Sin límite definido'}</dd></div>
                            ))}
                        </dl>
                        {subscription.termsCapturedAt && <p className="mt-3 text-xs text-ink-muted">Registradas el {dateOf(subscription.termsCapturedAt)}.</p>}
                    </div>
                    <div className="rounded-xl border border-line bg-canvas/50 p-4">
                        <h3 className="text-sm font-bold text-ink">Oferta actual del plan (solo para nuevas contrataciones)</h3>
                        {now ? (
                            <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div><dt className="text-xs text-ink-muted">Plan</dt><dd className="font-semibold text-ink">{data.plan.name}</dd></div>
                                <div><dt className="text-xs text-ink-muted">Mensual</dt><dd className="font-semibold text-ink">{now.priceMonthly ? `${formatMoney(now.priceMonthly, now.currency)} ${now.currency}` : 'No se vende'}</dd></div>
                                <div><dt className="text-xs text-ink-muted">Anual</dt><dd className="font-semibold text-ink">{now.priceYearly ? `${formatMoney(now.priceYearly, now.currency)} ${now.currency}` : 'No se vende'}</dd></div>
                            </dl>
                        ) : (
                            <p className="mt-3 text-sm text-ink-muted">Sin oferta comercial configurada para este plan.</p>
                        )}
                    </div>
                </div>
            )}
            {terms && canEdit && !['cancelled', 'expired'].includes(subscription.status) && <AgreementForm workspace={workspace} terms={terms} />}
        </Card>
    );
}

const LIMIT_LABEL = { users: 'Usuarios', ai_executions: 'Ejecuciones de IA al mes' };
const TRIAL_BLOCKING = ['trialing', 'scheduled', 'active', 'grace', 'suspended'];

/** A free trial is granted BY HAND to this one Workspace. When it ends the server restricts access (nothing is deleted) until a confirmed payment reopens it. */
function TrialCard({ workspace, data, canEdit, plans }) {
    const confirm = useConfirm();
    const subscription = data.subscription;
    const trial = subscription?.trial;
    const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
    const grant = useForm({ plan_id: '', starts_at: '', ends_at: '', reason: '' });
    const extend = useForm({ ends_at: '', reason: '' });
    const cancel = useForm({ reason: '' });
    const live = subscription && TRIAL_BLOCKING.includes(subscription.status);

    const cancelTrial = async (e) => {
        e.preventDefault();
        const confirmed = await confirm({
            title: 'Cancelar la prueba',
            description: trial?.scheduled
                ? `Se cancela la prueba programada de «${workspace.name}». Como todavía no empezó, el Workspace sigue exactamente igual. Queda registrado con tu nombre y el motivo.`
                : `«${workspace.name}» perderá el acceso a los módulos (la suscripción pasa a cancelada). No se borra ningún dato. Queda registrado con tu nombre y el motivo.`,
            confirmLabel: 'Cancelar prueba',
        });

        if (confirmed) cancel.post(route('workspaces.trial.cancel', workspace.id), { preserveScroll: true, onSuccess: () => cancel.reset() });
    };

    return (
        <Card className="p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-ink">Prueba gratuita</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Se concede a mano y solo a este Workspace (<strong className="text-ink">{workspace.name}</strong>). No cambia el catálogo ni a otros clientes, no se activa sola en ninguna contratación y al terminar restringe el acceso sin borrar datos.</p>
                </div>
                {trial && <Badge tone={trial.expired ? 'amber' : trial.active ? 'green' : trial.scheduled ? 'violet' : 'neutral'}>{trial.expired ? 'Prueba vencida' : trial.active ? 'Prueba en curso' : trial.scheduled ? 'Prueba programada' : 'Prueba cancelada'}</Badge>}
            </div>

            {subscription && !subscription.termsReady && <Alert tone="warning" className="mt-4">Las pruebas gratuitas todavía no están disponibles en esta base de datos (falta aplicar la migración pendiente).</Alert>}

            {trial && (
                <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt className="text-xs text-ink-muted">Inicio</dt><dd className="text-sm font-semibold text-ink">{dateOf(trial.startsAt)}</dd></div>
                    <div><dt className="text-xs text-ink-muted">Fin</dt><dd className="text-sm font-semibold text-ink">{dateOf(trial.endsAt)}</dd></div>
                    <div><dt className="text-xs text-ink-muted">Concedida por</dt><dd className="text-sm font-semibold text-ink">{trial.by ?? '—'}</dd></div>
                    <div><dt className="text-xs text-ink-muted">Motivo</dt><dd className="text-sm font-semibold text-ink">{trial.reason}</dd></div>
                </dl>
            )}

            {trial?.expired && (
                <Alert tone="warning" className="mt-4">
                    La prueba terminó: el acceso a los módulos y las nuevas ejecuciones de IA están restringidos hasta que se confirme un pago. Los usuarios, clientes, conversaciones y archivos de este Workspace se conservan intactos y vuelven a estar disponibles al reactivar la suscripción. Si hace falta más tiempo, concede una nueva prueba con su motivo.
                </Alert>
            )}

            {trial?.scheduled && (
                <Alert tone="info" className="mt-4">
                    La prueba empieza el {dateOf(trial.startsAt)}. Hasta entonces no concede ningún acceso y el Workspace conserva lo que ya tiene; el plan se asigna cuando comienza.
                </Alert>
            )}

            {(trial?.active || trial?.scheduled) && canEdit && (
                <div className="mt-5 grid gap-5 border-t border-line pt-4 lg:grid-cols-2">
                    <form onSubmit={(e) => { e.preventDefault(); extend.put(route('workspaces.trial.update', workspace.id), { preserveScroll: true, onSuccess: () => extend.reset() }); }} className="space-y-3">
                        <h3 className="text-sm font-bold text-ink">Cambiar la fecha de fin</h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="trial_ext_date" value="Nueva fecha de fin" />
                                <TextInput id="trial_ext_date" className="mt-1" type="date" min={tomorrow} value={extend.data.ends_at} onChange={(e) => extend.setData('ends_at', e.target.value)} invalid={Boolean(extend.errors.ends_at)} />
                                <InputError message={extend.errors.ends_at ?? extend.errors.trial} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="trial_ext_reason" value="Motivo (obligatorio)" />
                                <TextInput id="trial_ext_reason" className="mt-1" maxLength={500} value={extend.data.reason} onChange={(e) => extend.setData('reason', e.target.value)} invalid={Boolean(extend.errors.reason)} />
                                <InputError message={extend.errors.reason} className="mt-1" />
                            </div>
                        </div>
                        <PrimaryButton type="submit" disabled={extend.processing || !extend.data.ends_at}>Guardar fecha</PrimaryButton>
                    </form>
                    <form onSubmit={cancelTrial} className="space-y-3">
                        <h3 className="text-sm font-bold text-ink">Cancelar la prueba</h3>
                        <div>
                            <InputLabel htmlFor="trial_cancel_reason" value="Motivo (obligatorio)" />
                            <TextInput id="trial_cancel_reason" className="mt-1" maxLength={500} value={cancel.data.reason} onChange={(e) => cancel.setData('reason', e.target.value)} invalid={Boolean(cancel.errors.reason)} />
                            <InputError message={cancel.errors.reason ?? cancel.errors.trial} className="mt-1" />
                        </div>
                        <SecondaryButton type="submit" disabled={cancel.processing}>Cancelar prueba</SecondaryButton>
                    </form>
                </div>
            )}

            {canEdit && !live && (subscription ? subscription.termsReady : true) && (
                <form
                    onSubmit={(e) => { e.preventDefault(); grant.post(route('workspaces.trial.store', workspace.id), { preserveScroll: true, onSuccess: () => grant.reset() }); }}
                    className="mt-5 grid gap-3 border-t border-line pt-4 md:grid-cols-[1fr_auto_auto_2fr_auto] md:items-end"
                >
                    <div>
                        <InputLabel htmlFor="trial_plan" value="Plan de la prueba" />
                        <Select id="trial_plan" className="mt-1" value={grant.data.plan_id} onChange={(value) => grant.setData('plan_id', value)} options={[{ value: '', label: 'Elige un plan' }, ...plans]} />
                        <InputError message={grant.errors.plan_id} className="mt-1" />
                    </div>
                    <DateField id="trial_start" label="Empieza el (opcional)" value={grant.data.starts_at} onChange={(value) => grant.setData('starts_at', value)} error={grant.errors.starts_at} />
                    <DateField id="trial_end" label="Termina el" min={tomorrow} value={grant.data.ends_at} onChange={(value) => grant.setData('ends_at', value)} error={grant.errors.ends_at} />
                    <div>
                        <InputLabel htmlFor="trial_reason" value="Motivo (obligatorio)" />
                        <TextInput id="trial_reason" className="mt-1" maxLength={500} value={grant.data.reason} onChange={(e) => grant.setData('reason', e.target.value)} invalid={Boolean(grant.errors.reason)} />
                        <InputError message={grant.errors.reason ?? grant.errors.trial ?? grant.errors.workspace_id} className="mt-1" />
                    </div>
                    <PrimaryButton type="submit" disabled={grant.processing || !grant.data.plan_id || !grant.data.ends_at}>{grant.data.starts_at && grant.data.starts_at > tomorrow.slice(0, 10) ? 'Programar prueba' : 'Conceder prueba'}</PrimaryButton>
                    <p className="text-xs text-ink-muted md:col-span-4">Empieza ahora. Aplica el plan elegido con sus módulos y límites de hoy, congelados para esta prueba, y solo a este Workspace.</p>
                </form>
            )}
            {live && !trial?.active && <p className="mt-4 text-sm text-ink-muted">Este Workspace ya tiene una suscripción vigente: no se le concede una prueba.</p>}
        </Card>
    );
}

/** The subscription and limit events of this Workspace (who, what, when), from the same audit the administration already uses. */
function HistoryCard({ history }) {
    return (
        <Card className="p-4 sm:p-6">
            <h2 className="text-base font-bold text-ink">Historial de cambios</h2>
            <p className="mt-0.5 text-sm text-ink-muted">Suscripción, límites, módulos y bloqueos de este Workspace, los más recientes primero.</p>
            {history.length === 0 ? (
                <p className="mt-4 text-sm text-ink-muted">Todavía no hay cambios registrados.</p>
            ) : (
                <ul className="mt-4 divide-y divide-line rounded-xl border border-line">
                    {history.map((entry, index) => (
                        <li key={index} className="flex flex-wrap items-start justify-between gap-2 p-3 sm:px-4">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-ink">{entry.text}</p>
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    {(entry.changes ?? []).map((change) => {
                                        const word = (value) => (change.field === 'Estado de la suscripción' ? (SUBSCRIPTION[value] ?? value) : value);

                                        return `${change.field}: ${word(change.before) ?? '—'} → ${word(change.after) ?? '—'}`;
                                    }).join(' · ')}
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2 text-xs text-ink-muted">
                                {entry.outcome === 'failed' && <Badge tone="red">Rechazado</Badge>}
                                <span>{entry.who} · {dateOf(entry.at)}</span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** Where the module comes from for this Workspace: its plan, or an exception authorized only for it. */
const originOf = (module, override) => {
    if (override === 'grant') return 'Concedido solo a este Workspace';
    if (override === 'revoke') return 'Retirado solo de este Workspace';
    return module.inPlan ? 'Del plan' : null;
};

/** The modules of one client Workspace: what the plan gives, the exceptions CCG authorized for it and what is available now. */
export default function WorkspaceModules({ workspace, data, canEdit, plans = [], limits, history = [] }) {
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
            <Head title={`Suscripción y límites de ${workspace.name}`} />

            <Link href={route('plans.index')} className="inline-flex items-center gap-1.5 text-sm font-medium text-ink-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Planes
            </Link>

            <PageHeader title={`Suscripción y límites de ${workspace.name}`} description={`${workspace.code} · ${workspace.organization}`}>
                {data.plan && <Badge tone="blue">Plan: {data.plan.name}</Badge>}
                {data.subscription && (
                    <Badge tone={data.subscription.status === 'active' ? 'green' : 'amber'}>
                        {statusText(data.subscription)}
                    </Badge>
                )}
            </PageHeader>

            <LicenseCard workspace={workspace} data={data} canEdit={canEdit} plans={plans} />
            <ContractCard workspace={workspace} data={data} canEdit={canEdit} />
            <TrialCard workspace={workspace} data={data} canEdit={canEdit} plans={plans} />
            <LimitsCard workspace={workspace} limits={limits} canEdit={canEdit} />
            <HistoryCard history={history} />

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
